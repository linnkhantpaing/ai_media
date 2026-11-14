<?php

namespace App\Services\Media;

use App\Exceptions\MediaFailure;
use App\Models\MediaProject;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class MediaVoices
{
    public function __construct(private MediaRuntime $runtime, private GeminiClient $gemini, private MediaStorage $storage) {}

    public function line(MediaProject $project, array $line, array $settings, callable $cancelled): array
    {
        $hash = hash('sha256', json_encode([$line['text'], $settings['voice'], $settings['voice_style'], $settings['speech_rate'], app(ProviderSettings::class)->resolved()['speech_model']], JSON_THROW_ON_ERROR));
        $relative = 'media/'.$project->id.'/voices/'.$hash.'.wav';

        return Cache::lock('media-voice:'.$hash.':'.$project->id, 300)->block(10, function () use ($project, $line, $settings, $cancelled, $relative): array {
            $local = Storage::disk('local');
            $file = $local->path($relative);
            $local->makeDirectory(dirname($relative));
            if (! is_file($file) || filesize($file) < 44) {
                try {
                    $this->storage->materialize($project, $relative);
                } catch (MediaFailure) {
                    $this->gemini->speech($line['text'], $settings['voice'], $settings['voice_style'], $file.'.partial');
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
            $this->storage->publish($project, $relative, 'audio/wav');

            return ['path' => $file, 'relative' => $relative, 'duration' => $duration];
        });
    }
}
