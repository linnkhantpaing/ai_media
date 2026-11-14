<?php

namespace Tests\Feature;

use App\Models\MediaProject;
use App\Models\MediaTask;
use App\Models\MediaWorker;
use App\Models\User;
use App\Services\Media\AutomaticMediaWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminWorkerHealthTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->freezeTime();
        config(['queue.default' => 'database', 'operations.auto_launch_allowed' => false]);
        Queue::fake();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($this->admin, 'admin');
        $this->automatic();
    }

    private function automatic(array $overrides = []): void
    {
        $status = array_replace(['enabled' => true, 'supported' => true, 'state' => 'standby', 'idleSeconds' => 30, 'errorCode' => null], $overrides);
        $this->partialMock(AutomaticMediaWorker::class, fn ($mock) => $mock->shouldReceive('status')->andReturn($status));
    }

    private function queued(string $queue, ?MediaTask $task = null, int $delay = 0, bool $reserved = false): void
    {
        DB::table('jobs')->insert(['queue' => $queue, 'payload' => json_encode(['media_task_id' => $task?->id,
            'data' => ['command' => 'PRIVATE-serialized-command-must-not-be-exposed']]), 'attempts' => 0,
            'reserved_at' => $reserved ? now()->timestamp : null, 'available_at' => now()->timestamp + $delay,
            'created_at' => now()->timestamp]);
    }

    public function test_worker_pages_and_details_require_the_admin_guard_and_authorization(): void
    {
        $worker = MediaWorker::factory()->create();
        Auth::guard('admin')->logout();
        $this->get('/admin/operations/workers')->assertRedirect('/admin/login');
        $this->getJson('/admin/operations/workers/'.$worker->id)->assertUnauthorized();
        $this->actingAs($this->admin, 'web')->getJson('/admin/operations/workers')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['is_admin' => false]), 'admin');
        $this->getJson('/admin/operations/workers')->assertForbidden();
        $this->getJson('/admin/operations/workers/'.$worker->id)->assertForbidden();
    }

    public function test_empty_automatic_standby_is_healthy_without_invented_worker_rows(): void
    {
        $this->get('/admin/operations/workers')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/worker-health')->where('health.processingState', 'standby')
            ->where('health.counts.online', 0)->where('health.counts.attention', 0)->has('health.items', 0)
            ->where('adminLinks.workerHealth', '/admin/operations/workers'));
        $this->getJson('/admin/operations/workers')->assertOk()->assertJsonPath('automatic.enabled', true)
            ->assertJsonPath('pagination.total', 0)->assertJsonPath('queueReported', true)
            ->assertHeader('Cache-Control', 'no-store, private');
        Queue::assertNothingPushed();
    }

    public function test_counts_cover_all_current_instances_and_busy_requires_an_active_task(): void
    {
        $busy = MediaTask::factory()->create(['status' => 'running', 'progress' => 72, 'stage' => 'render']);
        MediaWorker::factory()->create(['current_task_id' => $busy->id]);
        $finished = MediaTask::factory()->create(['status' => 'completed']);
        MediaWorker::factory()->create(['current_task_id' => $finished->id]);
        MediaWorker::factory()->count(10)->create();
        MediaWorker::factory()->create(['heartbeat_at' => now()->subSeconds(46)]);
        MediaWorker::factory()->create(['heartbeat_at' => null]);
        MediaWorker::factory()->create(['stopped_at' => now()]);
        $this->getJson('/admin/operations/workers')->assertOk()->assertJsonPath('counts.online', 12)
            ->assertJsonPath('counts.busy', 1)->assertJsonPath('counts.idle', 11)->assertJsonPath('counts.attention', 2)
            ->assertJsonPath('pagination.total', 15)->assertJsonCount(10, 'items')->assertJsonPath('historyTotal', 1)
            ->assertJsonPath('processingState', 'processing');
        $worker = MediaWorker::where('current_task_id', $finished->id)->firstOrFail();
        $this->getJson('/admin/operations/workers/'.$worker->id)->assertJsonPath('state', 'idle')
            ->assertJsonPath('usedSlots', 0)->assertJsonPath('jobCurrent', false);
    }

    public function test_filters_search_literal_wildcards_and_page_clamping_work(): void
    {
        MediaWorker::factory()->create(['name' => 'Worker_100%', 'queues' => ['media']]);
        MediaWorker::factory()->create(['queues' => ['media-inspect']]);
        MediaWorker::factory()->count(10)->create();
        $this->getJson('/admin/operations/workers?q='.urlencode('_100%').'&type=processing&status=idle')
            ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('items.0.name', 'Worker_100%')
            ->assertJsonPath('items.0.type', 'processing');
        $this->getJson('/admin/operations/workers?type=preparation')->assertJsonPath('pagination.total', 1);
        $this->getJson('/admin/operations/workers?type=mixed&page=999')->assertJsonPath('pagination.page', 1)
            ->assertJsonCount(10, 'items');
        $this->getJson('/admin/operations/workers?page=999')->assertJsonPath('pagination.page', 2)->assertJsonCount(2, 'items');
        foreach (['status=bad', 'type=bad', 'page=0', 'per_page=100', 'q[]=bad', 'history=invalid', 'scope=invalid', 'q='.str_repeat('a', 101)] as $filter) {
            $this->getJson('/admin/operations/workers?'.$filter)->assertUnprocessable();
        }
    }

    public function test_stopped_history_is_separate_and_does_not_inflate_online_counts(): void
    {
        $clean = MediaWorker::factory()->create(['stopped_at' => now(), 'created_at' => now()->subHour()]);
        $issue = MediaWorker::factory()->create(['stopped_at' => now(), 'error_code' => 'PRIVATE-API-TOKEN']);
        $this->getJson('/admin/operations/workers')->assertJsonCount(2, 'items')->assertJsonPath('filters.scope', 'all')->assertJsonPath('counts.attention', 0)
            ->assertJsonPath('historyTotal', 2)->assertJsonPath('processingState', 'standby');
        $this->getJson('/admin/operations/workers?scope=current')->assertJsonCount(0, 'items');
        $this->getJson('/admin/operations/workers?history=1&status=stopped')->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $clean->id)->assertJsonPath('items.0.state', 'stopped')
            ->assertJsonPath('items.0.uptimeSeconds', 3600)->assertJsonPath('items.0.uptimeCurrent', false);
        $response = $this->getJson('/admin/operations/workers?history=1&status=issue')->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $issue->id)->assertJsonPath('items.0.errorCode', 'worker_unavailable');
        $this->assertStringNotContainsString('PRIVATE-API-TOKEN', $response->getContent());
    }

    public function test_stale_uptime_stops_at_the_last_heartbeat_and_job_is_not_claimed_current(): void
    {
        $task = MediaTask::factory()->create(['status' => 'running', 'progress' => 42]);
        $worker = MediaWorker::factory()->create(['current_task_id' => $task->id,
            'created_at' => now()->subHours(2), 'heartbeat_at' => now()->subHour()]);
        $this->getJson('/admin/operations/workers/'.$worker->id)->assertOk()->assertJsonPath('state', 'stale')
            ->assertJsonPath('uptimeSeconds', 3600)->assertJsonPath('heartbeatSeconds', 3600)
            ->assertJsonPath('usedSlots', null)->assertJsonPath('jobCurrent', false)->assertJsonPath('job.progress', 42);
    }

    public function test_queue_totals_use_actual_payloads_and_ignore_terminal_tasks(): void
    {
        $task = MediaTask::factory()->create();
        $done = MediaTask::factory()->create(['status' => 'completed']);
        $this->queued('media-inspect');
        $this->queued('media', $task);
        $this->queued('media', $task, 60);
        $this->queued('media', $task, 0, true);
        $this->queued('media', $done);
        $response = $this->getJson('/admin/operations/workers')->assertOk()->assertJsonPath('queues.0.ready', 1)
            ->assertJsonPath('queues.1.ready', 1)->assertJsonPath('queues.1.delayed', 1)->assertJsonPath('queues.1.reserved', 1)
            ->assertJsonPath('processingState', 'waiting');
        $this->assertStringNotContainsString('PRIVATE-serialized-command', $response->getContent());
        $this->assertDatabaseCount('jobs', 5);
        $this->assertSame('queued', $task->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_processing_mode_startup_failures_and_overdue_waiting_are_reported(): void
    {
        $this->automatic(['state' => 'starting']);
        $this->getJson('/admin/operations/workers')->assertJsonPath('processingState', 'starting');
        $this->automatic(['enabled' => false, 'state' => 'manual']);
        $this->getJson('/admin/operations/workers')->assertJsonPath('processingState', 'manual');
        $this->automatic(['state' => 'failed', 'errorCode' => 'PRIVATE-process-stack']);
        $response = $this->getJson('/admin/operations/workers')->assertJsonPath('processingState', 'attention')
            ->assertJsonPath('automatic.errorCode', 'worker_unavailable');
        $this->assertStringNotContainsString('PRIVATE-process-stack', $response->getContent());
        $this->automatic();
        $this->queued('media', null, -120);
        $this->getJson('/admin/operations/workers')->assertJsonPath('processingState', 'attention');
    }

    public function test_unsupported_queue_statistics_are_unknown_not_reported_as_empty(): void
    {
        config(['queue.default' => 'redis']);
        $this->getJson('/admin/operations/workers')->assertOk()->assertJsonPath('queueReported', false);
    }

    public function test_details_only_show_recorded_milestones_safe_jobs_and_admin_links(): void
    {
        $worker = MediaWorker::factory()->create(['queues' => ['media', 'PRIVATE-unconfigured-queue']]);
        $project = MediaProject::factory()->create(['title' => 'Customer recap']);
        $task = MediaTask::factory()->for($project, 'project')->create(['media_worker_id' => $worker->id,
            'status' => 'completed', 'stage' => 'PRIVATE-provider-key', 'error_code' => 'PRIVATE-provider-key']);
        $response = $this->getJson('/admin/operations/workers/'.$worker->id)->assertOk()
            ->assertJsonPath('queues', ['media'])->assertJsonPath('job.id', $task->id)
            ->assertJsonPath('jobCurrent', false)->assertJsonPath('recentJobs.0.stage', null)
            ->assertJsonCount(2, 'events')->assertJsonPath('events.0.type', 'registered')
            ->assertJsonPath('events.1.type', 'heartbeat')->assertJsonMissingPath('cpu')->assertJsonMissingPath('gpu')
            ->assertJsonMissingPath('process_id')->assertJsonMissingPath('source_path');
        $this->assertStringNotContainsString('PRIVATE-', $response->getContent());
        $this->assertStringContainsString('/admin/operations/jobs?', $response->json('links.job'));
        $this->getJson('/admin/operations/workers/not-a-uuid')->assertNotFound();
    }

    public function test_default_list_includes_current_and_stopped_workers_with_busy_instances_first(): void
    {
        $stopped = MediaWorker::factory()->create(['stopped_at' => now(), 'heartbeat_at' => now()]);
        $task = MediaTask::factory()->create(['status' => 'running']);
        $busy = MediaWorker::factory()->create(['current_task_id' => $task->id, 'heartbeat_at' => now()->subSeconds(5)]);
        MediaWorker::factory()->create(['heartbeat_at' => now()]);
        $this->getJson('/admin/operations/workers')->assertOk()->assertJsonPath('filters.scope', 'all')
            ->assertJsonCount(3, 'items')->assertJsonPath('items.0.id', $busy->id)
            ->assertJsonPath('items.2.id', $stopped->id)->assertJsonPath('counts.online', 2);
        $this->getJson('/admin/operations/workers?scope=current')->assertJsonCount(2, 'items');
        $this->getJson('/admin/operations/workers?scope=history')->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $stopped->id);
        $this->getJson('/admin/operations/workers?history=0')->assertJsonCount(2, 'items');
    }
}
