<?php

namespace App\Services\Media;

use App\Exceptions\MediaFailure;
use App\Support\YouTubeUrl;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class YouTubeDownloader
{
    public function __construct(private MediaRuntime $runtime) {}

    public function available(): bool
    {
        return is_file((string) config('media.ytdlp')) && $this->node() !== null && $this->runtime->available();
    }

    public function inspect(string $url, string $directory, callable $cancelled): array
    {
        $output = $this->execute([...$this->base(), '--skip-download', '--dump-single-json', '--', $this->url($url)], $directory, $cancelled, timeout: 120);
        try {
            $info = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new MediaFailure('youtube_unavailable');
        }
        if (! is_array($info) || ($info['_type'] ?? 'video') !== 'video') {
            throw new MediaFailure('youtube_unavailable');
        }
        if (($info['is_live'] ?? false) || in_array($info['live_status'] ?? '', ['is_live', 'is_upcoming'], true)) {
            throw new MediaFailure('youtube_live');
        }
        $duration = (float) ($info['duration'] ?? 0);
        if (! is_finite($duration) || $duration <= 0) {
            throw new MediaFailure('youtube_unavailable');
        }
        if ($duration > app(StorageSettings::class)->limits()['max_duration']) {
            throw new MediaFailure('duration_exceeded');
        }
        if (isset($info['filesize']) && (int) $info['filesize'] > app(StorageSettings::class)->limits()['max_bytes']) {
            throw new MediaFailure('too_large');
        }

        return ['title' => mb_substr((string) ($info['title'] ?? 'YouTube video'), 0, 250), 'duration' => $duration];
    }

    public function download(string $url, string $directory, callable $progress, callable $cancelled): string
    {
        $downloads = [];
        $this->execute([
            ...$this->base(), '--newline', '--progress', '--progress-delta', '1',
            '--progress-template', 'download:{"stage":"download","file":%(progress.filename)j,"bytes":%(progress.downloaded_bytes)j}',
            '--max-filesize', (string) app(StorageSettings::class)->limits()['max_bytes'],
            '--match-filters', '!is_live & duration <= '.app(StorageSettings::class)->limits()['max_duration'],
            '--format', 'bv*[height<=1080][ext=mp4]+ba[ext=m4a]/b[height<=1080][ext=mp4]/bv*[height<=1080]+ba/b[height<=1080]',
            '--format-sort', 'vcodec:h264,acodec:aac', '--merge-output-format', 'mp4', '--remux-video', 'mp4',
            '--output', 'download.%(ext)s', '--', $this->url($url),
        ], $directory, $cancelled, function (array $event) use ($progress, &$downloads): void {
            if (($event['stage'] ?? '') === 'download' && is_string($event['file'] ?? null) && is_numeric($event['bytes'] ?? null)) {
                $downloads[$event['file']] = max($downloads[$event['file']] ?? 0, (int) $event['bytes']);
                $progress(array_sum($downloads));
            }
        }, timeout: 600);
        $file = $directory.'/download.mp4';
        if (! is_file($file) || filesize($file) === 0) {
            throw new MediaFailure('youtube_unavailable');
        }
        if (filesize($file) > app(StorageSettings::class)->limits()['max_bytes']) {
            throw new MediaFailure('too_large');
        }

        return $file;
    }

    private function url(string $url): string
    {
        return YouTubeUrl::normalize($url) ?? throw new MediaFailure('invalid_youtube_url');
    }

    private function node(): ?string
    {
        $configured = config('media.node_binary');

        return is_string($configured) && is_file($configured) ? $configured : (new ExecutableFinder)->find('node');
    }

    private function base(): array
    {
        if (! $this->available()) {
            throw new MediaFailure('youtube_tools_missing');
        }

        return [
            '--ignore-config', '--no-plugin-dirs', '--no-cache-dir', '--no-playlist',
            '--use-extractors', 'youtube', '--encoding', 'utf-8', '--no-colors',
            '--socket-timeout', '20', '--retries', '2', '--fragment-retries', '2', '--extractor-retries', '1',
            '--js-runtimes', 'node:'.$this->node(), '--ffmpeg-location', dirname(config('media.ffmpeg')),
        ];
    }

    private function execute(array $arguments, string $directory, callable $cancelled, ?callable $progress = null, int $timeout = 120): string
    {
        $process = new Process([config('media.ytdlp'), ...$arguments], $directory, timeout: $timeout);
        $process->setEnv(array_fill_keys(['GEMINI_API_KEY', 'R2_ACCESS_KEY_ID', 'R2_SECRET_ACCESS_KEY', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'ADMIN_SEED_PASSWORD', 'APP_KEY', 'DB_PASSWORD'], false));
        $buffers = [];
        $lastProgress = 0.0;
        try {
            $process->start(function (string $type, string $output) use (&$buffers, &$lastProgress, $progress): void {
                if (! $progress) {
                    return;
                }
                $buffers[$type] = ($buffers[$type] ?? '').$output;
                while (($end = strpos($buffers[$type], "\n")) !== false) {
                    $line = trim(substr($buffers[$type], 0, $end));
                    $buffers[$type] = substr($buffers[$type], $end + 1);
                    $event = json_decode($line, true);
                    if (is_array($event) && microtime(true) - $lastProgress >= 0.5) {
                        $lastProgress = microtime(true);
                        $progress($event);
                    }
                }
            });
            while ($process->isRunning()) {
                $process->checkTimeout();
                if ($cancelled()) {
                    $process->stop(1);
                    throw new MediaFailure('cancelled');
                }
                $size = 0;
                foreach (glob($directory.'/*') ?: [] as $path) {
                    if (is_file($path)) {
                        clearstatcache(true, $path);
                        $size += filesize($path);
                    }
                }
                if ($size > 2 * app(StorageSettings::class)->limits()['max_bytes'] + 1024 * 1024) {
                    $process->stop(1);
                    throw new MediaFailure('too_large');
                }
                usleep(300000);
            }
        } catch (ProcessTimedOutException) {
            $process->stop(1);
            throw new MediaFailure('youtube_timeout');
        } finally {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        if (! $process->isSuccessful()) {
            throw new MediaFailure('youtube_unavailable');
        }

        return $process->getOutput();
    }
}
