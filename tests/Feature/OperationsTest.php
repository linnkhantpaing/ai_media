<?php

namespace Tests\Feature;

use App\Jobs\ImportYouTubeVideo;
use App\Jobs\ProcessMediaRun;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Models\MediaTask;
use App\Models\MediaWorker;
use App\Models\User;
use App\Services\Media\MediaRuntime;
use App\Services\Media\OperationsTelemetry;
use App\Support\MediaSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Queue::fake();
        config(['filesystems.media_disk' => 'local', 'media.gemini_key' => 'test-only-key', 'queue.default' => 'database']);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    public function test_operations_pages_details_actions_and_media_require_an_administrator(): void
    {
        $task = MediaTask::factory()->create();
        $paths = ['/operations/jobs', '/operations/workers', '/operations/jobs/'.$task->id, '/operations/jobs/'.$task->id.'/files/poster'];
        Auth::logout();
        foreach ($paths as $path) {
            $this->get($path)->assertRedirect('/login');
        }
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        foreach ($paths as $path) {
            $this->get($path)->assertForbidden();
        }
        $this->postJson('/operations/jobs/'.$task->id.'/action', ['action' => 'cancel', 'confirm' => true])->assertForbidden();
    }

    public function test_filters_and_status_counts_include_only_matching_real_tasks(): void
    {
        $project = MediaProject::factory()->create(['title' => 'Recap_100%.mp4']);
        foreach (['queued', 'running', 'stopping', 'completed', 'failed', 'retry_waiting', 'awaiting_review'] as $status) {
            MediaTask::factory()->for($project, 'project')->create(['type' => 'generate', 'mode' => 'dub', 'status' => $status]);
        }
        MediaTask::factory()->create(['type' => 'analysis', 'mode' => 'subs']);
        $this->get('/operations/jobs?q='.urlencode('_100%').'&type=generate&mode=dub&status=running')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('operations/jobs')->where('counts.all', 7)->where('counts.running', 2)->where('counts.queued', 1)
            ->where('counts.completed', 1)->where('counts.failed', 1)->where('counts.retry_waiting', 1)->where('counts.awaiting_review', 1)
            ->where('jobs.total', 2)->has('jobs.data', 2)->where('filters.mode', 'dub'));
        $task = MediaTask::firstOrFail();
        $this->get('/operations/jobs?q=J-'.str_pad((string) $task->id, 5, '0', STR_PAD_LEFT))->assertInertia(fn (Assert $page) => $page->has('jobs.data', 1)->where('jobs.data.0.id', $task->id));
        $this->get('/operations/jobs?q=missing')->assertInertia(fn (Assert $page) => $page->where('jobs.total', 0));
    }

    public function test_date_boundaries_sorting_pagination_and_invalid_input(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 06:00:00', 'UTC'));
        foreach (['2026-11-09 16:59:59', '2026-11-09 17:00:00', '2026-11-10 16:59:59', '2026-11-10 17:00:00'] as $at) {
            MediaTask::factory()->create(['updated_at' => $at]);
        }
        $this->get('/operations/jobs?date=today')->assertInertia(fn (Assert $page) => $page->where('jobs.total', 2));
        $this->get('/operations/jobs?date=custom&from=2026-11-10&to=2026-11-10')->assertInertia(fn (Assert $page) => $page->where('jobs.total', 2));
        MediaTask::factory()->count(11)->create();
        $this->get('/operations/jobs?view=cards&sort=oldest&page=999')->assertInertia(fn (Assert $page) => $page
            ->where('jobs.current_page', 2)->where('jobs.last_page', 2)->where('jobs.total', 15)->has('jobs.data', 3)->where('filters.view', 'cards'));
        foreach (['status=bad', 'type=unsupported', 'mode=invalid', 'view=invalid', 'page=0', 'q[]=bad', 'date=bad', 'date=custom&from=2026-11-10&to=2026-11-09'] as $query) {
            $this->getJson('/operations/jobs?'.$query)->assertUnprocessable();
        }
        $this->getJson('/operations/jobs?q='.str_repeat('a', 251))->assertUnprocessable();
    }

    public function test_task_detail_does_not_expose_private_payloads_secrets_or_invent_history(): void
    {
        $task = MediaTask::factory()->create(['history_available' => false, 'error_code' => 'SECRET-provider-credential', 'stages' => ['private' => 'SECRET-provider-credential']]);
        DB::table('media_task_events')->insert(['media_task_id' => $task->id, 'status' => 'failed', 'stage' => 'SECRET-provider-credential', 'error_code' => 'SECRET-provider-credential', 'recorded_at' => now()]);
        $response = $this->getJson('/operations/jobs/'.$task->id)->assertOk()
            ->assertJsonPath('job.historyAvailable', false)->assertJsonPath('job.attempt', null)
            ->assertJsonPath('job.elapsedSeconds', null)->assertJsonPath('job.errorCode', 'processing_failed')
            ->assertJsonPath('job.stages', [])->assertJsonPath('job.events.0.stage', null)->assertJsonPath('job.events.0.errorCode', 'processing_failed');
        $this->assertStringNotContainsString('SECRET-provider-credential', $response->getContent());
        foreach (['payload', 'sourceUrl', 'source_path', 'settings', 'lines', 'gemini_key', 'secret'] as $field) {
            $response->assertJsonMissingPath('job.'.$field);
        }
    }

    public function test_retry_is_confirmed_current_only_and_prevents_duplicate_enqueue(): void
    {
        $this->mock(MediaRuntime::class)->shouldReceive('available')->andReturn(true);
        $project = MediaProject::factory()->create(['user_id' => auth()->id(), 'status' => 'failed']);
        $run = MediaRun::factory()->for($project, 'project')->create(['status' => 'failed', 'error_code' => 'provider_unavailable']);
        $project->update(['active_run_id' => $run->id]);
        $task = MediaTask::where('media_run_id', $run->id)->firstOrFail();
        $url = '/operations/jobs/'.$task->id.'/action';
        $this->postJson($url, ['action' => 'retry'])->assertUnprocessable();
        $this->postJson($url, ['action' => 'retry', 'confirm' => true])->assertAccepted()->assertJsonPath('job.status', 'queued');
        $this->postJson($url, ['action' => 'retry', 'confirm' => true])->assertConflict();
        Queue::assertPushed(ProcessMediaRun::class, 1);
        $this->assertSame('queued', $run->fresh()->status);
    }

    public function test_historical_run_and_previous_phase_cannot_change_the_current_run(): void
    {
        $project = MediaProject::factory()->create(['user_id' => auth()->id(), 'status' => 'failed']);
        $old = MediaRun::factory()->for($project, 'project')->create(['status' => 'failed']);
        $task = MediaTask::where('media_run_id', $old->id)->firstOrFail();
        $new = MediaRun::factory()->for($project, 'project')->create(['status' => 'failed']);
        $project->update(['active_run_id' => $new->id]);
        $this->postJson('/operations/jobs/'.$task->id.'/action', ['action' => 'retry', 'confirm' => true])->assertConflict();
        $project->update(['active_run_id' => $old->id]);
        $old->update(['phase' => 'generate']);
        $this->postJson('/operations/jobs/'.$task->id.'/action', ['action' => 'retry', 'confirm' => true])->assertConflict();
        Queue::assertNothingPushed();
    }

    public function test_active_cancel_waits_for_worker_acknowledgement_and_queued_cancel_is_immediate(): void
    {
        $project = MediaProject::factory()->create(['user_id' => auth()->id(), 'status' => 'rendering']);
        $run = MediaRun::factory()->for($project, 'project')->create(['phase' => 'generate', 'status' => 'rendering']);
        $project->update(['active_run_id' => $run->id]);
        $task = MediaTask::where('media_run_id', $run->id)->firstOrFail();
        $url = '/operations/jobs/'.$task->id.'/action';
        $this->postJson($url, ['action' => 'cancel', 'confirm' => true])->assertAccepted()->assertJsonPath('job.status', 'stopping');
        $this->postJson($url, ['action' => 'cancel', 'confirm' => true])->assertConflict();
        $run->update(['status' => 'cancelled']);
        $this->assertSame('cancelled', $task->fresh()->status);
        $queued = MediaRun::factory()->for($project, 'project')->create();
        $project->update(['active_run_id' => $queued->id, 'status' => 'queued']);
        $next = MediaTask::where('media_run_id', $queued->id)->firstOrFail();
        $this->postJson('/operations/jobs/'.$next->id.'/action', ['action' => 'cancel', 'confirm' => true])->assertAccepted()->assertJsonPath('job.status', 'cancelled');
    }

    public function test_import_retry_creates_a_new_source_task_and_rejects_old_retries(): void
    {
        $project = MediaProject::factory()->create(['source_type' => 'youtube', 'source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXc', 'status' => 'import_failed']);
        app(OperationsTelemetry::class)->syncSource($project, true);
        $task = MediaTask::where('media_project_id', $project->id)->firstOrFail();
        $url = '/operations/jobs/'.$task->id.'/action';
        $this->postJson($url, ['action' => 'retry', 'confirm' => true])->assertAccepted();
        $this->assertNotSame($task->id, MediaTask::where('media_project_id', $project->id)->latest('id')->value('id'));
        $this->postJson($url, ['action' => 'retry', 'confirm' => true])->assertConflict();
        Queue::assertPushed(ImportYouTubeVideo::class, 1);
    }

    public function test_worker_health_distinguishes_unknown_fresh_stale_and_stopped_states(): void
    {
        $this->get('/operations/workers')->assertInertia(fn (Assert $page) => $page->component('operations/workers')->has('workers', 0)->where('queueReported', true));
        $worker = MediaWorker::factory()->create();
        $this->get('/operations/workers')->assertInertia(fn (Assert $page) => $page->where('workers.0.state', 'idle')->where('workers.0.usedSlots', 0)->missing('workers.0.gpu'));
        $task = MediaTask::factory()->create(['status' => 'running']);
        $worker->update(['current_task_id' => $task->id]);
        $this->get('/operations/workers')->assertInertia(fn (Assert $page) => $page->where('workers.0.state', 'busy')->where('workers.0.usedSlots', 1));
        $this->travel(46)->seconds();
        $this->get('/operations/workers')->assertInertia(fn (Assert $page) => $page->where('workers.0.state', 'stale')->where('workers.0.usedSlots', null));
        $worker->update(['stopped_at' => now(), 'current_task_id' => null]);
        $this->get('/operations/workers')->assertInertia(fn (Assert $page) => $page->where('workers.0.state', 'stopped')->where('workers.0.currentJob', null));
    }

    public function test_only_published_generation_files_are_offered_and_admin_files_remain_private(): void
    {
        $project = MediaProject::factory()->create();
        $run = MediaRun::factory()->for($project, 'project')->create(['phase' => 'generate', 'status' => 'completed', 'settings' => [...MediaSettings::DEFAULTS, 'output' => 'srt']]);
        $task = MediaTask::where('media_run_id', $run->id)->firstOrFail();
        Storage::disk('local')->put('media/'.$project->id.'/runs/'.$run->id.'/recap.srt', "1\n00:00:00,000 --> 00:00:01,000\nHello\n");
        $this->getJson('/operations/jobs/'.$task->id)->assertJsonPath('job.hasVideo', false)->assertJsonPath('job.hasSubtitles', true)->assertJsonPath('job.links.project', null);
        $this->get('/operations/jobs/'.$task->id.'/files/video')->assertNotFound();
        $response = $this->get('/operations/jobs/'.$task->id.'/files/subtitles')->assertOk()->assertDownload();
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
    }
}
