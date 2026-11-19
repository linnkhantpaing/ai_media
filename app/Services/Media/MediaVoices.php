<?php

namespace App\Services\Media;

use App\Exceptions\MediaFailure;
use App\Models\MediaProject;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MediaVoices
{
    private ?bool $rubberband = null;

    public function __construct(private MediaRuntime $runtime, private GeminiClient $gemini, private MediaStorage $storage) {}

    public static function filter(array $settings): string
    {
        $pitch = pow(2, (float) ($settings['voice_pitch'] ?? 0) / 12);
        $rate = (float) $settings['speech_rate'] / $pitch;

        $filters = [];
        while ($rate < 0.5) {
            $filters[] = 'atempo=0.5';
            $rate /= 0.5;
        }
        while ($rate > 2) {
            $filters[] = 'atempo=2';
            $rate /= 2;
        }
        $filters[] = 'atempo='.$rate;

        return 'aresample=48000,asetrate='.(48000 * $pitch).',aresample=48000,'.implode(',', $filters);
    }

    /** Remove only synthesis edge silence; preserve all pauses inside the narration. */
    public function prepareScene(array $voice, array $settings, string $directory, callable $cancelled): array
    {
        if ($cancelled()) {
            throw new MediaFailure('cancelled');
        }
        $signature = hash('sha256', 'scene-edges-v1:'.hash_file('sha256', $voice['path']));
        $file = $directory.'/voice-edges-'.$signature.'.wav';
        if (! is_file($file)) {
            $trim = 'silenceremove=start_periods=1:start_duration=0.015:start_threshold=-55dB:start_silence=0.01:window=0.01';
            try {
                $this->runtime->execute('ffmpeg', [
                    '-nostdin', '-v', 'error', '-y', '-i', $voice['path'],
                    '-af', $trim.',areverse,'.$trim.',areverse,asetpts=PTS-STARTPTS',
                    '-ar', '48000', '-ac', '2', '-c:a', 'pcm_s16le', '-f', 'wav', $file.'.partial',
                ], timeout: 90, cancelled: $cancelled);
                $probe = $this->runtime->probe($file.'.partial');
                if ((float) ($probe['format']['duration'] ?? 0) <= 0.02) {
                    throw new MediaFailure('provider_response_invalid');
                }
                rename($file.'.partial', $file);
            } finally {
                if (is_file($file.'.partial')) {
                    unlink($file.'.partial');
                }
            }
        }
        $duration = (float) ($this->runtime->probe($file)['format']['duration'] ?? 0);
        if ($duration <= 0.02) {
            throw new MediaFailure('provider_response_invalid');
        }

        return ['path' => $file, 'raw_duration' => $duration, 'duration' => $duration / $settings['speech_rate']];
    }

    /** Pitch-preserving speech fitting fills the scene rather than padding a short voice with silence. */
    public function sceneFilter(float $voiceDuration, float $sceneDuration, array $settings): string
    {
        $this->rubberband ??= str_contains($this->runtime->execute('ffmpeg', ['-hide_banner', '-filters']), 'rubberband');
        $rate = $voiceDuration / $sceneDuration;
        if (MediaNarrationFit::enabled($settings) && abs($rate / $settings['speech_rate'] - 1) > MediaNarrationFit::TOLERANCE + 0.001) {
            throw new MediaFailure('narration_timing_mismatch');
        }
        if ($this->rubberband && $rate >= 0.01 && $rate <= 100) {
            $pitch = pow(2, (float) ($settings['voice_pitch'] ?? 0) / 12);

            return 'aresample=48000,rubberband=tempo='.$rate.':pitch='.$pitch.':formant=preserved';
        }

        return self::filter([...$settings, 'speech_rate' => $rate]);
    }

    public function line(MediaProject $project, array $line, array $settings, callable $cancelled): array
    {
        $identity = [$settings['narration_lang'] ?? 'my', $settings['voice_delivery'] ?? 'natural', $settings['name_dictionary'] ?? [], $line['text'], $settings['voice'], $settings['voice_style'], app(ProviderSettings::class)->resolved()['speech_model']];
        $hash = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
        $relative = 'media/'.$project->id.'/voices/'.$hash.'.wav';

        /** Older cache keys included a rate that is applied locally, not sent to TTS. */
        foreach (array_unique([$settings['speech_rate'], ...array_map(fn (int $step): float => $step / 100, range(50, 200, 5))]) as $rate) {
            $legacy = [...array_slice($identity, 0, 6), $rate, $identity[6]];
            $old = 'media/'.$project->id.'/voices/'.hash('sha256', json_encode($legacy, JSON_THROW_ON_ERROR)).'.wav';
            $oldFile = Storage::disk('local')->path($old);
            if (is_file($oldFile) && filesize($oldFile) >= 44) {
                $relative = $old;
                break;
            }
        }

        return Cache::lock('media-voice:'.$hash.':'.$project->id, 300)->block(10, function () use ($project, $line, $settings, $cancelled, $relative): array {
            $local = Storage::disk('local');
            $file = $local->path($relative);
            $local->makeDirectory(dirname($relative));
            if (! is_file($file) || filesize($file) < 44) {
                try {
                    $this->storage->materialize($project, $relative);
                } catch (MediaFailure) {
                    $this->gemini->speech($line['text'], $settings['voice'], $settings['voice_style'].' Delivery: '.($settings['voice_delivery'] ?? 'natural').'. Pronunciation: '.json_encode($settings['name_dictionary'] ?? [], JSON_UNESCAPED_UNICODE), $file.'.partial', $settings['narration_lang'] ?? 'my');
                    if ($cancelled()) {
                        if (is_file($file.'.partial')) {
                            unlink($file.'.partial');
                        }throw new MediaFailure('cancelled');
                    }
                    $this->runtime->probe($file.'.partial');
                    rename($file.'.partial', $file);
                }
            }
            $probe = $this->runtime->probe($file);
            $duration = (float) ($probe['format']['duration'] ?? 0) / $settings['speech_rate'];
            if ($duration <= 0 || $duration > 120) {
                throw new MediaFailure('provider_response_invalid');
            }
            $published = DB::table('media_files')->where('media_project_id', $project->id)->where('disk', $project->storage_disk)->where('path', $relative)->where('state', 'active')->whereNotNull('verified_at')->where('bytes', filesize($file))->exists();
            if (! $published) {
                $this->storage->publish($project, $relative, 'audio/wav');
            }

            return ['path' => $file, 'relative' => $relative, 'duration' => $duration];
        });
    }
}
