<?php

namespace Tests\Feature;

use App\Jobs\ImportYouTubeVideo;
use App\Jobs\InspectMediaProject;
use App\Jobs\PrepareMediaPreview;
use App\Jobs\ProcessMediaRun;
use App\Models\CustomerPackageAssignment;
use App\Models\MediaPreview;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Models\MediaTask;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\UsageRecord;
use App\Models\User;
use App\Services\Media\MediaRuntime;
use App\Services\Media\OperationsTelemetry;
use App\Services\Media\StorageSettings;
use App\Services\UsageLedger;
use App\Support\MediaSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminJobMonitorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Queue::fake();
        config(['filesystems.media_disk' => 'local', 'media.gemini_key' => 'test-only-key',
            'queue.default' => 'database', 'operations.auto_launch_allowed' => false]);
        $this->mock(MediaRuntime::class)->shouldReceive('available')->andReturn(true);
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($this->admin, 'admin');
    }

    private function makeRun(string $status = 'failed', ?User $customer = null): array
    {
        $project = MediaProject::factory()->create(['user_id' => ($customer ?? User::factory()->create())->id,
            'status' => $status, 'metadata' => ['duration' => 60, 'audio' => true]]);
        Storage::disk('local')->put($project->source_path, 'video');
        $run = MediaRun::factory()->for($project, 'project')->create(['phase' => 'generate', 'status' => $status]);
        $project->update(['active_run_id' => $run->id]);

        return [$project, $run, MediaTask::where('media_run_id', $run->id)->firstOrFail()];
    }

    private function action(MediaTask $task, string $action, array $overrides = []): array
    {
        return array_replace(['action' => $action, 'confirm' => true, 'reason' => 'Administrator checked this job',
            'request_id' => (string) Str::uuid()], $overrides);
    }

    private function url(MediaTask $task): string
    {
        return '/admin/operations/jobs/'.$task->id.'/action';
    }

    private function customer(array $overrides = []): User
    {
        $user = User::factory()->create(['managed_account' => true]);
        $package = Package::factory()->create(['status' => 'active', 'published_version' => 1]);
        $def = array_replace($package->definition, $overrides);
        $version = PackageVersion::create(['package_id' => $package->id, 'number' => 1,
            'definition' => $def, 'actor_id' => $this->admin->id]);
        CustomerPackageAssignment::create(['user_id' => $user->id, 'package_id' => $package->id,
            'package_version_id' => $version->id, 'assigned_by' => $this->admin->id,
            'source' => 'account_creation', 'snapshot' => ['id' => $package->id, 'name' => $package->name,
                'code' => $package->code, 'type' => 'paid', 'version' => 1, 'definition' => $def],
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(29), 'allowance_starts_at' => now()->subDay()]);

        return $user;
    }

    public function test_every_admin_job_endpoint_requires_the_separate_admin_session(): void
    {
        $task = MediaTask::factory()->create();
        $paths = ['/admin/operations/jobs', '/admin/operations/workers', '/admin/operations/jobs/'.$task->id,
            '/admin/operations/jobs/'.$task->id.'/files/poster'];
        Auth::guard('admin')->logout();
        foreach ($paths as $path) {
            $this->get($path)->assertRedirect('/admin/login');
        }
        $this->actingAs($this->admin, 'web');
        $this->getJson('/admin/operations/jobs')->assertUnauthorized();
        $this->postJson($this->url($task), $this->action($task, 'cancel'))->assertUnauthorized();
        $this->actingAs(User::factory()->create(['is_admin' => false]), 'admin');
        foreach ($paths as $path) {
            $this->getJson($path)->assertForbidden();
        }
        $this->postJson($this->url($task), $this->action($task, 'cancel'))->assertForbidden();
    }

    public function test_admin_navigation_and_worker_health_stay_inside_the_admin_area(): void
    {
        $this->get('/admin/operations/jobs')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/job-monitor')
            ->where('adminLinks.jobMonitor', '/admin/operations/jobs')
            ->where('adminLinks.workerHealth', '/admin/operations/workers'));
        $this->get('/admin/operations/workers')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/worker-health')->has('health.items', 0));
    }

    public function test_search_counts_filters_and_job_ids_cover_all_customers_without_wildcard_leaks(): void
    {
        $customer = User::factory()->create(['name' => 'Customer One', 'email' => 'one@example.test']);
        $project = MediaProject::factory()->create(['user_id' => $customer->id, 'title' => 'Recap_100%.mp4']);
        foreach (['queued', 'running', 'stopping', 'completed', 'failed', 'retry_waiting', 'awaiting_review', 'cancelled'] as $status) {
            MediaTask::factory()->for($project, 'project')->create(['type' => 'generate', 'status' => $status]);
        }
        MediaTask::factory()->create();
        $this->getJson('/admin/operations/jobs?q='.urlencode('_100%').'&type=generate&status=running')
            ->assertOk()->assertJsonPath('counts.all', 8)->assertJsonPath('counts.running', 2)
            ->assertJsonPath('counts.failed', 1)->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.customer.email', $customer->email);
        $this->getJson('/admin/operations/jobs?q=one%40example.test')->assertJsonPath('pagination.total', 8);
        $task = MediaTask::firstOrFail();
        $this->getJson('/admin/operations/jobs?q=JOB-'.str_pad((string) $task->id, 7, '0', STR_PAD_LEFT))
            ->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $task->id);
        $this->getJson('/admin/operations/jobs?q=missing')->assertJsonPath('pagination.total', 0);
    }

    public function test_date_boundaries_pagination_and_filter_validation(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 06:00:00', 'UTC'));
        config(['operations.timezone' => 'Asia/Bangkok']);
        foreach (['2026-11-09 16:59:59', '2026-11-09 17:00:00', '2026-11-10 16:59:59', '2026-11-10 17:00:00'] as $at) {
            MediaTask::factory()->create(['updated_at' => $at]);
        }
        $this->getJson('/admin/operations/jobs?date=today')->assertJsonPath('pagination.total', 2);
        $this->getJson('/admin/operations/jobs?date=custom&from=2026-11-10&to=2026-11-10')->assertJsonPath('pagination.total', 2);
        MediaTask::factory()->count(11)->create();
        $this->getJson('/admin/operations/jobs?date=all&sort=longest&page=999')
            ->assertOk()->assertJsonPath('pagination.page', 2)->assertJsonCount(5, 'items');
        foreach (['status=bad', 'type=bad', 'page=0', 'per_page=100', 'q[]=bad', 'date=custom',
            'date=custom&from=2026-11-10&to=2026-11-09', 'sort=bad', 'q='.str_repeat('a', 101)] as $query) {
            $this->getJson('/admin/operations/jobs?'.$query)->assertUnprocessable();
        }
    }

    public function test_detail_exposes_safe_metadata_and_actual_usage_not_provider_payloads(): void
    {
        [$project, $run, $task] = $this->makeRun();
        $run->update(['settings' => [...MediaSettings::DEFAULTS, 'api_key' => 'PRIVATE-TEST-SECRET'],
            'error_code' => 'PRIVATE-TEST-SECRET']);
        $task->update(['history_available' => false, 'stages' => ['PRIVATE-TEST-SECRET' => 'active']]);
        DB::table('media_task_events')->insert(['media_task_id' => $task->id, 'status' => 'failed',
            'stage' => 'PRIVATE-TEST-SECRET', 'error_code' => 'PRIVATE-TEST-SECRET', 'recorded_at' => now()]);
        $response = $this->getJson('/admin/operations/jobs/'.$task->id)->assertOk()
            ->assertJsonPath('customer.id', $project->user_id)->assertJsonPath('links.project', null)
            ->assertJsonPath('historyAvailable', false)->assertJsonPath('errorCode', 'processing_failed')
            ->assertJsonPath('usage.reserved_seconds', 0)->assertJsonPath('stages', []);
        $this->assertStringNotContainsString('PRIVATE-TEST-SECRET', $response->getContent());
        foreach (['source_path', 'source_url', 'provider_revision_id', 'payload'] as $field) {
            $response->assertJsonMissingPath($field);
        }
        $this->assertStringContainsString('/admin/usage-ledger', $response->json('usage.ledger_url'));
    }

    public function test_retry_requires_valid_confirmation_and_reason_then_is_idempotent(): void
    {
        $customer = $this->customer();
        [$project, $run, $task] = $this->makeRun('failed', $customer);
        $input = $this->action($task, 'retry');
        $this->postJson($this->url($task), array_replace($input, ['reason' => '']))
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson($this->url($task), array_replace($input, ['confirm' => false]))->assertUnprocessable();
        $this->postJson($this->url($task), $input)->assertAccepted()->assertJsonPath('job.status', 'queued');
        $this->postJson($this->url($task), $input)->assertAccepted()->assertJsonPath('replayed', true);
        $this->postJson($this->url($task), array_replace($input, ['reason' => 'Different reason']))->assertConflict();
        $this->postJson($this->url($task), $this->action($task, 'retry'))->assertConflict();
        Queue::assertPushed(ProcessMediaRun::class, 1);
        $this->assertDatabaseCount('media_task_actions', 1);
        $this->assertDatabaseHas('media_task_actions', ['actor_id' => $this->admin->id, 'reason' => $input['reason']]);
        $this->assertDatabaseCount('usage_records', 1);
        $record = UsageRecord::firstOrFail();
        $this->assertSame(60, $record->reserved_seconds);
        $this->assertSame(0, $record->charged_seconds);
        $this->getJson('/admin/operations/jobs/'.$task->id)->assertJsonPath('usage.reserved_seconds', 60)
            ->assertJsonPath('admin_actions.0.reason', $input['reason']);
    }

    public function test_historical_run_cannot_mutate_a_new_current_run(): void
    {
        [$project, $old, $task] = $this->makeRun();
        $current = MediaRun::factory()->for($project, 'project')->create(['status' => 'failed']);
        $project->update(['active_run_id' => $current->id]);
        $this->getJson('/admin/operations/jobs/'.$task->id)->assertJsonPath('canRetry', false)
            ->assertJsonPath('retryBlocked', 'historical');
        $this->postJson($this->url($task), $this->action($task, 'retry'))->assertConflict();
        $this->assertSame('failed', $current->fresh()->status);
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('media_task_actions', 0);
    }

    public function test_latest_package_quota_and_account_status_are_rechecked_before_retry(): void
    {
        $customer = $this->customer(['included_seconds' => 30]);
        [$project, $run, $task] = $this->makeRun('failed', $customer);
        $this->postJson($this->url($task), $this->action($task, 'retry'))->assertUnprocessable();
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertDatabaseCount('usage_records', 0);
        $customer->forceFill(['account_status' => 'suspended'])->save();
        $this->getJson('/admin/operations/jobs/'.$task->id)->assertJsonPath('canRetry', false)
            ->assertJsonPath('retryBlocked', 'account_unavailable');
        $this->postJson($this->url($task), $this->action($task, 'retry'))->assertConflict();
        Queue::assertNothingPushed();
    }

    public function test_queued_cancel_releases_reservation_without_charging_or_duplicate_actions(): void
    {
        [$project, $run, $task] = $this->makeRun('queued', $this->customer());
        $record = app(UsageLedger::class)->reserve($run);
        $input = $this->action($task, 'cancel');
        $this->postJson($this->url($task), $input)->assertAccepted()->assertJsonPath('job.status', 'cancelled');
        $this->postJson($this->url($task), $input)->assertAccepted()->assertJsonPath('replayed', true);
        $this->assertSame('released', $record->fresh()->status);
        $this->assertSame(0, $record->fresh()->reserved_seconds);
        $this->assertSame(0, $record->fresh()->charged_seconds);
        $this->assertDatabaseCount('media_task_actions', 1);
    }

    public function test_running_cancel_stays_stopping_until_the_worker_acknowledges(): void
    {
        [$project, $run, $task] = $this->makeRun('rendering', $this->customer());
        $record = app(UsageLedger::class)->reserve($run);
        $this->postJson($this->url($task), $this->action($task, 'cancel'))->assertAccepted()
            ->assertJsonPath('job.status', 'stopping')->assertJsonPath('job.canCancel', false);
        $this->assertSame('reserved', $record->fresh()->status);
        $this->assertSame(60, $record->fresh()->reserved_seconds);
        $run->update(['status' => 'cancelled']);
        app(UsageLedger::class)->release($run);
        $this->assertSame('cancelled', $task->fresh()->status);
        $this->assertSame(0, $record->fresh()->reserved_seconds);
    }

    public function test_retry_after_retention_or_source_removal_is_rejected(): void
    {
        [$project, $run, $task] = $this->makeRun();
        $project->update(['source_expired_at' => now()]);
        $this->postJson($this->url($task), $this->action($task, 'retry'))->assertConflict();
        $this->getJson('/admin/operations/jobs/'.$task->id)->assertJsonPath('retryBlocked', 'source_missing');
        Queue::assertNothingPushed();
    }

    public function test_import_retry_reuses_the_job_and_dispatches_once(): void
    {
        $project = MediaProject::factory()->create(['source_type' => 'youtube',
            'source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXc', 'status' => 'import_failed']);
        app(OperationsTelemetry::class)->syncSource($project, true);
        $task = MediaTask::where('media_project_id', $project->id)->firstOrFail();
        $input = $this->action($task, 'retry');
        $this->postJson($this->url($task), $input)->assertAccepted()->assertJsonPath('job.id', $task->id);
        $this->postJson($this->url($task), $input)->assertAccepted()->assertJsonPath('replayed', true);
        $this->assertSame(1, MediaTask::where('media_project_id', $project->id)->where('type', 'import')->count());
        Queue::assertPushed(ImportYouTubeVideo::class, 1);
    }

    public function test_validation_retry_reuses_the_job_and_rechecks_the_source(): void
    {
        $project = MediaProject::factory()->create(['status' => 'validating']);
        Storage::disk('local')->put($project->source_path, 'video');
        $project->update(['status' => 'invalid']);
        $task = MediaTask::where('media_project_id', $project->id)->where('type', 'validation')->firstOrFail();
        $this->postJson($this->url($task), $this->action($task, 'retry'))->assertAccepted()
            ->assertJsonPath('job.id', $task->id)->assertJsonPath('job.status', 'queued');
        $this->assertSame(1, MediaTask::where('media_project_id', $project->id)->count());
        Queue::assertPushed(InspectMediaProject::class, 1);
    }

    public function test_preview_retry_and_cancel_use_real_preview_state_without_an_export_charge(): void
    {
        $project = MediaProject::factory()->create();
        Storage::disk('local')->put($project->source_path, 'video');
        $preview = MediaPreview::factory()->for($project, 'project')->create(['kind' => 'voice', 'status' => 'failed']);
        $task = MediaTask::where('source_key', 'preview:'.$preview->id)->firstOrFail();
        $input = $this->action($task, 'retry');
        $this->postJson($this->url($task), $input)->assertAccepted()->assertJsonPath('job.status', 'queued');
        $this->postJson($this->url($task), $input)->assertAccepted()->assertJsonPath('replayed', true);
        Queue::assertPushed(PrepareMediaPreview::class, 1);
        $preview->update(['status' => 'running']);
        $this->postJson($this->url($task), $this->action($task, 'cancel'))->assertAccepted()
            ->assertJsonPath('job.status', 'stopping');
        $this->assertTrue($preview->fresh()->cancel_requested);
        $this->assertDatabaseCount('usage_records', 0);
    }

    public function test_completed_result_files_are_private_and_never_show_failed_or_deleted_output(): void
    {
        [$project, $run, $task] = $this->makeRun('completed');
        $path = 'media/'.$project->id.'/runs/'.$run->id.'/recap.mp4';
        Storage::disk('local')->put($path, 'video');
        $this->getJson('/admin/operations/jobs/'.$task->id)->assertJsonPath('hasVideo', true)
            ->assertJsonPath('links.video', '/admin/operations/jobs/'.$task->id.'/files/video');
        $response = $this->get('/admin/operations/jobs/'.$task->id.'/files/video?download=1')
            ->assertOk()->assertDownload();
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $run->update(['status' => 'failed']);
        $this->get('/admin/operations/jobs/'.$task->id.'/files/video')->assertNotFound();
        $run->update(['status' => 'completed']);
        DB::table('media_files')->insert(['media_project_id' => $project->id, 'path' => $path, 'bytes' => 5, 'disk' => 'local', 'state' => 'deleted', 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson('/admin/operations/jobs/'.$task->id)->assertJsonPath('hasVideo', false);
        $this->get('/admin/operations/jobs/'.$task->id.'/files/video')->assertNotFound();
    }

    public function test_completed_voice_previews_and_direct_analysis_outputs_can_be_downloaded(): void
    {
        [$project, $run, $task] = $this->makeRun('completed');
        $run->update(['phase' => 'analysis']);
        $analysis = MediaTask::where('media_run_id', $run->id)->where('type', 'analysis')->firstOrFail();
        Storage::disk('local')->put('media/'.$project->id.'/runs/'.$run->id.'/recap.srt', 'subtitles');
        $this->getJson('/admin/operations/jobs/'.$analysis->id)->assertJsonPath('hasSubtitles', true);
        $this->get('/admin/operations/jobs/'.$analysis->id.'/files/subtitles')->assertDownload();
        $preview = MediaPreview::factory()->for($project, 'project')->create(['kind' => 'voice', 'status' => 'completed',
            'path' => 'media/'.$project->id.'/previews/voice.wav']);
        Storage::disk('local')->put($preview->path, 'voice');
        $voiceTask = MediaTask::where('source_key', 'preview:'.$preview->id)->firstOrFail();
        $this->getJson('/admin/operations/jobs/'.$voiceTask->id)->assertJsonPath('hasAudio', true)->assertJsonPath('audioFormat', 'WAV');
        $this->get('/admin/operations/jobs/'.$voiceTask->id.'/files/audio?download=1')->assertDownload();
    }

    public function test_project_deletion_keeps_the_admin_reason_without_blocking_cleanup(): void
    {
        [$project, $run, $task] = $this->makeRun('queued');
        $this->postJson($this->url($task), $this->action($task, 'cancel'))->assertAccepted();
        $project->delete();
        $this->assertDatabaseMissing('media_tasks', ['id' => $task->id]);
        $this->assertDatabaseHas('media_task_actions', ['media_task_id' => null,
            'reason' => 'Administrator checked this job', 'actor_id' => $this->admin->id]);
    }

    public function test_job_usage_keeps_its_recorded_package_after_the_customer_changes_plan(): void
    {
        [$project, $run, $task] = $this->makeRun('completed', $this->customer());
        UsageRecord::factory()->create(['user_id' => $project->user_id, 'media_project_id' => $project->id,
            'media_run_id' => $run->id, 'package_snapshot' => ['name' => 'Original paid plan', 'version' => 2]]);
        $this->getJson('/admin/operations/jobs/'.$task->id)->assertOk()
            ->assertJsonPath('usage.package.name', 'Original paid plan')->assertJsonPath('usage.package.version', 2)
            ->assertJsonPath('usage.charged_seconds', 60);
    }

    public function test_expired_results_are_not_downloadable_even_if_cached_locally(): void
    {
        [$project, $run, $task] = $this->makeRun('completed');
        Storage::disk('local')->put('media/'.$project->id.'/runs/'.$run->id.'/recap.mp4', 'cached output');
        $project->update(['artifacts_expires_at' => now()->subDay()]);
        $this->partialMock(StorageSettings::class,
            fn ($mock) => $mock->shouldReceive('policy')->andReturn(['retention_enabled' => true]));
        $this->getJson('/admin/operations/jobs/'.$task->id)->assertOk()->assertJsonPath('hasVideo', false)
            ->assertJsonPath('resultsExpired', true);
        $this->get('/admin/operations/jobs/'.$task->id.'/files/video')->assertNotFound();
    }
}
