<?php

namespace Tests\Feature;

use App\Jobs\InspectMediaProject;
use App\Jobs\ProcessMediaRun;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Models\MediaTask;
use App\Models\MediaWorker;
use App\Services\Media\OperationsTelemetry;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OperationsTelemetryTest extends TestCase
{
    use RefreshDatabase;

    private string|false $previousWorker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->previousWorker = getenv('MEDIA_WORKER_ID');
        putenv('MEDIA_WORKER_ID');
        config(['filesystems.media_disk' => 'local']);
    }

    protected function tearDown(): void
    {
        putenv($this->previousWorker === false ? 'MEDIA_WORKER_ID' : 'MEDIA_WORKER_ID='.$this->previousWorker);
        parent::tearDown();
    }

    private function queueJob(MediaTask $task, int $attempt = 1): Job
    {
        $job = \Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn(['media_task_id' => $task->id]);
        $job->shouldReceive('attempts')->andReturn($attempt);

        return $job;
    }

    public function test_real_database_queue_payload_links_to_a_durable_task_without_unserializing_commands(): void
    {
        $run = MediaRun::factory()->create();
        $id = Queue::connection('database')->push(new ProcessMediaRun($run->id));
        $payload = json_decode(DB::table('jobs')->where('id', $id)->value('payload'), true);
        $task = MediaTask::findOrFail($payload['media_task_id']);
        $this->assertSame($run->id, $task->media_run_id);
        $this->assertNotNull($task->queued_at);
        $this->assertNotNull($task->ready_at);
        $this->assertNull($task->attempts);
    }

    public function test_recorded_processing_time_excludes_retry_delay_and_does_not_double_count(): void
    {
        $worker = MediaWorker::factory()->create();
        putenv('MEDIA_WORKER_ID='.$worker->id);
        $run = MediaRun::factory()->create();
        $telemetry = app(OperationsTelemetry::class);
        $payload = $telemetry->payload(new ProcessMediaRun($run->id));
        $task = MediaTask::findOrFail($payload['media_task_id']);
        $this->travel(3)->seconds();
        $telemetry->started($this->queueJob($task));
        $this->assertSame($task->id, $worker->fresh()->current_task_id);
        $run->update(['status' => 'analyzing']);
        $this->travel(10)->seconds();
        $run->update(['status' => 'retry_waiting', 'error_code' => 'provider_rate_limit']);
        $telemetry->retryScheduled($run, 45);
        $telemetry->processed($this->queueJob($task));
        $this->assertEqualsWithDelta(10, $task->fresh()->processing_seconds, 0.1);
        $this->assertNotNull($task->fresh()->next_retry_at);
        $this->assertNull($worker->fresh()->current_task_id);
        $this->travel(45)->seconds();
        $telemetry->started($this->queueJob($task, 2));
        $run->update(['status' => 'analyzing']);
        $this->travel(4)->seconds();
        $run->update(['status' => 'completed', 'finished_at' => now()]);
        $telemetry->processed($this->queueJob($task, 2));
        $task->refresh();
        $this->assertEqualsWithDelta(14, $task->processing_seconds, 0.1);
        $this->assertEqualsWithDelta(3, $task->queue_wait_seconds, 0.1);
        $this->assertSame(2, $task->attempts);
        $this->assertNull($task->next_retry_at);
    }

    public function test_analysis_and_generation_have_distinct_tasks_and_review_wait_uses_no_worker_slot(): void
    {
        $worker = MediaWorker::factory()->create();
        putenv('MEDIA_WORKER_ID='.$worker->id);
        $telemetry = app(OperationsTelemetry::class);
        $run = MediaRun::factory()->create();
        $task = MediaTask::where('media_run_id', $run->id)->firstOrFail();
        $telemetry->started($this->queueJob($task));
        $run->update(['status' => 'analyzing']);
        $this->travel(8)->seconds();
        $run->update(['status' => 'awaiting_review', 'progress' => 100]);
        $telemetry->processed($this->queueJob($task));
        $this->assertNull($worker->fresh()->current_task_id);
        $this->travel(1)->hours();
        $run->update(['phase' => 'generate', 'status' => 'queued', 'progress' => 0]);
        $generation = MediaTask::where('source_key', 'run:'.$run->id.':generate')->firstOrFail();
        $this->assertNotSame($task->id, $generation->id);
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertNull($generation->started_at);
        $telemetry->started($this->queueJob($generation));
        $run->update(['status' => 'rendering']);
        $this->travel(5)->seconds();
        $run->update(['status' => 'completed']);
        $telemetry->processed($this->queueJob($generation));
        $this->assertEqualsWithDelta(8, $task->fresh()->processing_seconds, 0.1);
        $this->assertEqualsWithDelta(5, $generation->fresh()->processing_seconds, 0.1);
    }

    public function test_source_import_and_validation_are_recorded_separately_even_with_bulk_updates(): void
    {
        $project = MediaProject::factory()->create(['source_type' => 'youtube', 'status' => 'importing', 'import_stage' => 'queued']);
        $telemetry = app(OperationsTelemetry::class);
        $import = MediaTask::where('media_project_id', $project->id)->where('type', 'import')->firstOrFail();
        $telemetry->started($this->queueJob($import));
        $project->update(['import_stage' => 'checking']);
        $this->assertSame('running', $import->fresh()->status);
        $project->update(['status' => 'validating']);
        $this->assertSame('completed', $import->fresh()->status);
        $validation = MediaTask::where('media_project_id', $project->id)->where('type', 'validation')->firstOrFail();
        $telemetry->payload(new InspectMediaProject($project->id));
        $telemetry->started($this->queueJob($validation));
        MediaProject::whereKey($project->id)->update(['status' => 'ready']);
        $telemetry->processed($this->queueJob($validation));
        $this->assertSame('completed', $validation->fresh()->status);
        $this->assertSame(100, $validation->fresh()->progress);
        $this->assertGreaterThan(0, DB::table('media_task_events')->where('media_task_id', $validation->id)->count());
    }

    public function test_backfill_preserves_unknown_attempts_times_and_event_history(): void
    {
        $run = MediaRun::withoutEvents(fn () => MediaRun::factory()->create(['status' => 'awaiting_review']));
        $task = app(OperationsTelemetry::class)->syncRun($run, true);
        $this->assertFalse($task->history_available);
        $this->assertNull($task->attempts);
        $this->assertNull($task->started_at);
        $this->assertNull($task->finished_at);
        $this->assertNull($task->processing_seconds);
        $this->assertSame(0, DB::table('media_task_events')->where('media_task_id', $task->id)->count());
        $this->artisan('media:operations-sync')->assertSuccessful();
        $this->assertDatabaseCount('media_tasks', 1);
    }

    public function test_queue_failure_uses_the_actual_source_validation_error(): void
    {
        $project = MediaProject::factory()->create(['status' => 'validating']);
        $task = MediaTask::where('media_project_id', $project->id)->firstOrFail();
        $telemetry = app(OperationsTelemetry::class);
        $telemetry->started($this->queueJob($task));
        MediaProject::whereKey($project->id)->update(['status' => 'invalid', 'error_code' => 'inspection_failed']);
        $telemetry->failed($this->queueJob($task));
        $this->assertSame('failed', $task->fresh()->status);
        $this->assertSame('inspection_failed', $task->fresh()->error_code);
        $this->assertNotNull($task->fresh()->finished_at);
    }
}
