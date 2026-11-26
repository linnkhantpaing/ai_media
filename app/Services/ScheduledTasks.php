<?php

namespace App\Services;

use App\Models\MediaWorker;
use App\Models\ScheduledTaskRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The background tasks the Laravel scheduler (cron) runs, with a run history so Admin can see
 * what ran, what failed and ask for a run now. Cron itself is checked through a heartbeat that
 * the scheduler writes every minute. "Run now" requests are queued and picked up by the
 * scheduler within a minute, so a web request never runs a long task itself.
 */
class ScheduledTasks
{
    /** key => command (or callback), cron expression and the longest time one run may take (seconds). */
    public const TASKS = [
        'notifications' => ['command' => 'notifications:sync', 'arguments' => [], 'cron' => '* * * * *', 'timeout' => 300],
        'usage' => ['command' => 'usage:reconcile', 'arguments' => [], 'cron' => '*/5 * * * *', 'timeout' => 900],
        'cleanup' => ['command' => 'media:cleanup', 'arguments' => ['--scheduled' => true], 'cron' => '0 * * * *', 'timeout' => 3600],
        'ai_usage' => ['command' => null, 'arguments' => [], 'cron' => '15 3 * * *', 'timeout' => 1800],
    ];

    public const CRON_LINE = '* * * * * cd {path} && php artisan schedule:run >> /dev/null 2>&1';

    public const KEEP_DAYS = 30;

    /** Cron is treated as stopped when no heartbeat arrived for this long. */
    private const STALE_SECONDS = 180;

    public function timezone(): string
    {
        return (string) (config('app.schedule_timezone') ?: config('app.timezone') ?: 'UTC');
    }

