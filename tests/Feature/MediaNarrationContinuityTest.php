<?php

namespace Tests\Feature;

use App\Exceptions\MediaFailure;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Services\Media\MediaRenderer;
use App\Services\Media\MediaRuntime;
use App\Services\Media\MediaVoices;
use App\Support\MediaSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaNarrationContinuityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('r2');
        config(['filesystems.media_disk' => 'local', 'media.gemini_key' => 'test-key']);
        Http::preventStrayRequests();
        if (! app(MediaRuntime::class)->available()) {
            $this->markTestSkipped('FFmpeg is required.');
        }
    }

    public function test_short_narration_spans_both_five_second_scenes_at_slow_normal_and_fast_speed(): void
    {
        $project = $this->project();
        $this->voiceFixture('if(between(t,0.35,1.95),0.14*sin(2*PI*660*t),0)', 2.3);
        foreach ([0.5, 1, 2] as $speed) {
            $settings = MediaSettings::normalize(['mode' => 'dub', 'workflow' => 'movie', 'speech_rate' => $speed, 'burn_subtitles' => false]);
            [$file, $directory] = $this->render($project, $settings);
            $duration = app(MediaRuntime::class)->probe($file)['format']['duration'];
            $this->assertEqualsWithDelta(10, (float) $duration, 0.12);
            foreach ([0.2, 2.5, 4.65, 4.94, 5.08, 7.5, 9.65] as $start) {
                $samples = $this->samples($file, $start, 0.08);
                $this->assertGreaterThan(0.015, $this->rms($samples), 'Voice must remain audible at '.$start.' seconds, speed '.$speed);
            }
            $this->assertGreaterThan(0.015, $this->tone($this->samples($file, 3, 0.5), 660), 'Time fitting must preserve the selected voice pitch.');
            $this->assertStringContainsString('00:00:05,000 --> 00:00:10,000', file_get_contents($directory.'/recap.srt'));
        }
        Http::assertSentCount(2);
    }

    public function test_background_mix_reaches_video_and_audio_exports_without_original_dialogue(): void
    {
        $project = $this->project();
        $this->voiceFixture('0.12*sin(2*PI*660*t)', 4);
        config(['media.separation_command' => json_encode([config('media.ffmpeg'), '-nostdin', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=220:duration=10:sample_rate=48000', '-ac', '2', '{output}'])]);
        foreach (['burn', 'wav'] as $format) {
            foreach ([false, true] as $duck) {
                [$file] = $this->render($project, MediaSettings::normalize(['mode' => 'dub', 'workflow' => 'movie', 'output' => $format, 'burn_subtitles' => false, 'keep_background' => true, 'audio_track' => 'mix', 'background_volume' => 0.8, 'duck_background' => $duck]));
                $samples = $this->samples($file, 2, 1);
                $this->assertGreaterThan(0.003, $this->tone($samples, 220), 'Background must remain audible, including with ducking enabled.');
                $this->assertGreaterThan(0.015, $this->tone($samples, 660));
                $this->assertLessThan(0.002, $this->tone($samples, 880), 'Never mix original speech back as a background fallback.');
            }
        }
        Http::assertSentCount(2);
    }

    public function test_ducking_restores_background_between_narration_phrases(): void
    {
        $project = $this->project();
        $this->voiceFixture('if(lt(t,1)+gt(t,3),0.4*sin(2*PI*660*t),0)', 4);
        config(['media.separation_command' => json_encode([config('media.ffmpeg'), '-nostdin', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=220:duration=10:sample_rate=48000', '-ac', '2', '{output}'])]);
        [$file] = $this->render($project, MediaSettings::normalize(['mode' => 'dub', 'workflow' => 'movie', 'output' => 'wav', 'burn_subtitles' => false, 'keep_background' => true, 'audio_track' => 'mix', 'background_volume' => .8, 'duck_background' => true]));
        $underSpeech = $this->tone($this->samples($file, .4, .3), 220);
        $betweenPhrases = $this->tone($this->samples($file, 2.5, .3), 220);
        $this->assertGreaterThan($underSpeech * 1.5, $betweenPhrases, 'Music must return after speech pauses.');
        $this->assertGreaterThan(.003, $underSpeech, 'Ducking must not erase background audio.');
    }

    public function test_only_edge_silence_is_trimmed_and_original_cached_voice_remains_unchanged(): void
    {
        $path = Storage::disk('local')->path('pauses.wav');
        app(MediaRuntime::class)->execute('ffmpeg', ['-v', 'error', '-y', '-f', 'lavfi', '-i', 'aevalsrc=if(between(t\,0.4\,1)+between(t\,1.4\,2)\,0.14*sin(2*PI*660*t)\,0):s=48000:d=2.4', $path]);
        $hash = hash_file('sha256', $path);
        $directory = Storage::disk('local')->path('trim');
        mkdir($directory);
        $prepared = app(MediaVoices::class)->prepareScene(['path' => $path], MediaSettings::normalize([]), $directory, fn (): bool => false);
        $this->assertEqualsWithDelta(1.6, $prepared['raw_duration'], 0.04);
        $this->assertLessThan(0.001, $this->rms($this->samples($prepared['path'], 0.72, 0.15)), 'Natural pauses inside the sentence must remain.');
        $this->assertGreaterThan(0.05, $this->rms($this->samples($prepared['path'], 0.1, 0.1)));
        $this->assertSame($hash, hash_file('sha256', $path));
        $cached = app(MediaVoices::class)->prepareScene(['path' => $path], MediaSettings::normalize(['speech_rate' => 2]), $directory, fn (): bool => false);
        $this->assertSame($prepared['path'], $cached['path']);
        $this->assertEqualsWithDelta($prepared['duration'] / 2, $cached['duration'], 0.001);
        Http::assertNothingSent();
    }

    public function test_silent_provider_audio_fails_clearly_instead_of_exporting_a_silent_scene(): void
    {
        $project = $this->project();
        $this->voiceFixture('0', 1);
        $this->expectException(MediaFailure::class);
        $this->expectExceptionMessage('provider_response_invalid');
        $this->render($project, MediaSettings::normalize(['mode' => 'dub', 'workflow' => 'movie', 'burn_subtitles' => false]));
    }

    public function test_long_voice_extends_the_scene_instead_of_cutting_words_and_fallback_preserves_pitch(): void
    {
        $settings = MediaSettings::normalize(['workflow' => 'movie', 'voice_pitch' => 2]);
        $this->assertSame(7.0, MediaRenderer::sceneDuration(['start' => 0, 'end' => 5, 'planned_duration' => 5], $settings, 7.0));
        $this->assertSame(5.0, MediaRenderer::sceneDuration(['start' => 0, 'end' => 5], MediaSettings::normalize(['workflow' => 'translate']), 1.0));
        $voices = app(MediaVoices::class);
        (new \ReflectionProperty($voices, 'rubberband'))->setValue($voices, false);
        $path = Storage::disk('local')->path('fallback.wav');
        app(MediaRuntime::class)->execute('ffmpeg', ['-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=660:sample_rate=48000:duration=1', '-af', $voices->sceneFilter(1, 5, $settings), $path]);
        $this->assertEqualsWithDelta(5, (float) app(MediaRuntime::class)->probe($path)['format']['duration'], .15);
        $this->assertGreaterThan(.01, $this->tone($this->samples($path, 2, 1), 660 * pow(2, 2 / 12)));
    }

    private function project(): MediaProject
    {
        $project = MediaProject::factory()->create(['storage_disk' => 'local', 'metadata' => ['duration' => 10, 'width' => 160, 'height' => 120, 'audio' => true], 'lines' => [
            ['index' => 0, 'start' => 0, 'end' => 5, 'planned_duration' => 5, 'text' => 'The first narration scene.', 'included' => true],
            ['index' => 1, 'start' => 5, 'end' => 10, 'planned_duration' => 5, 'text' => 'The second narration scene.', 'included' => true],
        ]]);
        Storage::disk('local')->makeDirectory(dirname($project->source_path));
        app(MediaRuntime::class)->execute('ffmpeg', ['-v', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=blue:s=160x120:r=30', '-f', 'lavfi', '-i', 'sine=frequency=880:sample_rate=48000', '-t', '10', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-threads', '2', Storage::disk('local')->path($project->source_path)]);

        return $project;
    }

    private function voiceFixture(string $expression, float $duration): void
    {
        $path = Storage::disk('local')->path('voice-fixture.wav');
        $expression = str_replace(',', '\,', $expression);
        app(MediaRuntime::class)->execute('ffmpeg', ['-v', 'error', '-y', '-f', 'lavfi', '-i', 'aevalsrc='.$expression.':s=48000:d='.$duration, $path]);
        $audio = base64_encode(file_get_contents($path));
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['steps' => [['type' => 'model_output', 'content' => [['type' => 'audio', 'mime_type' => 'audio/wav', 'data' => $audio]]]]])]);
    }

    /** @return array{string,string} */
    private function render(MediaProject $project, array $settings): array
    {
        $run = MediaRun::factory()->create(['media_project_id' => $project->id, 'phase' => 'generate', 'settings' => $settings, 'lines' => $project->lines]);
        $directory = Storage::disk('local')->path('media/'.$project->id.'/runs/'.$run->id);
        mkdir($directory, 0755, true);
        $result = app(MediaRenderer::class)->render($run, $directory, fn () => null, fn (): bool => false);
        $file = isset($result['audio']) ? Storage::disk('local')->path($result['audio']) : $directory.'/recap.mp4';

        return [$file, $directory];
    }

    /** @return list<float> */
    private function samples(string $path, float $start, float $duration): array
    {
        $pcm = app(MediaRuntime::class)->execute('ffmpeg', ['-v', 'error', '-ss', (string) $start, '-i', $path, '-t', (string) $duration, '-vn', '-ac', '1', '-ar', '4800', '-f', 'f32le', '-']);

        return array_values(unpack('g*', $pcm));
    }

    private function rms(array $samples): float
    {
        return sqrt(array_sum(array_map(fn (float $value): float => $value * $value, $samples)) / max(1, count($samples)));
    }

    private function tone(array $samples, float $frequency): float
    {
        $real = 0.;
        $imaginary = 0.;
        foreach ($samples as $index => $sample) {
            $angle = 2 * M_PI * $frequency * $index / 4800;
            $real += $sample * cos($angle);
            $imaginary += $sample * sin($angle);
        }

        return hypot($real, $imaginary) / max(1, count($samples));
    }
}
