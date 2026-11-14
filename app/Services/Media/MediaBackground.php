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

    public function obtain(MediaProject $project, callable $cancelled): string
    {
        if (! $this->available()) {
            throw new MediaFailure('background_unavailable');
        }
        $key = hash('sha256', json_encode([$project->source_path, $project->fingerprint, config('media.separation_command'), 'UVR-MDX-NET-Inst_HQ_3-v1']));
        $relative = 'media/'.$project->id.'/background/'.$key.'.wav';

        return Cache::lock('media-background:'.$project->id, 1800)->block(10, function () use ($project, $cancelled, $relative): string {
            try {
                $file = $this->storage->materialize($project, $relative);
                $this->runtime->probe($file);

                return $file;
            } catch (MediaFailure $failure) {
                if (! in_array($failure->reason, ['source_missing', 'storage_unavailable'], true)) {
                    throw $failure;
                }
            }
            $local = Storage::disk('local');
            $directory = $local->path('media/'.$project->id.'/background');
            $local->makeDirectory('media/'.$project->id.'/background');
            $input = $directory.'/input.wav';
            $output = $local->path($relative).'.partial.wav';
            $source = $this->storage->materialize($project, $project->source_path);
            $this->runtime->execute('ffmpeg', ['-nostdin', '-v', 'error', '-y', '-i', $source, '-vn', '-ac', '2', '-ar', '44100', $input], timeout: 180, cancelled: $cancelled);
            $command = json_decode((string) config('media.separation_command'), true);
            if (! is_array($command) || $command === []) {
                $command = [config('media.separation_python'), base_path('app/Services/Media/separate_audio.py'), '{input}', '{output}', config('media.separation_models')];
            }
            $command = array_map(fn (string $arg): string => str_replace(['{input}', '{output}'], [$input, $output], $arg), $command);
            $process = new Process($command, $directory, ['PATH' => dirname(config('media.ffmpeg')).PATH_SEPARATOR.getenv('PATH'), 'OMP_NUM_THREADS' => '2'], timeout: 1200);
            try {
                $process->start();
                while ($process->isRunning()) {
                    $process->checkTimeout();
                    if ($cancelled()) {
                        $process->stop(1);
                        throw new MediaFailure('cancelled');
                    }
                    usleep(200000);
                }
                if (! $process->isSuccessful() || ! is_file($output)) {
                    throw new MediaFailure('background_unavailable');
                }
                $probe = $this->runtime->probe($output);
                $duration = (float) ($probe['format']['duration'] ?? 0);
                if (! collect($probe['streams'] ?? [])->firstWhere('codec_type', 'audio') || abs($duration - $project->metadata['duration']) > 1) {
                    throw new MediaFailure('render_verification_failed');
                }
                rename($output, $local->path($relative));
                $this->storage->publish($project, $relative, 'audio/wav');

                return $local->path($relative);
            } catch (ProcessTimedOutException) {
                $process->stop(1);
                throw new MediaFailure('media_timeout');
            } finally {
                if (is_file($input)) {
                    unlink($input);
                }
                if (is_file($output)) {
                    unlink($output);
                }
            }
        });
    }
}
