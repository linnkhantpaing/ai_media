<?php

namespace Tests\Feature;

use App\Jobs\InspectMediaProject;
use App\Jobs\ProcessMediaRun;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Services\Media\MediaPipeline;
use App\Services\Media\MediaRuntime;
use App\Services\Media\MediaStorage;
use App\Services\UsageLedger;
use App\Support\MediaSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('r2');
        config(['filesystems.media_disk' => 'local', 'media.gemini_key' => 'test-provider-key']);
        Http::preventStrayRequests();
        if (! app(MediaRuntime::class)->available()) {
            $this->markTestSkipped('Install the configured FFmpeg and FFprobe runtime to run media integration tests.');
        }
    }

    public function test_real_inspection_archives_source_and_poster_and_restores_private_r2_video(): void
    {
        $project = $this->fixture(['storage_disk' => 'r2']);
        $source = Storage::disk('local')->get($project->source_path);
        app()->call([new InspectMediaProject($project->id), 'handle']);
        $project->refresh();
        $this->assertSame('ready', $project->status);
        $this->assertSame(320, $project->metadata['width']);
        $this->assertTrue($project->metadata['audio']);
        Storage::disk('r2')->assertExists($project->source_path);
        Storage::disk('r2')->assertExists('media/'.$project->id.'/poster.jpg');
        Storage::disk('local')->delete($project->source_path);
        $restored = app(MediaStorage::class)->materialize($project, $project->source_path);
        $this->assertSame(hash('sha256', $source), hash_file('sha256', $restored));
        $this->actingAs($project->user)->get('/media/projects/'.$project->id.'/files/source')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_corrupt_video_and_no_audio_never_become_ready(): void
    {
        $project = MediaProject::factory()->create(['status' => 'validating', 'metadata' => null, 'expected_bytes' => 10]);
        Storage::disk('local')->put($project->source_path, 'notavideo!');
        app()->call([new InspectMediaProject($project->id), 'handle']);
        $this->assertSame('invalid', $project->fresh()->status);
        $silent = $this->fixture(audio: false);
        app()->call([new InspectMediaProject($silent->id), 'handle']);
        $this->assertSame('no_audio', $silent->fresh()->error_code);
        $this->assertNull($silent->fresh()->metadata);
    }

    public function test_real_pipeline_pauses_for_review_then_renders_and_archives_valid_mp4_and_srt(): void
    {
        $this->fakeProvider();
        $project = $this->ready(['storage_disk' => 'r2']);
        $run = $this->runFor($project);
        $usage = app(UsageLedger::class)->reserve($run);
        (new ProcessMediaRun($run->id))->handle(app(MediaPipeline::class));
        $project->refresh();
        $this->assertSame('awaiting_review', $project->status);
        $this->assertSame('released', $usage->fresh()->status);
        $this->assertCount(2, $project->lines);
        $this->assertNull($project->output_path);
        Http::assertSentCount(2);
        $run->refresh()->forceFill(['phase' => 'generate', 'status' => 'queued'])->save();
        app(UsageLedger::class)->reserve($run);
        (new ProcessMediaRun($run->id))->handle(app(MediaPipeline::class));
        $project->refresh();
        $this->assertSame('completed', $project->status, (string) $project->error_code);
        $this->assertSame(100, $run->fresh()->progress);
        $this->assertSame('charged', $usage->fresh()->status);
        $this->assertSame((int) ceil($project->metadata['duration']), $usage->fresh()->charged_seconds);
        $this->assertSame(1, $usage->fresh()->charged_videos);
        Storage::disk('r2')->assertExists($project->output_path);
        Storage::disk('r2')->assertExists($project->subtitle_path);
        $srt = Storage::disk('local')->get($project->subtitle_path);
        $this->assertStringContainsString('မင်္ဂလာပါ', $srt);
        $this->assertStringContainsString('00:00:00,200 --> 00:00:01,000', $srt);
        $probe = app(MediaRuntime::class)->probe(Storage::disk('local')->path($project->output_path));
        $this->assertSame('h264', collect($probe['streams'])->firstWhere('codec_type', 'video')['codec_name']);
        $this->assertNotNull(collect($probe['streams'])->firstWhere('codec_type', 'audio'));
        Http::assertSentCount(2);
    }

    public function test_srt_only_finishes_without_tts_or_video_encoding(): void
    {
        $this->fakeProvider();
        $project = $this->ready(['settings' => [...MediaSettings::DEFAULTS, 'output' => 'srt', 'review_before_render' => false]]);
        $run = $this->runFor($project);
        (new ProcessMediaRun($run->id))->handle(app(MediaPipeline::class));
        $this->assertSame('completed', $project->fresh()->status, (string) $project->fresh()->error_code);
        $this->assertNull($project->fresh()->output_path);
        Storage::disk('local')->assertExists($project->fresh()->subtitle_path);
        Http::assertSentCount(2);
    }

    public function test_full_dub_uses_real_audio_processing_and_extends_video_to_keep_all_narration(): void
    {
        $this->fakeProvider(speechDuration: 2.4);
        $project = $this->ready(['settings' => [...MediaSettings::DEFAULTS, 'mode' => 'dub', 'review_before_render' => false, 'aspect' => '9:16', 'fill' => 'blur']]);
        $run = $this->runFor($project);
        (new ProcessMediaRun($run->id))->handle(app(MediaPipeline::class));
        $project->refresh();
        $this->assertSame('completed', $project->status, (string) $project->error_code);
        $this->assertGreaterThan(5, $project->metadata['output']['duration']);
        $this->assertSame(240, $project->metadata['output']['width']);
        $this->assertSame(426, $project->metadata['output']['height']);
        Http::assertSentCount(4);
        $this->assertStringContainsString('00:00:02,600', Storage::disk('local')->get($project->subtitle_path));
    }

    public function test_crop_pad_and_manual_cover_render_real_readable_files(): void
    {
        foreach (['crop', 'pad'] as $fill) {
            $this->fakeProvider();
            $project = $this->ready(['settings' => [...MediaSettings::DEFAULTS, 'review_before_render' => false, 'aspect' => '1:1', 'fill' => $fill, 'cover_mode' => 'manual', 'cover_style' => $fill === 'crop' ? 'dark_box' : 'blur_strip']]);
            $run = $this->runFor($project);
            (new ProcessMediaRun($run->id))->handle(app(MediaPipeline::class));
            $this->assertSame('completed', $project->fresh()->status, (string) $project->fresh()->error_code);
            $this->assertSame(240, $project->fresh()->metadata['output']['height']);
            $this->assertSame(240, $project->fresh()->metadata['output']['width']);
        }
    }

    public function test_silence_malformed_provider_output_and_cancelled_runs_never_publish_fake_results(): void
    {
        $responses = [[], [['start' => 2, 'end' => 1, 'source_text' => 'bad']]];
        $sequence = Http::fakeSequence();
        foreach ($responses as $segments) {
            $sequence->push($this->response(['summary' => '', 'segments' => $segments]));
        }
        foreach ($responses as $segments) {
            $project = $this->ready();
            $run = $this->runFor($project);
            (new ProcessMediaRun($run->id))->handle(app(MediaPipeline::class));
            $this->assertSame('failed', $project->fresh()->status);
            $this->assertSame($segments === [] ? 'no_speech' : 'provider_response_invalid', $project->fresh()->error_code);
            $this->assertNull($project->fresh()->output_path);
        }
        Http::fake();
        $project = $this->ready();
        $run = $this->runFor($project);
        $run->update(['cancel_requested' => true]);
        (new ProcessMediaRun($run->id))->handle(app(MediaPipeline::class));
        $this->assertSame('cancelled', $project->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_duration_limits_are_checked_from_actual_media(): void
    {
        $project = $this->fixture();
        config(['media.max_duration_seconds' => 1]);
        app()->call([new InspectMediaProject($project->id), 'handle']);
        $this->assertSame('duration_exceeded', $project->fresh()->error_code);
    }

    public function test_retry_resumes_from_saved_transcript_without_transcribing_twice(): void
    {
        $sequence = Http::fakeSequence();
        $sequence->push($this->response(['summary' => 'Greeting', 'segments' => [['start' => 0.2, 'end' => 1, 'source_text' => 'Hello']]]));
        $sequence->push(['error' => ['message' => 'quota']], 429, ['Retry-After' => '1']);
        $sequence->push($this->response(['translations' => [['index' => 0, 'text' => 'မင်္ဂလာပါ']]]));
        $project = $this->ready();
        $run = $this->runFor($project);
        $job = new ProcessMediaRun($run->id);
        $job->handle(app(MediaPipeline::class));
        $this->assertSame('retry_waiting', $run->fresh()->status);
        $this->assertCount(1, $run->fresh()->lines);
        $this->travel(2)->seconds();
        $job->handle(app(MediaPipeline::class));
        $this->assertSame('awaiting_review', $project->fresh()->status);
        Http::assertSentCount(3);
    }

    public function test_re_render_reuses_unchanged_voice_cache_across_runs(): void
    {
        $this->fakeProvider();
        $project = $this->ready(['settings' => [...MediaSettings::DEFAULTS, 'mode' => 'dub', 'review_before_render' => false]]);
        $first = $this->runFor($project);
        (new ProcessMediaRun($first->id))->handle(app(MediaPipeline::class));
        $this->assertSame('completed', $project->fresh()->status);
        Http::assertSentCount(4);
        $lines = $project->fresh()->lines;
        $lines[0]['text'] = 'ပြန်လည်ပြင်ဆင်ထားသော စာမူ';
        $second = $this->runFor($project->fresh());
        $second->update(['phase' => 'generate', 'lines' => $lines]);
        (new ProcessMediaRun($second->id))->handle(app(MediaPipeline::class));
        $this->assertSame('completed', $project->fresh()->status, (string) $project->fresh()->error_code);
        Http::assertSentCount(5);
    }

    public function test_stale_job_cannot_overwrite_the_new_active_run(): void
    {
        Http::fake();
        $project = $this->ready();
        $old = $this->runFor($project);
        $new = $this->runFor($project);
        (new ProcessMediaRun($old->id))->handle(app(MediaPipeline::class));
        $this->assertSame($new->id, $project->fresh()->active_run_id);
        $this->assertSame('queued', $project->fresh()->status);
        $this->assertSame('cancelled', $old->fresh()->status);
        Http::assertNothingSent();
    }

    private function fixture(array $attributes = [], bool $audio = true): MediaProject
    {
        $project = MediaProject::factory()->create(['status' => 'validating', 'metadata' => null, ...$attributes]);
        Storage::disk('local')->makeDirectory('media/'.$project->id);
        $path = Storage::disk('local')->path($project->source_path);
        $args = ['-nostdin', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=size=320x240:rate=24'];
        if ($audio) {
            array_push($args, '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000', '-c:a', 'aac');
        }
        array_push($args, '-t', '3.2', '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-threads', '1', $path);
        app(MediaRuntime::class)->execute('ffmpeg', $args);
        clearstatcache(true, $path);
        $project->update(['expected_bytes' => filesize($path), 'uploaded_bytes' => filesize($path)]);

        return $project;
    }

    private function ready(array $attributes = []): MediaProject
    {
        $project = $this->fixture($attributes);
        app()->call([new InspectMediaProject($project->id), 'handle']);
        $this->assertSame('ready', $project->fresh()->status);

        return $project->fresh();
    }

    private function runFor(MediaProject $project): MediaRun
    {
        $run = MediaRun::factory()->for($project, 'project')->create(['settings' => $project->settings, 'lines' => [], 'stages' => []]);
        $project->update(['active_run_id' => $run->id, 'status' => 'queued']);

        return $run;
    }

    private function response(array $data): array
    {
        return ['steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE)]]]]];
    }

    private function fakeProvider(float $speechDuration = 0.9): void
    {
        $voice = Storage::disk('local')->path('test-voice.wav');
        app(MediaRuntime::class)->execute('ffmpeg', ['-nostdin', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=660:sample_rate=24000', '-t', (string) $speechDuration, '-ac', '1', $voice]);
        $audio = base64_encode(file_get_contents($voice));
        Http::fake(['generativelanguage.googleapis.com/*' => function (Request $request) use ($audio) {
            $this->assertTrue($request->hasHeader('x-goog-api-key', 'test-provider-key'));
            if (($request['response_format']['type'] ?? '') === 'audio') {
                return Http::response(['steps' => [['type' => 'model_output', 'content' => [['type' => 'audio', 'mime_type' => 'audio/wav', 'data' => $audio]]]]]);
            }
            if (isset($request['response_format']['schema']['properties']['segments'])) {
                return Http::response($this->response(['summary' => 'A greeting.', 'segments' => [
                    ['start' => 0.2, 'end' => 1.0, 'source_text' => 'Hello.'],
                    ['start' => 1.3, 'end' => 2.8, 'source_text' => 'Welcome.'],
                ]]));
            }

            return Http::response($this->response(['translations' => [['index' => 0, 'text' => 'မင်္ဂလာပါ'], ['index' => 1, 'text' => 'ကြိုဆိုပါသည်']]]));
        }]);
    }
}
