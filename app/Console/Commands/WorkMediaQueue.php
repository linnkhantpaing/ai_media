<?php

namespace App\Console\Commands;

use App\Models\MediaWorker;
use App\Services\Media\AutomaticMediaWorker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class WorkMediaQueue extends Command
{
    protected $signature = 'media:work {--name=Media Worker 01 : Friendly worker name} {--once : Process at most one job and exit} {--auto : Stop automatically after the saved idle period}';

    protected $description = 'Run the media queue with an independent, durable worker heartbeat';

    protected function makeWorkerProcess(MediaWorker $worker): Process
    {
        $arguments = [PHP_BINARY, base_path('artisan'), 'queue:work',
            '--queue='.implode(',', config('operations.queues')), '--timeout=1800', '--tries=3', '--sleep=1',
            '--max-jobs=1', '--max-time=30', '--memory=512'];
        if ($this->option('once')) {
            $arguments[] = '--once';
        }

        return new Process($arguments, base_path(), ['MEDIA_WORKER_ID' => $worker->id], timeout: 1830);
    }

    public function handle(): int
    {
        $name = trim((string) $this->option('name'));
        if ($name === '' || mb_strlen($name) > 100) {
            $this->error('Worker name must contain 1–100 characters.');

            return self::FAILURE;
        }
        $automatic = (bool) $this->option('auto');
        $lock = fopen(config('operations.worker_lock'), 'c');
        if (! $lock) {
            $this->error('Unable to acquire the media worker lock.');

            return self::FAILURE;
        }
        $waitUntil = microtime(true) + ($automatic ? 5 : 0);
        do {
            $locked = flock($lock, LOCK_EX | LOCK_NB);
            if ($locked || microtime(true) >= $waitUntil) {
                break;
            }
            usleep(100000);
        } while (true);
        if (! $locked) {
            fclose($lock);
            $this->info('A media worker is already active for this application.');

            return self::SUCCESS;
        }
        $worker = MediaWorker::create(['id' => (string) Str::uuid(), 'name' => $name,
            'queues' => config('operations.queues'), 'capacity' => 1, 'heartbeat_at' => now(), 'automatic' => $automatic, 'process_id' => getmypid()]);
        Cache::forget('media-auto-worker-starting');
        Cache::forget('media-auto-worker-error');
        $lastWorkAt = now()->timestamp;
        $exitCode = self::SUCCESS;
        $process = null;
        $this->info('Media worker registered; heartbeat reporting enabled.');
        try {
            do {
                $policy = app(AutomaticMediaWorker::class)->policy();
                if ($automatic && (! $policy['enabled'] || (! app(AutomaticMediaWorker::class)->pending() && now()->timestamp - $lastWorkAt >= $policy['idleSeconds']))) {
                    return self::SUCCESS;
                }
                $process = $this->makeWorkerProcess($worker);
                try {
                    $process->start(function (string $type, string $buffer): void {
                        $this->output->write($buffer);
                    });
                    $next = 0;
                    while ($process->isRunning()) {
                        $process->checkTimeout();
                        if ($automatic && app(AutomaticMediaWorker::class)->pending()) {
                            $lastWorkAt = now()->timestamp;
                        }
                        if (microtime(true) >= $next) {
                            $worker->update(['heartbeat_at' => now()]);
                            $next = microtime(true) + config('operations.heartbeat_interval');
                        }
                        usleep(250000);
                    }
                    $exitCode = $process->getExitCode() ?? self::FAILURE;
                    if ($automatic && app(AutomaticMediaWorker::class)->pending()) {
                        $lastWorkAt = now()->timestamp;
                    }
                } catch (ProcessTimedOutException) {
                    $process->stop(5);
                    $exitCode = self::FAILURE;
                    $this->warn('Worker child exceeded its runtime limit; the supervisor will restart it.');
                }
                $worker->update(['heartbeat_at' => now(), 'current_task_id' => null,
                    'error_code' => $exitCode ? 'queue_worker_restarting' : null]);
                if ($this->option('once')) {
                    return $exitCode;
                }
                usleep(1000000);
            } while (true);
        } finally {
            if ($process && $process->isRunning()) {
                $process->stop(10);
            }
            $worker->update(['stopped_at' => now(), 'heartbeat_at' => now(), 'current_task_id' => null,
                'error_code' => $exitCode ? 'queue_worker_stopped' : null]);
            flock($lock, LOCK_UN);
            fclose($lock);
            if ($automatic) {
                app(AutomaticMediaWorker::class)->wake();
            }
        }
    }
}
