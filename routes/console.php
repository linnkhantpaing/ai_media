<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => Cache::put('media-cleanup-scheduler', now()->toIso8601String(), 180))->everyMinute()->name('media-cleanup-heartbeat');
Schedule::command('media:cleanup --scheduled')->hourly()->withoutOverlapping(60);

Schedule::command('usage:reconcile')->everyFiveMinutes()->withoutOverlapping(10);

Schedule::command('notifications:sync')->everyMinute()->withoutOverlapping(5);