    /** Written by the scheduler every minute. */
    public function tick(): void
    {
        $now = now()->toIso8601String();
        // Older key read by Storage & Retention ("automatic cleanup is set up").
        Cache::put('media-cleanup-scheduler', $now, self::STALE_SECONDS);
        Cache::forever('scheduler:last-tick', $now);
        Cache::add('scheduler:first-tick', $now, now()->addYears(5));
        if (Cache::add('scheduler:history-pruned', true, 86400)) {
            ScheduledTaskRun::where('created_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
        }
    }

    public function scheduler(): array
    {
        $last = Cache::get('scheduler:last-tick');
        $seconds = $last ? max(0, (int) CarbonImmutable::parse($last)->diffInSeconds(now())) : null;

        return ['state' => $last === null ? 'never' : ($seconds <= self::STALE_SECONDS ? 'running' : 'stopped'),
            'last_tick' => $last, 'seconds_ago' => $seconds, 'cron_line' => str_replace('{path}', base_path(), self::CRON_LINE)];
    }

    /** Runs one task now (called by the scheduler). A task that is already running is not started twice. */
    public function run(string $key, string $trigger = 'schedule', ?ScheduledTaskRun $request = null): ?ScheduledTaskRun
    {
        $task = self::TASKS[$key] ?? null;
        if (! $task) {
            return null;
        }
        $lock = Cache::lock('scheduled-task:'.$key, $task['timeout']);
        if (! $lock->get()) {
            return null;
        }
        $started = microtime(true);
        try {
            $run = $request ?? new ScheduledTaskRun(['task' => $key, 'trigger' => $trigger]);
            $run->forceFill(['status' => 'running', 'started_at' => now(), 'finished_at' => null, 'error' => null])->save();
            $status = 'success';
            $output = '';
            $error = null;
            try {
                if ($task['command'] === null) {
                    app(AiUsage::class)->prune();
                } else {
                    $code = Artisan::call($task['command'], $task['arguments']);
                    $output = Artisan::output();
                    if ($code !== 0) {
                        $status = 'failed';
                        $error = 'The command ended with exit code '.$code.'.';
                    }
                }
            } catch (\Throwable $exception) {
                report($exception);
                $status = 'failed';
                $error = Str::limit(class_basename($exception).': '.$exception->getMessage(), 1500);
            }
            $run->forceFill(['status' => $status, 'finished_at' => now(), 'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'output' => trim($output) === '' ? null : Str::limit(trim($output), 2000), 'error' => $error])->save();
        } finally {
            $lock->release();
        }
        try {
            app(AdminNotifications::class)->scheduledTasks();
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $run;
    }

    /** Picks up "Run now" requests from Admin (called by the scheduler every minute). */
    public function runRequested(): void
    {
        foreach (ScheduledTaskRun::where('status', 'queued')->orderBy('id')->limit(10)->get() as $request) {
            $this->run($request->task, 'manual', $request);
        }
    }

    public function request(string $key, User $admin): ScheduledTaskRun
    {
        abort_unless(isset(self::TASKS[$key]), 404);
        $this->closeInterrupted();

        return DB::transaction(function () use ($key, $admin): ScheduledTaskRun {
            if (ScheduledTaskRun::where('task', $key)->whereIn('status', ['queued', 'running'])->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['task' => 'This task is already waiting or running.']);
            }
            $run = ScheduledTaskRun::create(['task' => $key, 'trigger' => 'manual', 'status' => 'queued', 'requested_by' => $admin->id]);
            app(AuditTrail::class)->record('scheduled_task', (string) $run->id, 'operations', 'operations.task_run_requested', 'success', $admin->id,
                'scheduled_task', $key, 'Scheduled Tasks', ['task' => $key]);

            return $run;
        });
    }

    /** A run that never finished (server restart, killed process) is closed as failed after its time limit. */
    public function closeInterrupted(): void
    {
        foreach (self::TASKS as $key => $task) {
            ScheduledTaskRun::where('task', $key)->where('status', 'running')->where('started_at', '<', now()->subSeconds($task['timeout'] + 60))
                ->update(['status' => 'failed', 'finished_at' => now(), 'error' => 'The run stopped before it finished (the server or process was restarted).', 'updated_at' => now()]);
        }
    }

    public function overview(): array
    {
        $this->closeInterrupted();
        $scheduler = $this->scheduler();
        $zone = $this->timezone();
        $now = CarbonImmutable::now($zone);
        $firstTick = Cache::get('scheduler:first-tick');
        $since = now()->subDay();
        $tasks = [];
        foreach (self::TASKS as $key => $task) {
            $cron = new CronExpression($task['cron']);
            $previousDue = CarbonImmutable::instance($cron->getPreviousRunDate($now->subMinutes(2), 0, true));
            $latest = ScheduledTaskRun::where('task', $key)->latest('id')->first();
            $finished = ScheduledTaskRun::where('task', $key)->whereIn('status', ['success', 'failed'])->latest('id')->first();
            $lastStart = ScheduledTaskRun::where('task', $key)->whereNotNull('started_at')->max('started_at');
            $lastSuccess = ScheduledTaskRun::where('task', $key)->where('status', 'success')->max('finished_at');
            $day = ScheduledTaskRun::where('task', $key)->where('created_at', '>=', $since)->selectRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS ok, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS bad")->first();
            $missed = $scheduler['state'] === 'running' && $firstTick && $previousDue->gt(CarbonImmutable::parse($firstTick))
                && (! $lastStart || CarbonImmutable::parse($lastStart)->lt($previousDue));
            $state = match (true) {
                $latest?->status === 'running' => 'running',
                $latest?->status === 'queued' => 'queued',
                $finished?->status === 'failed' => 'failed',
                $missed => 'late',
                $finished !== null => 'success',
                default => 'never',
            };
            $tasks[] = [
                'key' => $key, 'cron' => $task['cron'], 'state' => $state,
                'next_run' => CarbonImmutable::instance($cron->getNextRunDate($now))->toIso8601String(),
                'last' => $finished ? $this->present($finished) : null,
                'current' => $latest && in_array($latest->status, ['queued', 'running'], true) ? $this->present($latest) : null,
                'last_success_at' => $lastSuccess ? CarbonImmutable::parse($lastSuccess)->toIso8601String() : null,
                'day' => ['success' => (int) ($day->ok ?? 0), 'failed' => (int) ($day->bad ?? 0)],
            ];
        }
        $ttl = (int) config('operations.heartbeat_ttl', 45);

        return [
            'scheduler' => $scheduler,
            'workers' => ['online' => MediaWorker::whereNull('stopped_at')->where('heartbeat_at', '>=', now()->subSeconds($ttl))->count(),
                'restart' => 'supervisorctl restart zatlan-worker'],
            'tasks' => $tasks,
            'timezone' => $zone,
            'keep_days' => self::KEEP_DAYS,
            'refreshed_at' => now()->toIso8601String(),
        ];
    }

    /** Tasks whose latest finished run failed (for the Admin dashboard). */
    public function failing(): int
    {
        return collect(array_keys(self::TASKS))->filter(fn (string $key) => ScheduledTaskRun::where('task', $key)->whereIn('status', ['success', 'failed'])->latest('id')->value('status') === 'failed')->count();
    }

    public function history(string $key, int $page = 1, string $status = ''): array
    {
        abort_unless(isset(self::TASKS[$key]), 404);
        $query = ScheduledTaskRun::where('task', $key)->with('requester:id,name')->when(in_array($status, ['success', 'failed'], true), fn ($q) => $q->where('status', $status))->latest('id');
        $rows = $query->paginate(15, ['*'], 'page', $page);

        return ['task' => $key, 'items' => collect($rows->items())->map(fn (ScheduledTaskRun $run) => $this->present($run))->all(),
            'pagination' => ['page' => $rows->currentPage(), 'pages' => max(1, $rows->lastPage()), 'total' => $rows->total()], 'status' => $status];
    }

    public function present(ScheduledTaskRun $run): array
    {
        return ['id' => $run->id, 'status' => $run->status, 'trigger' => $run->trigger, 'requested_by' => $run->requester?->name,
            'created_at' => $run->created_at?->toIso8601String(), 'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(), 'duration_ms' => $run->duration_ms, 'output' => $run->output, 'error' => $run->error];
    }
}
