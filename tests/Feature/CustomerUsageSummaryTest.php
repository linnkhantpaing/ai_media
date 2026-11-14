<?php

namespace Tests\Feature;

use App\Models\CustomerPackageAssignment;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomerUsageSummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private CustomerPackageAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 11, 12)->setTime(10, 0));
        Storage::fake('local');
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->customer = User::factory()->create(['managed_account' => true]);
        $package = Package::factory()->create(['status' => 'active', 'published_version' => 1]);
        $version = PackageVersion::create(['package_id' => $package->id, 'number' => 1,
            'definition' => [...$package->definition, 'included_seconds' => 600, 'included_minutes' => 10, 'video_limit' => 5], 'actor_id' => $this->admin->id]);
        $this->assignment = CustomerPackageAssignment::create([
            'user_id' => $this->customer->id, 'package_id' => $package->id, 'package_version_id' => $version->id,
            'assigned_by' => $this->admin->id, 'source' => 'payment_new',
            'snapshot' => ['name' => $package->name, 'code' => $package->code, 'type' => 'paid', 'version' => 1, 'definition' => $version->definition],
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(29), 'allowance_starts_at' => now()->subDay(),
        ]);
        $this->actingAs($this->admin, 'admin');
    }

    private function record(array $extra = []): UsageRecord
    {
        return UsageRecord::factory()->create(array_replace([
            'user_id' => $this->customer->id, 'assignment_id' => $this->assignment->id,
            'package_id' => $this->assignment->package_id, 'allowance_start' => $this->assignment->allowance_starts_at,
            'period_start' => $this->assignment->starts_at, 'period_end' => $this->assignment->ends_at,
            'package_snapshot' => $this->assignment->snapshot,
            'customer_snapshot' => ['name' => $this->customer->name, 'email' => $this->customer->email],
        ], $extra));
    }

    private function success(?MediaProject $project = null, array $extra = []): UsageRecord
    {
        $project ??= MediaProject::factory()->create(['user_id' => $this->customer->id, 'status' => 'completed', 'output_path' => 'result.mp4', 'metadata' => ['poster' => true, 'duration' => 60]]);
        $run = MediaRun::factory()->create(['media_project_id' => $project->id, 'status' => 'completed', 'phase' => 'generate']);

        return $this->record(['media_project_id' => $project->id, 'media_run_id' => $run->id, ...$extra]);
    }

    public function test_many_operations_are_grouped_into_one_customer_with_exact_remaining_after_reservations(): void
    {
        $this->record(['charged_seconds' => 240, 'returned_seconds' => 60, 'charged_videos' => 2]);
        $this->record(['status' => 'reserved', 'charged_seconds' => 0, 'charged_videos' => 0, 'reserved_seconds' => 120, 'reserved_videos' => 1]);
        $this->record(['status' => 'released', 'charged_seconds' => 0, 'charged_videos' => 0]);
        $this->record(['kind' => 'storage', 'status' => 'stored', 'charged_seconds' => 0, 'charged_videos' => 0, 'storage_bytes' => 999]);

        $this->getJson('/admin/usage-ledger')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('items.0.id', $this->customer->id)
            ->assertJsonPath('items.0.allowance.seconds', 600)->assertJsonPath('items.0.used.seconds', 180)
            ->assertJsonPath('items.0.reserved.seconds', 120)->assertJsonPath('items.0.remaining.seconds', 300)
            ->assertJsonPath('items.0.remaining.videos', 2)->assertJsonPath('summary.seconds', 180)
            ->assertJsonPath('summary.videos', 2)->assertJsonPath('summary.reserved_seconds', 120)
            ->assertJsonPath('summary.storage_bytes', 0)->assertJsonPath('items.0.recent_count', 0);
        $this->getJson('/admin/usage-ledger/customers/'.$this->customer->id)->assertOk()->assertJsonCount(4, 'records');
        $this->assertDatabaseCount('usage_records', 4);
    }

    public function test_recent_usage_is_distinct_successful_video_projects_only_and_more_count_is_correct(): void
    {
        $project = MediaProject::factory()->create(['user_id' => $this->customer->id, 'status' => 'completed', 'output_path' => 'result.mp4']);
        $this->success($project);
        $latest = $this->success($project, ['charged_videos' => 0]);
        for ($i = 0; $i < 4; $i++) {
            $this->success();
        }
        foreach (['failed', 'cancelled', 'queued', 'awaiting_review'] as $status) {
            $run = MediaRun::factory()->create(['media_project_id' => $project->id, 'status' => $status]);
            $this->record(['media_project_id' => $project->id, 'media_run_id' => $run->id]);
        }
        $audio = MediaProject::factory()->create(['user_id' => $this->customer->id, 'status' => 'completed', 'audio_path' => 'narration.mp3', 'output_path' => null]);
        $this->success($audio);
        $this->getJson('/admin/usage-ledger')->assertOk()->assertJsonPath('items.0.recent_count', 5)->assertJsonCount(3, 'items.0.recent');
        $detail = $this->getJson('/admin/usage-ledger/customers/'.$this->customer->id)->assertOk()
            ->assertJsonPath('recent_pagination.total', 5)->assertJsonCount(5, 'recent');
        $this->assertContains($latest->id, array_column($detail->json('recent'), 'id'));
        $this->assertSame(5, count(array_unique(array_column($detail->json('recent'), 'project_id'))));
    }

    public function test_latest_package_limits_and_original_allowance_origin_are_used_without_resetting_usage(): void
    {
        $this->record(['charged_seconds' => 240, 'charged_videos' => 2]);
        $old = $this->assignment;
        $new = CustomerPackageAssignment::create([
            ...$old->toArray(), 'id' => null, 'supersedes_id' => $old->id, 'source' => 'account_correction',
            'starts_at' => now(), 'allowance_starts_at' => $old->starts_at,
        ]);
        $definition = [...$old->snapshot['definition'], 'included_seconds' => 1200, 'video_limit' => 10];
        PackageVersion::create(['package_id' => $old->package_id, 'number' => 2, 'definition' => $definition, 'actor_id' => $this->admin->id]);
        Package::whereKey($old->package_id)->update(['published_version' => 2]);
        $this->getJson('/admin/usage-ledger')->assertOk()->assertJsonPath('items.0.package.version', 2)
            ->assertJsonPath('items.0.used.seconds', 240)->assertJsonPath('items.0.remaining.seconds', 960)
            ->assertJsonPath('items.0.remaining.videos', 8);
        $this->assertSame($old->starts_at->toDateTimeString(), $new->allowance_starts_at->toDateTimeString());
    }

    public function test_storage_remaining_counts_verified_files_and_upload_reservations_without_using_duplicate_events(): void
    {
        $project = MediaProject::factory()->create(['user_id' => $this->customer->id]);
        DB::table('media_files')->insert(['media_project_id' => $project->id, 'disk' => 'local', 'path' => 'source.mp4', 'bytes' => 100,
            'state' => 'active', 'is_source' => true, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('media_files')->insert(['media_project_id' => $project->id, 'disk' => 'local', 'path' => 'deleted.mp4', 'bytes' => 900,
            'state' => 'deleted', 'is_source' => false, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        MediaProject::factory()->create(['user_id' => $this->customer->id, 'status' => 'uploading', 'expected_bytes' => 50]);
        $this->record(['kind' => 'storage', 'storage_bytes' => 999, 'charged_seconds' => 0, 'charged_videos' => 0]);
        $this->getJson('/admin/usage-ledger')->assertOk()->assertJsonPath('summary.storage_bytes', 100)
            ->assertJsonPath('items.0.used.storage_bytes', 100)->assertJsonPath('items.0.reserved.storage_bytes', 50)
            ->assertJsonPath('items.0.remaining.storage_bytes', 1073741674);
    }

    public function test_old_periods_do_not_use_current_allowance_and_date_filters_do_not_reset_balances(): void
    {
        $old = $this->record(['allowance_start' => now()->subDays(31), 'charged_seconds' => 400, 'updated_at' => now()->subDays(31)]);
        $this->record(['charged_seconds' => 120, 'updated_at' => now()->subHours(20)]);
        $this->getJson('/admin/usage-ledger?dates=1')->assertOk()->assertJsonPath('items.0.used.seconds', 120)
            ->assertJsonPath('items.0.remaining.seconds', 480)->assertJsonPath('summary.seconds', 0);
        $this->getJson('/admin/usage-ledger?dates=all')->assertOk()->assertJsonPath('summary.seconds', 120);
        $this->getJson('/admin/usage-ledger?dates=all&period=all')->assertOk()->assertJsonCount(1, 'items')
            ->assertJsonPath('summary.seconds', 520)->assertJsonPath('items.0.used.seconds', 120);
        $this->getJson('/admin/usage-ledger/customers/'.$this->customer->id.'?dates=all&period=all')->assertOk()->assertJsonPath('records_pagination.total', 2);
        $this->getJson('/admin/usage-ledger/'.$old->id)->assertOk();
    }

    public function test_customer_filters_keep_usage_from_before_a_package_or_email_change_in_the_same_allowance_period(): void
    {
        $this->success(null, ['charged_seconds' => 240, 'charged_videos' => 2]);
        $package = Package::factory()->create(['published_version' => 1, 'status' => 'active']);
        $version = PackageVersion::create(['package_id' => $package->id, 'number' => 1, 'definition' => $package->definition, 'actor_id' => $this->admin->id]);
        CustomerPackageAssignment::create([
            ...$this->assignment->toArray(), 'id' => null, 'supersedes_id' => $this->assignment->id,
            'package_id' => $package->id, 'package_version_id' => $version->id, 'starts_at' => now(),
            'snapshot' => ['name' => $package->name, 'code' => $package->code, 'type' => 'paid', 'version' => 1, 'definition' => $package->definition],
        ]);
        $this->customer->update(['email' => 'corrected@example.test']);
        $query = '?package_id='.$package->id.'&q=corrected%40example.test';
        $this->getJson('/admin/usage-ledger'.$query)->assertOk()->assertJsonCount(1, 'items')
            ->assertJsonPath('summary.seconds', 240)->assertJsonPath('items.0.used.seconds', 240)
            ->assertJsonPath('items.0.recent_count', 1);
        $this->getJson('/admin/usage-ledger?package_id='.$package->id.'&q=Example%20recap')->assertOk()->assertJsonCount(1, 'items');
        $this->getJson('/admin/usage-ledger/customers/'.$this->customer->id.$query)->assertOk()
            ->assertJsonPath('records_pagination.total', 1)->assertJsonPath('recent_pagination.total', 1);
        $this->assertStringContainsString('240', $this->get('/admin/usage-ledger/export'.$query)->assertOk()->streamedContent());
    }

    public function test_customer_pagination_filters_and_empty_usage_still_show_real_allowances(): void
    {
        $this->record();
        for ($i = 0; $i < 15; $i++) {
            $this->record(['charged_videos' => 0]);
        }
        User::factory()->create(['managed_account' => true, 'name' => 'No projects yet']);
        $this->getJson('/admin/usage-ledger?per_page=10')->assertOk()->assertJsonPath('pagination.total', 2);
        $this->getJson('/admin/usage-ledger?q='.urlencode($this->customer->email))->assertOk()
            ->assertJsonPath('pagination.total', 1)->assertJsonPath('items.0.customer.email', $this->customer->email);
        $this->getJson('/admin/usage-ledger/customers/'.$this->customer->id.'?records_page=2')->assertOk()
            ->assertJsonPath('records_pagination.total', 16)->assertJsonCount(6, 'records');
        $this->getJson('/admin/usage-ledger?kind=storage')->assertOk()->assertJsonPath('pagination.total', 0);
        $this->getJson('/admin/usage-ledger?dates=custom')->assertUnprocessable();
    }

    public function test_completed_video_is_served_privately_and_only_for_its_own_matching_run(): void
    {
        $record = $this->success();
        $project = MediaProject::findOrFail($record->media_project_id);
        $path = 'media/'.$project->id.'/export.mp4';
        Storage::disk('local')->put($path, 'test video contents');
        $project->update(['output_path' => $path]);
        $this->get('/admin/usage-ledger/'.$record->id.'/video')->assertOk()->assertHeader('Content-Type', 'video/mp4')
            ->assertHeader('Cache-Control', 'no-store, private');
        $other = MediaProject::factory()->create(['user_id' => $this->customer->id]);
        MediaRun::whereKey($record->media_run_id)->update(['media_project_id' => $other->id]);
        $this->getJson('/admin/usage-ledger/'.$record->id.'/video')->assertNotFound();
    }

    public function test_late_finishing_export_is_the_recent_result_even_when_it_has_an_older_reservation_id(): void
    {
        $project = MediaProject::factory()->create(['user_id' => $this->customer->id, 'status' => 'completed', 'output_path' => 'result.mp4']);
        $late = $this->success($project);
        $this->success($project);
        $late->update(['updated_at' => now()->addMinute()]);
        $this->getJson('/admin/usage-ledger')->assertOk()->assertJsonPath('items.0.recent_count', 1)
            ->assertJsonPath('items.0.recent.0.id', $late->id);
    }

    public function test_customer_details_video_and_history_remain_admin_only_and_failed_runs_cannot_play(): void
    {
        $successful = $this->success();
        $run = MediaRun::factory()->create(['media_project_id' => $successful->media_project_id, 'status' => 'failed']);
        $failed = $this->record(['media_project_id' => $successful->media_project_id, 'media_run_id' => $run->id]);
        $this->getJson('/admin/usage-ledger/'.$failed->id.'/video')->assertNotFound();
        $this->getJson('/admin/usage-ledger/customers/'.$this->admin->id)->assertNotFound();
        auth('admin')->logout();
        $this->actingAs($this->customer, 'web');
        foreach (['/admin/usage-ledger', '/admin/usage-ledger/customers/'.$this->customer->id,
            '/admin/usage-ledger/'.$successful->id, '/admin/usage-ledger/'.$successful->id.'/video'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
    }
}
