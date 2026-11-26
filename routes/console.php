<?php

use App\Services\AiUsage;
use App\Services\ScheduledTasks;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ai-usage:prune', function () {
    app(AiUsage::class)->prune();
    $this->info('AI usage older than 90 days was rolled into daily totals.');
})->purpose('Roll AI call logs older than 90 days into daily totals (also runs daily at 03:15)');

// Cron heartbeat and Admin "Run now" requests (Admin → Operations → Scheduled Tasks).
Schedule::call(fn () => app(ScheduledTasks::class)->tick())->everyMinute()->name('scheduler-heartbeat');
Schedule::call(fn () => app(ScheduledTasks::class)->runRequested())->everyMinute()->name('scheduled-task-requests')->withoutOverlapping(120);

// Every background task is recorded with its result; the task list lives in ScheduledTasks::TASKS.
foreach (ScheduledTasks::TASKS as $key => $task) {
    Schedule::call(fn () => app(ScheduledTasks::class)->run($key))->cron($task['cron'])->name('task:'.$key);
}
