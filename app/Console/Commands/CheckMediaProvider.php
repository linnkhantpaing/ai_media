<?php

namespace App\Console\Commands;

use App\Exceptions\MediaFailure;
use App\Services\Media\GeminiClient;
use App\Services\Media\MediaRuntime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class CheckMediaProvider extends Command
{
    protected $signature = 'media:provider-check {--speech : Generate and verify a short Burmese voice sample}';

    protected $description = 'Verify live Gemini translation and optional speech without revealing credentials';

    public function handle(GeminiClient $gemini, MediaRuntime $runtime): int
    {
        $directory = 'checks/'.Str::uuid();
        try {
            $result = $gemini->translate([['index' => 0, 'start' => 0, 'end' => 4, 'source_text' => 'Hello. Welcome to our video studio.']], 'A short studio greeting.');
            $line = $result['translations'][0] ?? null;
            if (! is_array($line) || ($line['index'] ?? null) !== 0 || ! is_string($line['text'] ?? null) || trim($line['text']) === '') {
                throw new MediaFailure('provider_response_invalid');
            }
            $this->info('Live structured Burmese translation verified.');
            if ($this->option('speech')) {
                Storage::disk('local')->makeDirectory($directory);
                $file = Storage::disk('local')->path($directory.'/speech.wav');
                $gemini->speech($line['text'], 'Kore', 'Speak clearly in natural Burmese.', $file);
                $probe = $runtime->probe($file);
                $audio = collect($probe['streams'] ?? [])->firstWhere('codec_type', 'audio');
                $duration = (float) ($probe['format']['duration'] ?? 0);
                if (! $audio || $duration <= 0) {
                    throw new MediaFailure('provider_response_invalid');
                }
                $this->info('Live Burmese speech verified: '.round($duration, 2).' seconds, '.$audio['sample_rate'].' Hz.');
            }

            return self::SUCCESS;
        } catch (MediaFailure $failure) {
            $this->error('Provider check failed: '.$failure->reason.($failure->retryAfter ? '; retry after '.$failure->retryAfter.' seconds.' : '.'));

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Provider check failed ('.$exception::class.'). Credentials were not displayed.');

            return self::FAILURE;
        } finally {
            Storage::disk('local')->deleteDirectory($directory);
        }
    }
}
