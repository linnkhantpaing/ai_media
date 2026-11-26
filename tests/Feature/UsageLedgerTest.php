<?php

namespace Tests\Feature;

use App\Exceptions\MediaFailure;
use App\Jobs\ProcessMediaRun;
use App\Models\CustomerPackageAssignment;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\UsageEvent;
use App\Models\UsageRecord;
use App\Models\User;
use App\Services\Media\MediaRunActions;
use App\Services\Media\MediaRuntime;
use App\Services\Media\ProviderSettings;
use App\Services\UsageLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UsageLedgerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private UsageLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->ledger = app(UsageLedger::class);
        Storage::fake('local');
        Queue::fake();
    }

    private function customer(array $overrides = []): array
    {
        $user = User::factory()->create(['managed_account' => true]);
        $package = Package::factory()->create(['status' => 'active', 'published_version' => 1]);
        $def = array_replace($package->definition, $overrides);
        $version = PackageVersion::create(['package_id' => $package->id, 'number' => 1, 'definition' => $def, 'actor_id' => $this->admin->id]);
        $a = CustomerPackageAssignment::create(['user_id' => $user->id, 'package_id' => $package->id, 'package_version_id' => $version->id, 'assigned_by' => $this->admin->id, 'source' => 'account_creation',
            'snapshot' => ['id' => $package->id, 'name' => $package->name, 'code' => $package->code, 'type' => 'paid', 'version' => 1, 'definition' => $def],
            'starts_at' => now()->subDay()->startOfDay(), 'ends_at' => now()->addDays(29)->startOfDay(), 'allowance_starts_at' => now()->subDay()->startOfDay()]);

        return [$user, $a];
    }

    private function makeRun(User $u, int $seconds = 60): MediaRun
    {
        $project = MediaProject::factory()->create(['user_id' => $u->id, 'metadata' => ['duration' => $seconds, 'audio' => true]]);
        $run = MediaRun::factory()->create(['media_project_id' => $project->id]);
        $project->update(['active_run_id' => $run->id]);

        return $run;
    }

    private function correction(UsageRecord $r, array $overrides = []): array
    {
        return array_replace(['revision' => $r->revision, 'seconds' => 30, 'videos' => 0, 'reason' => 'Correct a duplicate processing charge', 'request_id' => (string) Str::uuid()], $overrides);
    }

    public function test_admin_only_listing_detail_export_and_mutation(): void
    {
        $r = UsageRecord::factory()->create();
        $this->get('/admin/usage-ledger')->assertRedirect('/admin/login');
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/admin/usage-ledger')->assertUnauthorized();
        $this->actingAs($this->admin, 'admin')->getJson('/admin/usage-ledger')->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('items.0.id', $r->user_id);
        $this->getJson('/admin/usage-ledger/'.$r->id)->assertOk()->assertJsonPath('charged_seconds', 60);
        $this->postJson('/admin/usage-ledger/'.$r->id.'/adjust', [])->assertUnprocessable()->assertJsonValidationErrors(['revision', 'seconds', 'videos', 'reason', 'request_id']);
        $this->get('/admin/usage-ledger/export')->assertOk()->assertDownload();
    }

    public function test_reserve_and_settle_are_idempotent_and_rerender_counts_project_once(): void
    {
        [$u,$a] = $this->customer();
        $run = $this->makeRun($u);
        $record = $this->ledger->reserve($run);
        $this->assertSame($record->id, $this->ledger->reserve($run)->id);
        $this->assertSame(60, $this->ledger->balances($u->id, $a->allowance_starts_at)['reserved_seconds']);
        $this->ledger->settle($run);
        $this->ledger->settle($run);
        $this->ledger->release($run);
        $record->refresh();
        $this->assertSame(60, $record->charged_seconds);
        $this->assertSame(1, $record->charged_videos);
        $this->assertSame(2, $record->events()->count());
        $another = MediaRun::factory()->create(['media_project_id' => $run->media_project_id, 'phase' => 'generate']);
        $r = $this->ledger->reserve($another);
        $this->assertSame(0, $r->reserved_videos);
        $this->ledger->settle($another);
        $totals = $this->ledger->balances($u->id, $a->allowance_starts_at);
        $this->assertSame(120, $totals['seconds']);
        $this->assertSame(1, $totals['videos']);
    }

    public function test_failed_cancelled_or_review_work_releases_without_charging_and_retry_reserves_same_record(): void
    {
        [$u,$a] = $this->customer();
        $run = $this->makeRun($u);
        $record = $this->ledger->reserve($run);
        $this->ledger->release($run);
        $this->ledger->release($run);
        $this->assertSame('released', $record->fresh()->status);
        $this->assertSame(0, $record->fresh()->charged_seconds);
        $again = $this->ledger->reserve($run);
        $this->assertSame($record->id, $again->id);
        $this->assertSame(60, $again->reserved_seconds);
        $this->ledger->settle($run);
        $this->assertSame(60, $this->ledger->balances($u->id, $a->allowance_starts_at)['seconds']);
    }

    public function test_processing_and_video_allowance_include_pending_reservations(): void
    {
        [$u] = $this->customer(['included_seconds' => 90, 'video_limit' => 1]);
        $run = $this->makeRun($u, 60);
        $this->ledger->reserve($run);
        try {
            $this->ledger->reserve($this->makeRun($u, 60));
            $this->fail('Expected insufficient processing allowance.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('processing allowance', $e->getMessage());
        }
        $this->ledger->release($run);
        $first = $this->makeRun($u, 10);
        $this->ledger->reserve($first);
        $this->ledger->settle($first);
        try {
            $this->ledger->reserve($this->makeRun($u, 10));
            $this->fail('Expected exhausted videos.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('video allowance', $e->getMessage());
        }
    }

    public function test_expired_suspended_and_unsupported_features_cannot_reserve(): void
    {
        [$u,$a] = $this->customer();
        $a->update(['ends_at' => now()->subMinute()]);
        try {
            $this->ledger->reserve($this->makeRun($u));
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertStringContainsString('active subscription', $e->getMessage());
        }
        $a->update(['ends_at' => now()->addDay()]);
        $u->forceFill(['account_status' => 'suspended'])->save();
        try {
            $this->ledger->reserve($this->makeRun($u));
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertStringContainsString('account', $e->getMessage());
        }
        $u->forceFill(['account_status' => 'active'])->save();
        $version = PackageVersion::findOrFail($a->package_version_id);
        $version->update(['definition' => array_replace_recursive($version->definition, ['features' => ['subs' => false]])]);
        $this->expectException(ValidationException::class);
        $this->ledger->reserve($this->makeRun($u));
    }

    public function test_concurrency_claim_serializes_workers_and_queue_limit_is_enforced(): void
    {
        [$u] = $this->customer(['concurrency' => 1, 'queue_limit' => 1]);
        $one = $this->makeRun($u);
        $two = $this->makeRun($u);
        $this->ledger->reserve($one);
        $this->ledger->reserve($two);
        $this->assertTrue($this->ledger->claim($one));
        $this->assertFalse($this->ledger->claim($two));
        try {
            $this->ledger->reserve($this->makeRun($u));
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertStringContainsString('queue limit', $e->getMessage());
        }
        $one->update(['status' => 'completed']);
        $this->assertTrue($this->ledger->claim($two));
    }

    public function test_original_period_is_kept_after_change_but_renewal_uses_fresh_allowance(): void
    {
        [$u,$a] = $this->customer();
        $run = $this->makeRun($u);
        $r = $this->ledger->reserve($run);
        $this->ledger->settle($run);
        $new = CustomerPackageAssignment::create([...$a->toArray(), 'id' => null, 'supersedes_id' => $a->id, 'source' => 'change', 'starts_at' => now(), 'allowance_starts_at' => $a->allowance_starts_at, 'created_at' => now(), 'updated_at' => now()]);
        $next = $this->makeRun($u);
        $this->ledger->reserve($next);
        $this->ledger->settle($next);
        $this->assertSame(120, $this->ledger->balances($u->id, $new->allowance_starts_at)['seconds']);
        $new->update(['allowance_starts_at' => now()]);
        $next = $this->makeRun($u);
        $this->ledger->reserve($next);
        $this->ledger->settle($next);
        $this->assertSame(60, $this->ledger->balances($u->id, $new->fresh()->allowance_starts_at)['seconds']);
        $this->assertSame($a->id, $r->fresh()->assignment_id);
    }

    public function test_correction_is_append_only_idempotent_bounded_and_requires_reason(): void
    {
        [$u,$a] = $this->customer();
        $run = $this->makeRun($u);
        $r = $this->ledger->reserve($run);
        $this->ledger->settle($run);
        $r->refresh();
        $this->actingAs($this->admin, 'admin');
        $url = '/admin/usage-ledger/'.$r->id.'/adjust';
        $this->postJson($url, $this->correction($r, ['reason' => '']))->assertUnprocessable()->assertJsonValidationErrors('reason');
        $payload = $this->correction($r);
        $this->postJson($url, $payload)->assertOk();
        $this->postJson($url, $payload)->assertOk();
        $this->assertSame(60, $r->fresh()->charged_seconds);
        $this->assertSame(30, $r->fresh()->returned_seconds);
        $this->assertSame(1, UsageEvent::where('kind', 'adjusted')->count());
        $this->postJson($url, $this->correction($r->fresh(), ['seconds' => 31]))->assertUnprocessable();
        $this->postJson($url, $this->correction($r, ['seconds' => 10]))->assertConflict();
        $this->assertSame(30, $this->ledger->balances($u->id, $a->allowance_starts_at)['seconds']);
    }

    public function test_storage_only_tracks_verified_objects_once_and_preserves_deletion_history(): void
    {
        [$u] = $this->customer();
        $project = $this->makeRun($u)->project;
        $id = DB::table('media_files')->insertGetId(['media_project_id' => $project->id, 'disk' => 'local', 'path' => $project->source_path, 'bytes' => 1024, 'state' => 'active', 'is_source' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->ledger->syncStorage($project);
        $this->assertDatabaseCount('usage_records', 0);
        DB::table('media_files')->where('id', $id)->update(['verified_at' => now()]);
        $this->ledger->syncStorage($project);
        $this->ledger->syncStorage($project);
        $this->assertDatabaseCount('usage_records', 1);
        $this->assertSame(1024, $this->ledger->storageBytes($u->id));
        $this->ledger->discardStorage($project);
        $project->delete();
        $r = UsageRecord::sole();
        $this->assertSame(0, $r->storage_bytes);
        $this->assertSame('released', $r->status);
        $this->assertSame(2, $r->events()->count());
    }

    public function test_reconciliation_never_invents_old_charges_or_releases_a_running_job(): void
    {
        [$u] = $this->customer();
        $run = $this->makeRun($u);
        $r = $this->ledger->reserve($run);
        $run->update(['status' => 'rendering']);
        $old = $this->makeRun($u);
        $old->update(['status' => 'completed']);
        $this->artisan('usage:reconcile')->assertSuccessful();
        $this->assertSame('reserved', $r->fresh()->status);
        $this->assertDatabaseCount('usage_records', 1);
        $run->update(['status' => 'failed']);
        $this->artisan('usage:reconcile')->assertSuccessful();
        $this->assertSame('released', $r->fresh()->status);
    }

    public function test_filters_totals_and_csv_use_real_scoped_data_and_escape_spreadsheet_formulas(): void
    {
        $r = UsageRecord::factory()->create(['customer_snapshot' => ['name' => '=DANGEROUS()', 'email' => 'customer@example.test'], 'charged_seconds' => 120, 'returned_seconds' => 30, 'status' => 'adjusted']);
        UsageRecord::factory()->create(['charged_seconds' => 300, 'updated_at' => now()->subDays(60)]);
        $this->actingAs($this->admin, 'admin')->getJson('/admin/usage-ledger')->assertOk()->assertJsonPath('summary.seconds', 90)->assertJsonPath('pagination.total', 2);
        $this->getJson('/admin/usage-ledger?q='.$r->code())->assertOk()->assertJsonPath('pagination.total', 1);
        $this->getJson('/admin/usage-ledger?kind=storage')->assertOk()->assertJsonPath('pagination.total', 0);
        $csv = $this->get('/admin/usage-ledger/export?q='.$r->code())->assertOk()->streamedContent();
        $this->assertStringContainsString("'=DANGEROUS()", $csv);
        $this->assertStringNotContainsString('Server key', $csv);
    }

    public function test_end_access_keeps_usage_and_does_not_delete_payment_or_allowance_history(): void
    {
        [$u,$a] = $this->customer();
        $r = $this->ledger->reserve($this->makeRun($u));
        $a->update(['closed_kind' => 'ended', 'closed_at' => now()]);
        $this->actingAs($this->admin, 'admin')->getJson('/admin/usage-ledger/'.$r->id)->assertOk()->assertJsonPath('period_status', 'ended');
        $this->assertSame(60, $this->ledger->balances($u->id, $a->allowance_starts_at)['reserved_seconds']);
    }

    public function test_start_reserves_before_dispatch_and_quota_rejection_rolls_back_new_run(): void
    {
        [$u,$a] = $this->customer(['included_seconds' => 60]);
        config(['media.gemini_key' => 'test-ledger-provider-key', 'operations.auto_launch_allowed' => false]);
        $this->mock(MediaRuntime::class, function ($mock): void {
            $mock->shouldReceive('available')->andReturn(true);
            $mock->shouldReceive('capabilities')->andReturn(['mediaReady' => true, 'aiReady' => true]);
        });
        $project = MediaProject::factory()->create(['user_id' => $u->id, 'metadata' => ['audio' => true, 'duration' => 60]]);
        $this->actingAs($u, 'web')->postJson('/media/projects/'.$project->id.'/start')->assertAccepted();
        $record = UsageRecord::where('media_project_id', $project->id)->sole();
        $this->assertSame('reserved', $record->status);
        $this->assertSame(60, $record->reserved_seconds);
        Queue::assertPushed(ProcessMediaRun::class);
        $other = MediaProject::factory()->create(['user_id' => $u->id, 'metadata' => ['audio' => true, 'duration' => 60]]);
        $this->postJson('/media/projects/'.$other->id.'/start')->assertUnprocessable()->assertJsonValidationErrors('usage');
        $this->assertSame(0, $other->runs()->count());
        $this->assertNull($other->fresh()->active_run_id);
    }

    public function test_queued_cancellation_releases_and_worker_failure_releases(): void
    {
        [$u] = $this->customer();
        $run = $this->makeRun($u);
        $run->project->update(['status' => 'queued']);
        $this->ledger->reserve($run);
        app(MediaRunActions::class)->cancel($run->project->fresh());
        $this->assertSame('released', UsageRecord::where('media_run_id', $run->id)->sole()->status);
        $other = $this->makeRun($u);
        $this->ledger->reserve($other);
        (new ProcessMediaRun($other->id))->failed(null);
        $this->assertSame('failed', $other->fresh()->status);
        $this->assertSame('released', UsageRecord::where('media_run_id', $other->id)->sole()->status);
    }

    public function test_a_cancelled_render_can_be_retried_and_reserves_its_record_again(): void
    {
        Queue::fake();
        [$u] = $this->customer();
        $run = $this->makeRun($u);
        $run->update(['phase' => 'generate', 'status' => 'queued']);
        $run->project->update(['status' => 'queued']);
        $this->ledger->reserve($run);
        app(MediaRunActions::class)->cancel($run->project->fresh());
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->mock(MediaRuntime::class, fn ($mock) => $mock->shouldReceive('available')->andReturn(true));
        $this->partialMock(ProviderSettings::class, fn ($mock) => $mock->shouldReceive('withRevision')->andReturn(true));

        $this->actingAs($u, 'web')->postJson('/media/projects/'.$run->media_project_id.'/retry')->assertAccepted();
        $this->assertSame(['queued', false], [$run->fresh()->status, (bool) $run->fresh()->cancel_requested]);
        $this->assertSame('reserved', UsageRecord::where('media_run_id', $run->id)->sole()->status);
        Queue::assertPushed(ProcessMediaRun::class);

        // Retrying something that is neither failed nor cancelled explains itself instead of claiming it is busy.
        $run->update(['status' => 'completed']);
        $run->project->update(['status' => 'completed']);
        $this->postJson('/media/projects/'.$run->media_project_id.'/retry')->assertConflict()->assertJsonPath('code', 'retry_unavailable');
    }

    public function test_upload_reservations_and_confirmed_storage_respect_customer_storage_cap(): void
    {
        [$u] = $this->customer(['storage_bytes' => 1024, 'max_upload_bytes' => 1024]);
        $pending = MediaProject::factory()->create(['user_id' => $u->id, 'status' => 'uploading', 'expected_bytes' => 900]);
        $this->assertSame(900, $this->ledger->allocatedStorage($u->id));
        try {
            $this->ledger->checkUpload($u->id, 200, 'local');
            $this->fail();
        } catch (MediaFailure $e) {
            $this->assertSame('storage_limit', $e->reason);
        }
        $pending->update(['status' => 'ready']);
        $this->ledger->checkUpload($u->id, 200, 'local');
        $this->assertSame(0, $this->ledger->allocatedStorage($u->id));
        $this->expectException(MediaFailure::class);
        $this->ledger->checkUpload($u->id, 2048, 'local');
    }
}
