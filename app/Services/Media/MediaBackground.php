<?php

namespace App\Services\Media;

use App\Exceptions\MediaFailure;
use App\Models\MediaProject;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class MediaBackground
{
    private const REVISION = 'UVR-MDX-NET-Inst_HQ_3-selected-v2';

    public function __construct(private MediaRuntime $runtime, private MediaStorage $storage) {}

    public function available(): bool
    {
        $custom = json_decode((string) config('media.separation_command'), true);

        return (is_array($custom) && is_file($custom[0] ?? '')) || (is_file(config('media.separation_python')) && is_file(config('media.separation_models').'/UVR-MDX-NET-Inst_HQ_3.onnx') && is_file(config('media.separation_models').'/engine-ready.json'));
    }

    public function diagnose(): bool
    {
        if (! $this->available() || ! is_file(config('media.separation_python'))) {
            return false;
        }
        $process = new Process([config('media.separation_python'), '-c', 'import sys, onnxruntime as o; o.InferenceSession(sys.argv[1], providers=["CPUExecutionProvider"]); print("ready")', config('media.separation_models').'/UVR-MDX-NET-Inst_HQ_3.onnx'], timeout: 45);
        try {
            $process->run();

            return $process->isSuccessful() && str_contains($process->getOutput(), 'ready');
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<array{start: float, end: float, offset: float}> */
    public static function plan(array $lines, float $duration, bool $selectedOnly): array
    {
        if ($duration <= 0 || ! is_finite($duration)) {
            throw new MediaFailure('invalid_media');
        }
        if (! $selectedOnly) {
            return [['start' => 0.0, 'end' => $duration, 'offset' => 0.0]];
        }
        $ranges = [];
        foreach ($lines as $line) {
            if (! ($line['included'] ?? true)) {
                continue;
            }
            $start = max(0.0, (float) ($line['start'] ?? 0));
            $end = min($duration, (float) ($line['end'] ?? 0));
            if (! is_finite($start) || ! is_finite($end) || $end <= $start) {
                throw new MediaFailure('invalid_script');
            }
            $ranges[] = ['start' => $start, 'end' => $end];
        }
        usort($ranges, fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        $merged = [];
        foreach ($ranges as $range) {
            $last = count($merged) - 1;
            if ($last >= 0 && $range['start'] <= $merged[$last]['end']) {
                $merged[$last]['end'] = max($merged[$last]['end'], $range['end']);
            } else {
                $merged[] = $range;
            }
        }
        $offset = 0.0;
        foreach ($merged as &$range) {
            $range['offset'] = $offset;
            $offset += $range['end'] - $range['start'];
        }
        unset($range);
        if ($merged === []) {
            throw new MediaFailure('invalid_script');
        }

        return $merged;
    }

    public static function offset(array $ranges, float $sourceTime): float
    {
        foreach ($ranges as $range) {
            if ($sourceTime >= $range['start'] - 0.001 && $sourceTime < $range['end']) {
                return (float) ($range['offset'] + $sourceTime - $range['start']);
            }
        }
        throw new MediaFailure('invalid_script');
    }

    /** Selected ranges are packed into the returned WAV; offset() maps original source time. */
    public function obtain(MediaProject $project, callable $cancelled, ?array $ranges = null, ?callable $progress = null): string
    {
        if (! $this->available()) {
            throw new MediaFailure('background_unavailable');
        }
        $sourceDuration = (float) ($project->metadata['duration'] ?? 0);
        $ranges ??= self::plan([], $sourceDuration, false);
        $duration = array_sum(array_map(fn (array $range): float => $range['end'] - $range['start'], $ranges));
        $key = hash('sha256', json_encode([$project->source_path, $project->fingerprint, config('media.separation_command'), self::REVISION, $ranges]));
        $relative = 'media/'.$project->id.'/background/'.$key.'.wav';

        return Cache::lock('media-background:'.$project->id, 1800)->block(10, function () use ($project, $cancelled, $progress, $relative, $ranges, $duration, $sourceDuration, $key): string {
            try {
                $file = $this->storage->materialize($project, $relative);
                if ($this->valid($file, $duration)) {
                    $progress && $progress(100);

                    return $file;
                }
            } catch (MediaFailure $failure) {
                if (! in_array($failure->reason, ['source_missing', 'storage_unavailable', 'invalid_media'], true)) {
                    throw $failure;
                }
            }
            $local = Storage::disk('local');
            $directory = $local->path('media/'.$project->id.'/background');
            $local->makeDirectory('media/'.$project->id.'/background');
            $source = $this->storage->materialize($project, $project->source_path);
            $items = [];
            $clips = [];
            $clipDurations = [];
            $completed = 0.0;
            $prepared = 0.0;
            $temporary = [];
            $target = $local->path($relative);
            try {
                foreach ($ranges as $range) {
                    for ($start = $range['start']; $start < $range['end'] - 0.00001; $start = $end) {
                        if ($cancelled()) {
                            throw new MediaFailure('cancelled');
                        }
                        $end = min($range['end'], $start + 60);
                        // Six seconds of real source context protects the model's ~5.9s windows at both cut edges.
                        $contextStart = max(0.0, $start - 6);
                        $contextEnd = min($sourceDuration, $end + 6);
                        $chunkKey = hash('sha256', json_encode([self::REVISION, $project->fingerprint, $project->source_path, config('media.separation_command'), $start, $end, $contextStart, $contextEnd]));
                        $output = $directory.'/chunk-'.$chunkKey.'.wav';
                        $length = $end - $start;
                        $clips[] = $output;
                        $clipDurations[] = $length;
                        if ($this->valid($output, $length)) {
                            $completed += $length;
                        } else {
                            $input = $directory.'/input-'.$chunkKey.'.wav';
                            $temporary[] = $input;
                            $this->runtime->execute('ffmpeg', ['-nostdin', '-v', 'error', '-y', '-ss', (string) $contextStart, '-t', (string) ($contextEnd - $contextStart), '-i', $source, '-vn', '-af', 'apad=whole_dur='.($contextEnd - $contextStart), '-t', (string) ($contextEnd - $contextStart), '-ac', '2', '-ar', '44100', '-c:a', 'pcm_s16le', $input], timeout: 180, cancelled: $cancelled);
                            $items[] = ['input' => $input, 'output' => $output, 'trim_start' => $start - $contextStart, 'duration' => $length];
                        }
                        $prepared += $length;
                        $progress && $progress((int) floor($prepared / $duration * 10));
                    }
                }
                if ($items !== []) {
                    $manifest = $directory.'/selection-'.$key.'.json';
                    $temporary[] = $manifest;
                    file_put_contents($manifest, json_encode(['items' => $items, 'completed_seconds' => $completed, 'total_seconds' => $duration], JSON_THROW_ON_ERROR), LOCK_EX);
                    $custom = json_decode((string) config('media.separation_command'), true);
                    if (is_array($custom) && $custom !== []) {
                        foreach ($items as $item) {
                            $raw = $item['output'].'.context.wav';
                            $partial = $item['output'].'.partial.wav';
                            $temporary[] = $raw;
                            $temporary[] = $partial;
                            $command = array_map(fn (string $arg): string => str_replace(['{input}', '{output}'], [$item['input'], $raw], $arg), $custom);
                            $this->separate($command, $directory, $cancelled);
                            $this->runtime->execute('ffmpeg', ['-v', 'error', '-y', '-i', $raw, '-ss', (string) $item['trim_start'], '-t', (string) $item['duration'], '-ar', '44100', '-ac', '2', '-c:a', 'pcm_s16le', $partial], cancelled: $cancelled);
                            if (! $this->valid($partial, $item['duration'])) {
                                throw new MediaFailure('render_verification_failed');
                            }
                            rename($partial, $item['output']);
                            $completed += $item['duration'];
                            $progress && $progress(10 + (int) floor($completed / $duration * 84));
                        }
                    } else {
                        $this->separate([config('media.separation_python'), '-u', base_path('app/Services/Media/separate_audio.py'), '--manifest', $manifest, config('media.separation_models')], $directory, $cancelled, $progress);
                    }
                }
                foreach ($clips as $index => $file) {
                    if (! $this->valid($file, $clipDurations[$index])) {
                        throw new MediaFailure('render_verification_failed');
                    }
                }
                $playlist = $directory.'/selection-'.$key.'.txt';
                $temporary[] = $playlist;
                file_put_contents($playlist, implode("\n", array_map(fn (string $file): string => "file '".basename($file)."'", $clips)), LOCK_EX);
                $partial = $target.'.partial.wav';
                $temporary[] = $partial;
                $this->runtime->execute('ffmpeg', ['-nostdin', '-v', 'error', '-y', '-f', 'concat', '-safe', '1', '-i', $playlist, '-c:a', 'copy', $partial], cancelled: $cancelled, progress: $progress ? fn (int $percent) => $progress(94 + (int) floor($percent * 0.05)) : null, duration: $duration);
                if (! $this->valid($partial, $duration)) {
                    throw new MediaFailure('render_verification_failed');
                }
                rename($partial, $target);
                $this->storage->publish($project, $relative, 'audio/wav');
                $progress && $progress(100);

                return $target;
            } finally {
                foreach ($temporary as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
        });
    }

    private function valid(string $file, ?float $duration): bool
    {
        if (! is_file($file) || filesize($file) < 44) {
            return false;
        }
        try {
            $probe = $this->runtime->probe($file);
            $actual = (float) ($probe['format']['duration'] ?? 0);

            return collect($probe['streams'] ?? [])->firstWhere('codec_type', 'audio') && $actual > 0 && ($duration === null || abs($actual - $duration) < 0.05);
        } catch (MediaFailure) {
            return false;
        }
    }

    private function separate(array $command, string $directory, callable $cancelled, ?callable $progress = null): void
    {
        $process = new Process($command, $directory, ['PATH' => dirname(config('media.ffmpeg')).PATH_SEPARATOR.getenv('PATH'), 'OMP_NUM_THREADS' => '2'], timeout: 1200);
        $buffer = '';
        $lastPercent = -1;
        try {
            $process->start(function (string $type, string $output) use ($progress, &$buffer, &$lastPercent): void {
                if ($type !== Process::OUT || ! $progress) {
                    return;
                }
                $buffer .= $output;
                while (($end = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $end);
                    $buffer = substr($buffer, $end + 1);
                    if (preg_match('/^RECAP_BACKGROUND (\d+)$/', trim($line), $match)) {
                        $percent = min(94, 10 + (int) floor((int) $match[1] * 0.84));
                        if ($percent > $lastPercent) {
                            $lastPercent = $percent;
                            $progress($percent);
                        }
                    }
                }
                $buffer = substr($buffer, -8192);
            });
            while ($process->isRunning()) {
                $process->checkTimeout();
                if ($cancelled()) {
                    throw new MediaFailure('cancelled');
                }
                usleep(200000);
            }
            if (! $process->isSuccessful()) {
                throw new MediaFailure('background_unavailable');
            }
        } catch (ProcessTimedOutException) {
            throw new MediaFailure('media_timeout');
        } finally {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
    }
}
