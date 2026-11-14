<?php

namespace App\Services\Media;

use App\Models\MediaWorker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class AutomaticMediaWorker
{
    public function pending(): bool
    {
        return config('queue.default') === 'database'
            && DB::table(config('queue.connections.database.table', 'jobs'))->whereIn('queue', config('operations.queues'))->exists();
    }

    public function policy(): array
    {
        $configuration = app(ProviderSettings::class)->current();

        return ['enabled' => $configuration['auto_processing'], 'idleSeconds' => $configuration['worker_idle_seconds']];
    }

    private function phpBinary(): ?string
    {
        $candidate = config('operations.php_binary');
        if (! $candidate) {
            $candidate = PHP_OS_FAMILY === 'Windows' ? dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'php.exe' : (in_array(PHP_SAPI, ['cli', 'cli-server'], true) ? PHP_BINARY : (new ExecutableFinder)->find('php'));
        }

        return is_string($candidate) && is_file($candidate) ? $candidate : null;
    }

    public function supported(): bool
    {
        return config('queue.default') === 'database' && $this->phpBinary() !== null && function_exists('proc_open')
            && (PHP_OS_FAMILY !== 'Windows' || is_file($this->powershell()));
    }

    private function powershell(): string
    {
        return (getenv('SystemRoot') ?: 'C:\Windows').'\System32\WindowsPowerShell\v1.0\powershell.exe';
    }

    private function online(): bool
    {
        return MediaWorker::whereNull('stopped_at')->where('heartbeat_at', '>=', now()->subSeconds(config('operations.heartbeat_ttl')))->exists();
    }

    public function status(): array
    {
        $policy = $this->policy();
        $supported = $this->supported();
        $error = Cache::get('media-auto-worker-error');
        $state = ! $policy['enabled'] ? 'manual' : (! $supported ? 'unsupported' : ($this->online() ? 'online' : ($error ? 'failed' : (Cache::has('media-auto-worker-starting') ? 'starting' : 'standby'))));

        return [...$policy, 'supported' => $supported, 'state' => $state, 'errorCode' => $error];
    }

    public function wake(): void
    {
        if (! config('operations.auto_launch_allowed', ! app()->runningUnitTests()) || ! $this->pending() || ! $this->policy()['enabled'] || $this->online()) {
            return;
        }
        if (! $this->supported()) {
            Cache::put('media-auto-worker-error', 'auto_worker_unavailable', 60);

            return;
        }
        if (Cache::has('media-auto-worker-error')) {
            return;
        }
        try {
            Cache::lock('media-auto-worker-launch', 15)->get(function (): void {
                if (Cache::has('media-auto-worker-starting') || $this->online() || ! $this->pending()) {
                    return;
                }
                Cache::put('media-auto-worker-starting', true, 10);
                try {
                    $this->launch();
                    Cache::forget('media-auto-worker-error');
                } catch (Throwable $exception) {
                    Log::warning('Automatic media worker launch failed', ['exception' => $exception::class, 'detail' => $this->launchError($exception)]);
                    Cache::forget('media-auto-worker-starting');
                    Cache::put('media-auto-worker-error', 'auto_worker_launch_failed', 30);
                }
            });
        } catch (Throwable) {
            Cache::put('media-auto-worker-error', 'auto_worker_launch_failed', 30);
        }
    }

    private function launchError(Throwable $exception): string
    {
        $detail = $exception instanceof ProcessFailedException ? $exception->getProcess()->getErrorOutput() : $exception->getMessage();
        if (str_contains($detail, "\0")) {
            $detail = mb_convert_encoding($detail, 'UTF-8', 'UTF-16LE');
        }

        return mb_substr($detail, 0, 800);
    }

    private function windowsEnvironment(): array
    {
        $environment = [];
        foreach (['SystemRoot', 'SystemDrive', 'windir', 'ComSpec', 'USERPROFILE', 'APPDATA', 'LOCALAPPDATA', 'TEMP', 'TMP', 'PATH', 'PATHEXT', 'PSModulePath', 'ProgramData', 'ProgramFiles', 'ProgramFiles(x86)', 'ProgramW6432', 'HOMEDRIVE', 'HOMEPATH', 'USERNAME', 'USERDOMAIN', 'OS', 'PROCESSOR_ARCHITECTURE'] as $name) {
            $value = getenv($name);
            if ($value !== false) {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }

    protected function launch(): void
    {
        $php = $this->phpBinary();
        if (PHP_OS_FAMILY === 'Windows') {
            $quote = fn (string $value): string => "'".str_replace("'", "''", $value)."'";
            $script = '$ErrorActionPreference = \'Stop\'; Start-Process -FilePath '.$quote($php)
                .' -ArgumentList @('.$quote('"'.base_path('artisan').'"').", 'media:work', '--auto', '--no-interaction')"
                .' -WorkingDirectory '.$quote(base_path()).' -WindowStyle Hidden'
                .' -RedirectStandardOutput '.$quote(storage_path('logs/media-worker-auto.log'))
                .' -RedirectStandardError '.$quote(storage_path('logs/media-worker-auto-error.log'));
            $process = new Process([$this->powershell(), '-NoLogo', '-NoProfile', '-NonInteractive', '-EncodedCommand', base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'))], base_path(), $this->windowsEnvironment(), timeout: 12);
            $process->mustRun();
        } else {
            $command = 'nohup '.escapeshellarg($php).' '.escapeshellarg(base_path('artisan')).' media:work --auto --no-interaction >> '.escapeshellarg(storage_path('logs/media-worker-auto.log')).' 2>&1 < /dev/null &';
            (new Process(['/bin/sh', '-c', $command], base_path(), timeout: 12))->mustRun();
        }
    }
}
