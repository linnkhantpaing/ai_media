<?php

namespace Tests\Feature;

use App\Models\AdminNotice;
use App\Models\ScheduledTaskRun;
use App\Models\User;
use App\Services\AdminNotifications;
use App\Services\AiUsage;
use App\Services\ScheduledTasks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Admin → Operations → Scheduled Tasks: cron heartbeat, a run history for every scheduled
 * task, failure notices, and "Run now" requests that the scheduler picks up.
 */
class ScheduledTasksTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    private function task(array $overview, string $key): array
    {
        return collect($overview['tasks'])->firstWhere('key', $key);
    }

    public function test_the_heartbeat_shows_whether_cron_is_running(): void
    {
        $tasks = app(ScheduledTasks::class);
        $this->assertSame('never', $tasks->scheduler()['state']);
        $tasks->tick();
        $this->assertSame('running', $tasks->scheduler()['state']);
        $this->assertNotNull(Cache::get('media-cleanup-scheduler'));
        $this->assertStringEndsWith('/artisan schedule:run >> /dev/null 2>&1', $tasks->scheduler()['cron_line']);

        $this->travel(10)->minutes();
        $this->assertSame('stopped', $tasks->scheduler()['state']);
        app(AdminNotifications::class)->scheduledTasks();
        $this->assertSame('attention', AdminNotice::where('dedupe_key', 'scheduler:availability')->value('issue'));
        $tasks->tick();
        app(AdminNotifications::class)->scheduledTasks();
        $this->assertSame('resolved', AdminNotice::where('dedupe_key', 'scheduler:availability')->value('issue'));
    }

    public function test_each_run_is_recorded_and_a_failure_is_noticed_until_the_next_good_run(): void
    {
        $tasks = app(ScheduledTasks::class);
        $run = $tasks->run('notifications');
        $this->assertSame('success', $run->status);
        $this->assertSame('schedule', $run->trigger);
        $this->assertNotNull($run->duration_ms);

        $this->mock(AiUsage::class)->shouldReceive('prune')->once()->andThrow(new \RuntimeException('Database is locked'));
        $failed = $tasks->run('ai_usage');
        $this->assertSame('failed', $failed->status);
        $this->assertStringContainsString('Database is locked', $failed->error);
        $notice = AdminNotice::where('dedupe_key', 'schedule:ai_usage')->first();
        $this->assertSame(['attention', 'schedule_failed', 'schedules'], [$notice->issue, $notice->kind, $notice->target_type]);
        $this->assertSame('failed', $this->task($tasks->overview(), 'ai_usage')['state']);
        $this->assertSame(1, $tasks->failing());

        $this->mock(AiUsage::class)->shouldReceive('prune')->once();
        $this->assertSame('success', $tasks->run('ai_usage')->status);
        $this->assertSame('resolved', AdminNotice::where('dedupe_key', 'schedule:ai_usage')->value('issue'));
        $this->assertSame('success', $this->task($tasks->overview(), 'ai_usage')['state']);
    }

    public function test_a_task_already_running_is_not_started_twice_and_an_unfinished_run_is_closed(): void
    {
        $lock = Cache::lock('scheduled-task:usage', 60);
        $lock->get();
        $this->assertNull(app(ScheduledTasks::class)->run('usage'));
        $lock->release();
        $this->assertSame(0, ScheduledTaskRun::count());

        ScheduledTaskRun::create(['task' => 'notifications', 'trigger' => 'schedule', 'status' => 'running', 'started_at' => now()->subHours(2)]);
        $this->assertSame('failed', $this->task(app(ScheduledTasks::class)->overview(), 'notifications')['state']);
        $this->assertSame('failed', ScheduledTaskRun::value('status'));
    }

    public function test_admin_sees_the_page_and_run_now_is_queued_for_the_scheduler(): void
    {
        app(ScheduledTasks::class)->tick();
        $this->actingAs($this->admin, 'admin')->get('/admin/operations/schedules')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/scheduled-tasks')->where('overview.scheduler.state', 'running')
                ->has('overview.tasks', count(ScheduledTasks::TASKS))->where('overview.tasks.0.state', 'never'));

        $this->postJson('/admin/operations/schedules/usage/run', [])->assertUnprocessable();
        $this->postJson('/admin/operations/schedules/usage/run', ['confirm' => true])->assertAccepted()->assertJsonPath('tasks.1.state', 'queued');
        $this->postJson('/admin/operations/schedules/usage/run', ['confirm' => true])->assertUnprocessable();
        $this->postJson('/admin/operations/schedules/unknown/run', ['confirm' => true])->assertNotFound();
        $this->assertTrue(DB::table('audit_events')->where('action', 'operations.task_run_requested')->where('actor_id', $this->admin->id)->exists());

        app(ScheduledTasks::class)->runRequested();
        $run = ScheduledTaskRun::firstOrFail();
        $this->assertSame(['success', 'manual', $this->admin->id], [$run->status, $run->trigger, $run->requested_by]);
        $this->getJson('/admin/operations/schedules/usage/history')->assertOk()->assertJsonPath('items.0.requested_by', $this->admin->name)->assertJsonPath('pagination.total', 1);
        $this->getJson('/admin/operations/schedules/usage/history?status=failed')->assertOk()->assertJsonPath('pagination.total', 0);
        $this->get('/admin/dashboard')->assertInertia(fn (Assert $page) => $page->where('dashboard.system.scheduler.state', 'running'));
    }

    public function test_customers_cannot_open_scheduled_tasks(): void
    {
        $customer = User::factory()->create(['is_admin' => false]);
        $this->actingAs($customer)->get('/admin/operations/schedules')->assertRedirect();
        $this->actingAs($customer)->postJson('/admin/operations/schedules/usage/run', ['confirm' => true])->assertUnauthorized();
    }

    public function test_runs_missed_while_cron_was_down_are_not_late_but_later_misses_are(): void
    {
        $this->travelTo(now()->setTime(10, 0, 30));
        $tasks = app(ScheduledTasks::class);
        $tasks->tick();
        $tasks->run('usage');
        // Cron stops for 20 minutes, then starts again: the missed 10:05–10:20 slots are not "late".
        $this->travelTo(now()->setTime(10, 21, 0));
        $tasks->tick();
        $this->assertSame('success', $this->task($tasks->overview(), 'usage')['state']);
        // Cron keeps running but the 10:25 run never happens: that is late.
        foreach (range(22, 28) as $minute) {
            $this->travelTo(now()->setTime(10, $minute, 0));
            $tasks->tick();
        }
        $this->assertSame('late', $this->task($tasks->overview(), 'usage')['state']);
    }

    public function test_old_history_is_pruned_after_the_keep_period(): void
    {
        ScheduledTaskRun::create(['task' => 'usage', 'trigger' => 'schedule', 'status' => 'success', 'created_at' => now()->subDays(ScheduledTasks::KEEP_DAYS + 1)]);
        ScheduledTaskRun::create(['task' => 'usage', 'trigger' => 'schedule', 'status' => 'success']);
        app(ScheduledTasks::class)->tick();
        $this->assertSame(1, ScheduledTaskRun::count());
    }
}
