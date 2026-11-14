<?php

namespace App\Services\Media;

use App\Exceptions\MediaFailure;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class MediaRuntime
{
    public function available(): bool
    {
        return is_file(config('media.ffmpeg')) && is_file(config('media.ffprobe'));
    }

    public function execute(string $tool, array $arguments, ?string $directory = null, int $timeout = 120, ?callable $cancelled = null): string
    {
        $binary = config('media.'.$tool);
        if (! is_string($binary) || ! is_file($binary)) {
            throw new MediaFailure('media_tools_missing');
        }
        $process = new Process([$binary, ...$arguments], $directory, timeout: $timeout);
        try {
            $process->start();
            $lastCheck = 0.0;
            while ($process->isRunning()) {
                $process->checkTimeout();
                if ($cancelled && microtime(true) - $lastCheck > 0.5) {
                    $lastCheck = microtime(true);
                    if ($cancelled()) {
                        $process->stop(1);
                        throw new MediaFailure('cancelled');
                    }
                }
                usleep(100000);
            }
        } catch (ProcessTimedOutException) {
            $process->stop(1);
            throw new MediaFailure('media_timeout');
        }
        if (! $process->isSuccessful()) {
            throw new MediaFailure('invalid_media');
        }

        return $process->getOutput();
    }

    public function probe(string $file): array
    {
        try {
            return json_decode($this->execute('ffprobe', [
                '-v', 'error', '-protocol_whitelist', 'file,pipe', '-show_format', '-show_streams', '-of', 'json', $file,
            ], timeout: 45), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new MediaFailure('invalid_media');
        }
    }

    public function capabilities(): array
    {
        return [
            'mediaReady' => $this->available(),
            'aiReady' => app(ProviderSettings::class)->ready(),
            'backgroundSeparation' => app(MediaBackground::class)->available(),
            'voices' => config('media.voices'),
            'youtubeReady' => app(YouTubeDownloader::class)->available(),
        ];
    }
}
