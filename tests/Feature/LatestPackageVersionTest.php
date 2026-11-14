<?php

namespace Tests\Feature;

use App\Exceptions\MediaFailure;
use App\Models\CustomerPackageAssignment;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\PaymentTransaction;
use App\Models\UsageRecord;
use App\Models\User;
use App\Services\UsageLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LatestPackageVersionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Package $package;

    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 11, 12)->setTime(10, 0));
        config(['media.max_upload_bytes' => 2147483648, 'media.max_duration_seconds' => 600]);
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($this->admin, 'admin');
        $this->payload = [
            'name' => 'Pro', 'code' => 'PKG-PRO', 'type' => 'paid', 'status' => 'active',
            'currency' => 'USD', 'price' => '9.99', 'validity_days' => 30, 'included_minutes' => 60,
            'video_limit' => 20, 'storage_gib' => 2, 'max_video_seconds' => 300, 'max_upload_mib' => 100,
            'concurrency' => 1, 'queue_limit' => 3, 'retention_days' => 7, 'description' => '', 'note' => '',
            'features' => ['local' => true, 'youtube' => true, 'subs' => true, 'dub' => false, 'mp4' => true,
                'srt' => true, 'audio' => false, 'script_edit' => true, 'voice_preview' => false,
                'video_preview' => true, 'subtitle_style' => true, 'cover_subtitles' => true,
                'layout' => true, 'keep_background' => false],
            'request_id' => (string) Str::uuid(),
        ];
        $id = $this->postJson('/admin/packages', $this->payload)->assertCreated()->json('package.id');
        $this->package = Package::findOrFail($id);
    }

    private function assign(?User $user = null, array $extra = []): CustomerPackageAssignment
    {
        $user ??= User::factory()->create(['managed_account' => true]);
        $version = PackageVersion::where('package_id', $this->package->id)->where('number', 1)->firstOrFail();

        return CustomerPackageAssignment::create(array_replace([
            'user_id' => $user->id, 'package_id' => $this->package->id,
            'package_version_id' => $version->id, 'assigned_by' => $this->admin->id, 'source' => 'payment_new',
            'snapshot' => ['name' => 'Pro', 'code' => 'PKG-PRO', 'type' => 'paid', 'version' => 1,
                'validity_mode' => 'package', 'definition' => $version->definition],
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(29), 'allowance_starts_at' => now()->subDay(),
        ], $extra));
    }

    private function publish(array $changes = []): void
    {
        $this->payload = array_replace($this->payload, $changes);
        $this->patchJson('/admin/packages/'.$this->package->id, [
            ...$this->payload, 'revision' => $this->package->fresh()->revision,
            'request_id' => (string) Str::uuid(), 'confirm_version' => true, 'reason' => 'Update published customer access',
        ])->assertOk();
        $this->package->refresh();
    }

    public function test_publishing_updates_every_live_and_scheduled_customer_without_rewriting_purchases(): void
    {
        $first = $this->assign();
        $second = $this->assign();
        $next = $this->assign($first->user, [
            'source' => 'payment_renewal', 'starts_at' => $first->ends_at,
            'ends_at' => $first->ends_at->addDays(30), 'allowance_starts_at' => $first->ends_at,
        ]);
        $before = collect([$first, $second, $next])->mapWithKeys(fn ($a) => [$a->id => $a->fresh()->getAttributes()]);
        $payment = PaymentTransaction::factory()->create([
            'user_id' => $first->user_id, 'subscription_id' => $first->id, 'status' => 'approved',
            'package_version_id' => $first->package_version_id,
            'package_snapshot' => $first->snapshot,
        ]);
        $paidBefore = $payment->fresh()->getAttributes();
        $usage = UsageRecord::factory()->create([
            'user_id' => $first->user_id, 'assignment_id' => $first->id,
            'allowance_start' => $first->allowance_starts_at, 'package_snapshot' => $first->snapshot,
            'charged_seconds' => 120, 'charged_videos' => 1,
        ]);
        $usageBefore = $usage->fresh()->getAttributes();
        $features = array_fill_keys(array_keys($this->payload['features']), true);
        $this->publish(['features' => $features, 'price' => '19.99', 'validity_days' => 90,
            'included_minutes' => 300, 'storage_gib' => 5]);

        foreach ([$first, $second, $next] as $period) {
            // Reuse the loaded model too: a fresh login/model is not required.
            $this->assertSame(2, $period->effectiveSnapshot()['version']);
            $this->assertTrue($period->effectiveSnapshot()['definition']['features']['dub']);
            $this->assertSame(5, $period->effectiveSnapshot()['definition']['storage_gib']);
            $this->assertSame($before[$period->id], $period->fresh()->getAttributes());
        }
        $this->getJson('/admin/users/'.$first->user_id)->assertOk()
            ->assertJsonPath('customer.package.version', 2)
            ->assertJsonPath('customer.package.validity_mode', 'custom')
            ->assertJsonPath('customer.package.period_days', 30);
        $this->getJson('/admin/subscriptions/'.$first->id)->assertOk()
            ->assertJsonPath('subscription.package.version', 2);
        $this->assertSame($paidBefore, $payment->fresh()->getAttributes());
        $this->assertSame($usageBefore, $usage->fresh()->getAttributes());
        $this->assertSame(120, app(UsageLedger::class)->balances($first->user_id, $first->allowance_starts_at)['seconds']);

        $this->publish(['storage_gib' => 10]);
        $this->assertSame(3, $first->effectiveSnapshot()['version']);
        $this->assertSame(10, $next->effectiveSnapshot()['definition']['storage_gib']);
        $this->assertDatabaseCount('customer_package_assignments', 3);
    }

    public function test_expired_ended_cancelled_and_replaced_periods_keep_their_historical_snapshot(): void
    {
        $expired = $this->assign(null, ['starts_at' => now()->subDays(31), 'ends_at' => now()->subDay()]);
        $ended = $this->assign(null, ['closed_kind' => 'ended', 'closed_at' => now()]);
        $cancelled = $this->assign(null, ['closed_kind' => 'cancelled', 'closed_at' => now()]);
        $replaced = $this->assign();
        $replacement = $this->assign($replaced->user, ['source' => 'admin_correction', 'supersedes_id' => $replaced->id]);
        $this->publish(['included_minutes' => 120]);
        foreach ([$expired, $ended, $cancelled, $replaced] as $historical) {
            $this->assertSame(1, $historical->effectiveSnapshot()['version']);
            $this->assertSame($historical->snapshot, $historical->effectiveSnapshot());
        }
        $this->assertSame(2, $replacement->effectiveSnapshot()['version']);
    }

    public function test_latest_limits_apply_to_new_work_without_resetting_usage_or_existing_reservations(): void
    {
        $assignment = $this->assign();
        $user = $assignment->user;
        $ledger = app(UsageLedger::class);
        $project = MediaProject::factory()->create(['user_id' => $user->id, 'metadata' => ['duration' => 60, 'audio' => true]]);
        $firstRun = MediaRun::factory()->create(['media_project_id' => $project->id]);
        $record = $ledger->reserve($firstRun);
        $recordBefore = $record->fresh()->getAttributes();
        $this->publish(['included_minutes' => 5, 'video_limit' => 1]);
        $this->assertSame($recordBefore, $record->fresh()->getAttributes());
        $ledger->settle($firstRun);
        $firstRun->update(['status' => 'completed']);
        $this->assertSame(240, $ledger->quote($project)['available_seconds']);
        $newProject = MediaProject::factory()->create(['user_id' => $user->id, 'metadata' => ['duration' => 60, 'audio' => true]]);
        $run = MediaRun::factory()->create(['media_project_id' => $newProject->id]);
        try {
            $ledger->reserve($run);
            $this->fail('The latest video cap must reject new work.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('video allowance', $e->getMessage());
        }
        $this->assertSame(1, UsageRecord::where('user_id', $user->id)->count());
        $this->assertSame(1, $record->fresh()->package_snapshot['version']);

        $this->publish(['video_limit' => 2, 'included_minutes' => 120]);
        $newRecord = $ledger->reserve($run);
        $this->assertSame(3, $newRecord->package_snapshot['version']);
        $this->assertSame(60, $ledger->balances($user->id, $assignment->allowance_starts_at)['seconds']);
        $this->assertSame(60, $ledger->balances($user->id, $assignment->allowance_starts_at)['reserved_seconds']);
    }

    public function test_storage_and_upload_limits_refresh_without_removing_existing_files(): void
    {
        $assignment = $this->assign();
        $project = MediaProject::factory()->create(['user_id' => $assignment->user_id]);
        $ledger = app(UsageLedger::class);
        $fileId = DB::table('media_files')->insertGetId([
            'media_project_id' => $project->id, 'disk' => 'local', 'path' => $project->source_path,
            'bytes' => 1610612736, 'state' => 'active', 'is_source' => true, 'verified_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $ledger->checkUpload($assignment->user_id, 100 * 1048576, 'local');
        $this->publish(['storage_gib' => 1, 'max_upload_mib' => 50]);
        try {
            $ledger->checkUpload($assignment->user_id, 100 * 1048576, 'local');
            $this->fail('The latest maximum upload must apply.');
        } catch (MediaFailure $e) {
            $this->assertSame('too_large', $e->reason);
        }
        try {
            $ledger->checkStorage($project, 1);
            $this->fail('New output must fit the latest quota.');
        } catch (MediaFailure $e) {
            $this->assertSame('storage_limit', $e->reason);
        }
        $this->assertDatabaseHas('media_files', ['id' => $fileId, 'state' => 'active', 'bytes' => 1610612736]);
        $this->publish(['storage_gib' => 5, 'max_upload_mib' => 500]);
        $ledger->checkUpload($assignment->user_id, 200 * 1048576, 'local');
        $ledger->checkStorage($project, 200 * 1048576);
        $this->assertSame(1610612736, $ledger->storageBytes($assignment->user_id));
    }

    public function test_profile_edit_after_validity_change_keeps_original_dates_and_allowance_period(): void
    {
        $assignment = $this->assign();
        $user = $assignment->user;
        $before = $assignment->fresh()->getAttributes();
        $this->publish(['validity_days' => 90]);
        $this->patchJson('/admin/users/'.$user->id, [
            'name' => 'Corrected customer', 'email' => $user->email, 'preferred_locale' => 'en', 'note' => '',
            'revision' => $user->account_revision, 'request_id' => (string) Str::uuid(),
            'package_id' => $this->package->id, 'package_version' => 2, 'validity_mode' => 'custom',
            'starts_on' => $assignment->starts_at->toDateString(), 'ends_on' => $assignment->ends_at->toDateString(),
        ])->assertOk()->assertJsonPath('customer.package.version', 2);
        $this->assertSame($before, $assignment->fresh()->getAttributes());
        $this->assertDatabaseCount('customer_package_assignments', 1);
    }

    public function test_unpublished_edits_do_not_grant_features_and_deactivation_does_not_revoke_paid_access(): void
    {
        $assignment = $this->assign();
        $definition = $this->package->definition;
        $definition['features']['dub'] = true;
        $this->package->update(['definition' => $definition]);
        $this->assertFalse($assignment->effectiveSnapshot()['definition']['features']['dub']);
        $this->package->update(['definition' => PackageVersion::where('package_id', $this->package->id)->where('number', 1)->firstOrFail()->definition]);
        $this->publish(['features' => $definition['features']]);
        $this->package->update(['status' => 'archived']);
        $this->assertSame(2, $assignment->effectiveSnapshot()['version']);
        $this->assertTrue($assignment->effectiveSnapshot()['definition']['features']['dub']);
    }
}
