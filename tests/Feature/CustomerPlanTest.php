<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\CustomerPackageAssignment;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\PaymentTransaction;
use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CustomerPlanTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private CustomerPackageAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 11, 18)->setTime(10, 0));
        $this->customer = User::factory()->create(['managed_account' => true]);
        $package = Package::factory()->create(['name' => 'Pro', 'published_version' => 1, 'status' => 'active']);
        $version = PackageVersion::create(['package_id' => $package->id, 'number' => 1,
            'definition' => [...$package->definition, 'included_seconds' => 600, 'video_limit' => 5, 'note' => 'PRIVATE_ADMIN_NOTE']]);
        $this->assignment = CustomerPackageAssignment::create([
            'user_id' => $this->customer->id, 'package_id' => $package->id, 'package_version_id' => $version->id,
            'source' => 'payment_new', 'snapshot' => ['name' => 'Pro', 'code' => $package->code, 'type' => 'paid', 'version' => 1, 'definition' => $version->definition],
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(29), 'allowance_starts_at' => now()->subDay(),
        ]);
        $this->actingAs($this->customer);
    }

    private function record(array $attributes = []): UsageRecord
    {
        return UsageRecord::factory()->create([
            'user_id' => $this->customer->id, 'assignment_id' => $this->assignment->id, 'package_id' => $this->assignment->package_id,
            'allowance_start' => $this->assignment->allowance_starts_at, 'period_start' => $this->assignment->starts_at,
            'period_end' => $this->assignment->ends_at, 'package_snapshot' => $this->assignment->snapshot,
            ...$attributes,
        ]);
    }

    private function export(array $attributes = [], ?MediaProject $project = null): MediaRun
    {
        $project ??= MediaProject::factory()->create(['user_id' => $this->customer->id, 'title' => 'My recap', 'status' => 'completed', 'output_path' => 'result.mp4']);

        return MediaRun::factory()->create([
            'media_project_id' => $project->id, 'phase' => 'generate', 'status' => 'completed', 'finished_at' => now()->subHour(),
            'settings' => ['mode' => 'dub', 'workflow' => 'movie', 'private_key' => 'DO_NOT_EXPOSE'], ...$attributes,
        ]);
    }

    private function page(string $query = ''): TestResponse
    {
        return $this->get('/account/plan'.$query, ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/account/plan'))]);
    }

    public function test_personal_balances_are_scoped_exact_and_do_not_mutate_usage(): void
    {
        $this->record(['charged_seconds' => 240, 'returned_seconds' => 60, 'charged_videos' => 2]);
        $this->record(['status' => 'reserved', 'charged_seconds' => 0, 'charged_videos' => 0, 'reserved_seconds' => 120, 'reserved_videos' => 1]);
        $this->record(['allowance_start' => now()->subDays(40), 'charged_seconds' => 500]);
        UsageRecord::factory()->create(['charged_seconds' => 900]);
        $project = MediaProject::factory()->create(['user_id' => $this->customer->id]);
        DB::table('media_files')->insert(['media_project_id' => $project->id, 'disk' => 'local', 'path' => 'input.mp4', 'bytes' => 100,
            'state' => 'active', 'is_source' => true, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        MediaProject::factory()->create(['user_id' => $this->customer->id, 'status' => 'uploading', 'expected_bytes' => 50]);
        $response = $this->page()->assertOk()->assertJsonPath('component', 'account/plan')
            ->assertJsonPath('props.overview.package.id', $this->assignment->package_id)
            ->assertJsonPath('props.availablePlans.0.id', $this->assignment->package_id)
            ->assertJsonPath('props.availablePlans.0.type', 'paid')
            ->assertJsonPath('props.overview.processing.total', 600)->assertJsonPath('props.overview.processing.used', 180)
            ->assertJsonPath('props.overview.processing.reserved', 120)->assertJsonPath('props.overview.processing.available', 300)
            ->assertJsonPath('props.overview.videos.available', 2)->assertJsonPath('props.overview.storage.used', 100)
            ->assertJsonPath('props.overview.storage.reserved', 50)->assertJsonPath('props.overview.storage.available', 1073741674);
        $this->assertStringNotContainsString('PRIVATE_ADMIN_NOTE', $response->getContent());
        $this->assertDatabaseCount('usage_records', 4);
        $this->assertDatabaseCount('customer_package_assignments', 1);
    }

    public function test_latest_published_terms_keep_the_original_allowance_origin_after_correction(): void
    {
        $run = $this->export();
        $this->record(['charged_seconds' => 240, 'media_project_id' => $run->media_project_id, 'media_run_id' => $run->id]);
        $old = $this->assignment;
        CustomerPackageAssignment::create([
            ...$old->toArray(), 'id' => null, 'supersedes_id' => $old->id, 'source' => 'account_correction', 'starts_at' => now(),
        ]);
        PackageVersion::create(['package_id' => $old->package_id, 'number' => 2,
            'definition' => [...$old->snapshot['definition'], 'included_seconds' => 1200, 'video_limit' => 10]]);
        Package::whereKey($old->package_id)->update(['published_version' => 2]);
        $this->page()->assertOk()->assertJsonPath('props.overview.package.version', 2)
            ->assertJsonPath('props.overview.processing.used', 240)->assertJsonPath('props.overview.processing.available', 960)
            ->assertJsonPath('props.overview.videos.available', 9)->assertJsonPath('props.exports.total', 1);
    }

    public function test_successful_history_is_deduplicated_filtered_paginated_and_includes_subtitle_and_audio_exports(): void
    {
        $first = $this->export();
        $latest = $this->export(['finished_at' => now()], $first->project);
        $this->record(['media_project_id' => $latest->media_project_id, 'media_run_id' => $latest->id, 'charged_seconds' => 120]);
        for ($i = 0; $i < 10; $i++) {
            $this->export();
        }
        $subs = MediaProject::factory()->create(['user_id' => $this->customer->id, 'title' => 'Subtitle sample', 'output_path' => null, 'subtitle_path' => 'subtitles.srt']);
        $this->export(['settings' => ['mode' => 'subs']], $subs);
        $audio = MediaProject::factory()->create(['user_id' => $this->customer->id, 'title' => 'Audio sample', 'output_path' => null, 'audio_path' => 'voice.mp3']);
        $this->export(['settings' => ['mode' => 'dub', 'workflow' => 'translate']], $audio);
        $this->export(['status' => 'failed']);
        $this->export(['phase' => 'analysis']);
        $other = MediaProject::factory()->create(['title' => 'OTHER_CUSTOMER']);
        $private = $this->export([], $other);
        $page = $this->page()->assertOk()->assertJsonPath('props.exports.total', 13)->assertJsonCount(10, 'props.exports.data');
        $this->assertStringNotContainsString('DO_NOT_EXPOSE', $page->getContent());
        $this->assertStringNotContainsString('OTHER_CUSTOMER', $page->getContent());
        $this->assertSame($latest->id, $page->json('props.exports.data.0.id'));
        $this->assertNotContains($first->id, array_column($page->json('props.exports.data'), 'id'));
        $this->page('?page=2')->assertOk()->assertJsonCount(3, 'props.exports.data');
        $this->page('?type=subs&q=Subtitle')->assertOk()->assertJsonPath('props.exports.total', 1)->assertJsonPath('props.exports.data.0.outputs.0', 'SRT');
        $this->page('?type=translate')->assertOk()->assertJsonPath('props.exports.total', 1)->assertJsonPath('props.exports.data.0.outputs.0', 'MP3');
        $this->getJson('/account/plan/exports/'.$private->id)->assertNotFound();
    }

    public function test_usage_details_are_customer_safe_and_unknown_charges_are_not_fabricated(): void
    {
        $run = $this->export();
        $record = $this->record(['media_run_id' => $run->id, 'media_project_id' => $run->media_project_id]);
        $record->events()->create(['kind' => 'charged', 'changes' => ['secret' => 'HIDDEN_EVENT'], 'reason' => 'PRIVATE_REASON', 'created_at' => now()]);
        $response = $this->getJson('/account/plan/exports/'.$run->id)->assertOk()
            ->assertJsonPath('export.charged_seconds', 60)->assertJsonPath('export.duration', 4)
            ->assertJsonPath('package.version', 1)
            ->assertJsonPath('events.0.type', 'charged');
        foreach (['PRIVATE_REASON', 'HIDDEN_EVENT', 'PRIVATE_ADMIN_NOTE', 'DO_NOT_EXPOSE'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $uncharged = $this->export();
        $this->getJson('/account/plan/exports/'.$uncharged->id)->assertOk()->assertJsonPath('export.charged_seconds', null)->assertJsonCount(0, 'events');
        $failed = $this->export(['status' => 'failed']);
        $this->getJson('/account/plan/exports/'.$failed->id)->assertNotFound();
    }

    public function test_expired_upcoming_missing_and_overdrawn_plans_are_displayed_honestly(): void
    {
        $this->record(['charged_seconds' => 700, 'charged_videos' => 7]);
        $this->page()->assertOk()->assertJsonPath('props.overview.processing.available', 0)->assertJsonPath('props.overview.processing.exceeded', 100);
        $this->assignment->update(['starts_at' => now()->subDays(31), 'ends_at' => now()->subDay()]);
        $this->page()->assertOk()->assertJsonPath('props.overview.status', 'expired')->assertJsonPath('props.overview.processing.available', 0);
        $this->assignment->update(['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(31)]);
        $this->page()->assertOk()->assertJsonPath('props.overview.status', 'upcoming')->assertJsonPath('props.overview.processing.available', 0);
        $this->assignment->delete();
        $this->page()->assertOk()->assertJsonPath('props.overview.package', null)->assertJsonPath('props.overview.status', 'none')->assertJsonPath('props.exports.total', 0);
    }

    public function test_history_filters_do_not_change_balances_and_pending_payments_never_grant_access(): void
    {
        $this->record(['charged_seconds' => 120]);
        $this->export(['finished_at' => now()->subDays(40)]);
        PaymentTransaction::factory()->create(['user_id' => $this->customer->id, 'status' => 'pending_review']);
        $this->page()->assertOk()->assertJsonPath('props.exports.total', 0)->assertJsonPath('props.overview.pending_payment', true);
        $this->page('?period=all')->assertOk()->assertJsonPath('props.exports.total', 1)
            ->assertJsonPath('props.overview.processing.used', 120)->assertJsonPath('props.overview.processing.available', 480)
            ->assertJsonPath('props.overview.package.name', 'Pro');
        $this->assertDatabaseCount('customer_package_assignments', 1);
    }

    public function test_guest_and_admin_only_session_cannot_read_customer_page(): void
    {
        auth()->guard('web')->logout();
        $this->get('/account/plan')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['is_admin' => true]), 'admin');
        $this->get('/account/plan')->assertRedirect('/login');
    }

    public function test_invalid_history_filters_are_rejected(): void
    {
        $this->getJson('/account/plan?type=private&period=invalid&page=0')->assertUnprocessable()
            ->assertJsonValidationErrors(['type', 'period', 'page']);
    }

    public function test_unlimited_and_zero_limits_never_produce_negative_availability(): void
    {
        $version = PackageVersion::findOrFail($this->assignment->package_version_id);
        $version->update(['definition' => [...$version->definition, 'video_limit' => null, 'included_seconds' => 0]]);
        $this->record(['charged_seconds' => 60]);
        $this->page()->assertOk()->assertJsonPath('props.overview.videos.available', null)
            ->assertJsonPath('props.overview.processing.total', 0)->assertJsonPath('props.overview.processing.available', 0)
            ->assertJsonPath('props.overview.processing.exceeded', 60);
        $this->assignment->update(['ends_at' => now()->subMinute(), 'starts_at' => now()->subDays(30)]);
        $this->page()->assertOk()->assertJsonPath('props.overview.videos.available', 0);
    }

    public function test_release_and_charge_replace_reservations_without_double_counting(): void
    {
        $record = $this->record(['status' => 'reserved', 'charged_seconds' => 0, 'charged_videos' => 0, 'reserved_seconds' => 120, 'reserved_videos' => 1]);
        $this->page()->assertOk()->assertJsonPath('props.overview.processing.available', 480);
        $record->update(['status' => 'charged', 'charged_seconds' => 120, 'charged_videos' => 1, 'reserved_seconds' => 0, 'reserved_videos' => 0]);
        $this->page()->assertOk()->assertJsonPath('props.overview.processing.available', 480)
            ->assertJsonPath('props.overview.processing.used', 120)->assertJsonPath('props.overview.processing.reserved', 0);
        $record->update(['returned_seconds' => 120, 'returned_videos' => 1]);
        $this->page()->assertOk()->assertJsonPath('props.overview.processing.available', 600)->assertJsonPath('props.overview.videos.available', 5);
    }

    public function test_search_treats_underscores_and_percent_as_literal_characters(): void
    {
        foreach (['Clip_50% complete', 'ClipA50B complete'] as $title) {
            $this->export([], MediaProject::factory()->create(['user_id' => $this->customer->id, 'title' => $title, 'output_path' => 'result.mp4']));
        }
        $this->page('?q='.urlencode('Clip_50%'))->assertOk()->assertJsonPath('props.exports.total', 1)
            ->assertJsonPath('props.exports.data.0.title', 'Clip_50% complete');
    }

    public function test_running_and_future_period_information_is_owned_and_read_only(): void
    {
        $this->export(['status' => 'rendering']);
        $this->export(['status' => 'queued']);
        $this->export(['status' => 'retry_waiting']);
        $other = MediaProject::factory()->create();
        $this->export(['status' => 'rendering'], $other);
        CustomerPackageAssignment::create([
            ...$this->assignment->toArray(), 'id' => null, 'source' => 'payment_renewal',
            'starts_at' => $this->assignment->ends_at, 'ends_at' => $this->assignment->ends_at->addDays(30),
            'allowance_starts_at' => $this->assignment->ends_at,
        ]);
        $this->page()->assertOk()->assertJsonPath('props.overview.jobs.running', 1)
            ->assertJsonPath('props.overview.jobs.queued', 2)->assertJsonPath('props.overview.next.package.name', 'Pro');
        $this->assertDatabaseCount('customer_package_assignments', 2);
        $this->assertDatabaseCount('usage_records', 0);
    }
}
