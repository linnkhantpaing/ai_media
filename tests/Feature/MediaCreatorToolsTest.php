<?php

namespace Tests\Feature;

use App\Jobs\PrepareMediaPreview;
use App\Jobs\ProcessMediaRun;
use App\Models\CustomerPackageAssignment;
use App\Models\MediaPreset;
use App\Models\MediaPreview;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Models\MediaTask;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;
use App\Services\Media\MediaPipeline;
use App\Services\Media\MediaRuntime;
use App\Support\MediaSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaCreatorToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('r2');
        config(['filesystems.media_disk' => 'local', 'media.gemini_key' => 'test-key', 'media.separation_command' => null, 'media.separation_python' => 'missing-python']);
        Http::preventStrayRequests();
        if (! app(MediaRuntime::class)->available()) {
            $this->markTestSkipped('FFmpeg is required.');
        }
    }

    public function test_audio_exports_contain_real_audio_and_reuse_narration_cache(): void
    {
        config(['media.font_directory' => 'missing-font-directory']);
        $this->provider();
        $project = $this->project();
        foreach (['mp3', 'wav'] as $format) {
            $settings = [...MediaSettings::DEFAULTS, 'mode' => 'dub', 'output' => $format, 'audio_track' => 'narration'];
            $project->update(['settings' => $settings]);
            $run = $this->createRun($project);
            (new ProcessMediaRun($run->id))->handle(app(MediaPipeline::class));
            $project->refresh();
            $this->assertSame('completed', $project->status, (string) $project->error_code);
            $this->assertNull($project->output_path);
            $this->assertStringEndsWith('.'.$format, $project->audio_path);
            $probe = app(MediaRuntime::class)->probe(Storage::disk('local')->path($project->audio_path));
            $this->assertCount(1, $probe['streams']);
            $this->assertSame('audio', $probe['streams'][0]['codec_type']);
            $this->assertSame($format === 'mp3' ? 'mp3' : 'pcm_s16le', $probe['streams'][0]['codec_name']);
            $this->actingAs($project->user)->get('/media/projects/'.$project->id.'/files/audio?download=1')->assertOk();
            $task = MediaTask::where('media_run_id', $run->id)->where('type', 'generate')->firstOrFail();
            $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/operations/jobs/'.$task->id.'/files/audio?download=1')->assertOk();
            $this->actingAs(User::factory()->create())->get('/media/projects/'.$project->id.'/files/audio')->assertNotFound();
        }
        Http::assertSentCount(2);
    }

    public function test_video_without_burned_subtitles_keeps_separate_srt(): void
    {
        config(['media.font_directory' => 'missing-font-directory']);
        $this->provider();
        $project = $this->project();
        $project->update(['settings' => [...MediaSettings::DEFAULTS, 'mode' => 'dub', 'burn_subtitles' => false]]);
        $run = $this->createRun($project);
        (new ProcessMediaRun($run->id))->handle(app(MediaPipeline::class));
        $this->assertSame('completed', $project->fresh()->status);
        Storage::disk('local')->assertExists($project->fresh()->output_path);
        Storage::disk('local')->assertExists($project->fresh()->subtitle_path);
    }

    public function test_real_background_mix_has_instrumental_tone_without_original_dialogue_tone(): void
    {
        $this->provider();
        $project = $this->project();
        config(['media.separation_command' => json_encode([config('media.ffmpeg'), '-nostdin', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=220:duration=3:sample_rate=48000', '-ac', '2', '{output}'])]);
        $project->update(['settings' => [...MediaSettings::DEFAULTS, 'mode' => 'dub', 'output' => 'wav', 'keep_background' => true, 'background_volume' => 0.8, 'duck_background' => false]]);
        $run = $this->createRun($project);
        (new ProcessMediaRun($run->id))->handle(app(MediaPipeline::class));
        $project->refresh();
        $this->assertSame('completed', $project->status, (string) $project->error_code);
        $pcm = Storage::disk('local')->path('mix.pcm');
        app(MediaRuntime::class)->execute('ffmpeg', ['-nostdin', '-v', 'error', '-y', '-ss', '0.25', '-t', '0.5', '-i', Storage::disk('local')->path($project->audio_path), '-ac', '1', '-ar', '4800', '-f', 'f32le', $pcm]);
        $values = array_values(unpack('g*', file_get_contents($pcm)));
        $energy = function (int $frequency) use ($values): float {
            $a = 0.;
            $b = 0.;
            foreach ($values as $i => $value) {
                $angle = 2 * M_PI * $frequency * $i / 4800;
                $a += $value * cos($angle);
                $b += $value * sin($angle);
            }

            return hypot($a, $b) / count($values);
        };
        $this->assertGreaterThan(0.015, $energy(220));
        $this->assertGreaterThan(0.015, $energy(660));
        $this->assertLessThan(0.005, $energy(880));
        $this->assertSame('completed', $run->fresh()->stages['separate']);
    }

    public function test_voice_preview_is_owned_cached_and_does_not_change_script_or_outputs(): void
    {
        Queue::fake([PrepareMediaPreview::class]);
        $this->provider();
        $project = $this->project();
        $payload = ['title' => $project->title, 'settings' => [...MediaSettings::DEFAULTS, 'mode' => 'dub'], 'kind' => 'voice', 'line' => 0];
        $url = '/media/projects/'.$project->id.'/previews';
        $response = $this->actingAs($project->user)->postJson($url, $payload)->assertAccepted();
        $id = $response->json('preview.id');
        Queue::assertPushed(PrepareMediaPreview::class, 1);
        app()->call([new PrepareMediaPreview($id), 'handle']);
        $record = MediaPreview::findOrFail($id);
        $this->assertSame('completed', $record->status, (string) $record->error_code);
        $this->assertNull($project->fresh()->audio_path);
        $this->getJson('/media/projects/'.$project->id)->assertJsonPath('project.latestPreview.id', $id)->assertJsonPath('project.previewBusy', false);
        $this->assertSame('Hello', $project->fresh()->lines[0]['source_text']);
        $this->get('/media/projects/'.$project->id.'/previews/'.$id.'/file')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('preview.id', $id);
        Queue::assertPushed(PrepareMediaPreview::class, 1);
        Http::assertSentCount(1);
        $this->actingAs(User::factory()->create())->getJson('/media/projects/'.$project->id.'/previews/'.$id)->assertNotFound();
        $this->get('/media/projects/'.$project->id.'/previews/'.$id.'/file')->assertNotFound();
    }

    public function test_timing_report_measures_actual_voice_and_shortening_does_not_auto_replace_script(): void
    {
        Queue::fake([PrepareMediaPreview::class]);
        $this->provider(2.4);
        $project = $this->project();
        $payload = ['title' => $project->title, 'settings' => [...MediaSettings::DEFAULTS, 'mode' => 'dub'], 'kind' => 'timing'];
        $url = '/media/projects/'.$project->id.'/previews';
        $id = $this->actingAs($project->user)->postJson($url, $payload)->assertAccepted()->json('preview.id');
        app()->call([new PrepareMediaPreview($id), 'handle']);
        $record = MediaPreview::findOrFail($id);
        $this->assertSame('completed', $record->status, (string) $record->error_code);
        $this->assertSame(2, $record->result['warningCount']);
        $this->assertGreaterThan(2.3, $record->result['lines'][0]['voice']);
        $this->assertGreaterThan(1.3, $record->result['lines'][0]['extra']);
        $payload['kind'] = 'shorten';
        $payload['line'] = 0;
        $before = $project->fresh()->lines;
        $id = $this->postJson($url, $payload)->assertAccepted()->json('preview.id');
        app()->call([new PrepareMediaPreview($id), 'handle']);
        $this->assertSame('Short Burmese text', MediaPreview::findOrFail($id)->result['text']);
        $this->assertSame($before, $project->fresh()->lines);
    }

    public function test_video_preview_is_a_real_private_sample_and_never_replaces_published_result(): void
    {
        Queue::fake([PrepareMediaPreview::class]);
        $this->provider();
        $project = $this->project();
        $payload = ['title' => $project->title, 'settings' => [...MediaSettings::DEFAULTS, 'mode' => 'dub'], 'kind' => 'video', 'start' => 0, 'duration' => 3];
        $id = $this->actingAs($project->user)->postJson('/media/projects/'.$project->id.'/previews', $payload)->assertAccepted()->json('preview.id');
        app()->call([new PrepareMediaPreview($id), 'handle']);
        $record = MediaPreview::findOrFail($id);
        $this->assertSame('completed', $record->status, (string) $record->error_code);
        $this->assertNotNull($record->path);
        $probe = app(MediaRuntime::class)->probe(Storage::disk('local')->path($record->path));
        $this->assertNotNull(collect($probe['streams'])->firstWhere('codec_type', 'video'));
        $this->assertNull($project->fresh()->output_path);
        $this->assertDatabaseHas('media_tasks', ['source_key' => 'preview:'.$id, 'status' => 'completed', 'type' => 'preview']);
    }

    public function test_pending_preview_prevents_conflicting_edits_and_can_be_cancelled(): void
    {
        Queue::fake([PrepareMediaPreview::class]);
        $project = $this->project();
        $url = '/media/projects/'.$project->id;
        $payload = ['title' => $project->title, 'settings' => [...MediaSettings::DEFAULTS, 'mode' => 'dub'], 'kind' => 'voice'];
        $id = $this->actingAs($project->user)->postJson($url.'/previews', $payload)->assertAccepted()->json('preview.id');
        $this->patchJson($url, ['title' => 'Changed', 'settings' => $project->settings])->assertConflict();
        $this->postJson($url.'/previews', [...$payload, 'line' => 1])->assertConflict();
        $this->postJson($url.'/previews/'.$id.'/cancel')->assertOk();
        app()->call([new PrepareMediaPreview($id), 'handle']);
        $this->assertSame('cancelled', MediaPreview::find($id)->status);
        Http::assertNothingSent();
        $this->patchJson($url, ['title' => 'Changed', 'settings' => $project->settings])->assertOk();
    }

    public function test_presets_are_owned_normalized_and_limited_without_exposing_other_accounts(): void
    {
        $user = User::factory()->create();
        $payload = ['title' => 'My recap', 'settings' => [...MediaSettings::DEFAULTS, 'mode' => 'dub', 'output' => 'mp3']];
        $id = $this->actingAs($user)->postJson('/media/presets', $payload)->assertCreated()->json('preset.id');
        $this->postJson('/media/presets', [...$payload, 'settings' => [...$payload['settings'], 'voice' => 'Charon']])->assertCreated()->assertJsonPath('preset.id', $id);
        $this->getJson('/media/presets')->assertJsonCount(1, 'presets')->assertJsonPath('presets.0.settings.voice', 'Charon');
        $this->actingAs(User::factory()->create())->getJson('/media/presets')->assertJsonCount(0, 'presets');
        $this->deleteJson('/media/presets/'.$id)->assertNotFound();
        $this->actingAs($user)->deleteJson('/media/presets/'.$id)->assertOk();
        MediaPreset::factory()->count(12)->create(['user_id' => $user->id]);
        $this->postJson('/media/presets', $payload)->assertUnprocessable();
    }

    public function test_invalid_export_and_preview_requests_are_rejected(): void
    {
        $project = $this->project();
        $this->actingAs($project->user)->patchJson('/media/projects/'.$project->id, ['title' => 'Invalid', 'settings' => [...MediaSettings::DEFAULTS, 'output' => 'mp3']])->assertUnprocessable()->assertJsonValidationErrors('settings.output');
        $this->postJson('/media/projects/'.$project->id.'/previews', ['title' => 'Invalid', 'kind' => 'shell', 'settings' => MediaSettings::DEFAULTS])->assertUnprocessable();
        $this->postJson('/media/presets', ['title' => 'Invalid', 'settings' => [...MediaSettings::DEFAULTS, 'voice' => 'untrusted']])->assertUnprocessable();
    }

    public function test_narration_only_needs_no_separator_and_background_mix_failure_is_explicit(): void
    {
        $project = $this->project();
        $settings = [...MediaSettings::DEFAULTS, 'mode' => 'dub', 'output' => 'wav', 'audio_track' => 'narration', 'keep_background' => true];
        $this->actingAs($project->user)->patchJson('/media/projects/'.$project->id, ['title' => $project->title, 'settings' => $settings])->assertOk()->assertJsonPath('project.settings.keep_background', false);
        $this->patchJson('/media/projects/'.$project->id, ['title' => $project->title, 'settings' => [...$settings, 'audio_track' => 'mix']])->assertUnprocessable()->assertJsonValidationErrors('settings.keep_background');
        Queue::fake([PrepareMediaPreview::class]);
        $this->provider();
        $payload = ['title' => $project->title, 'settings' => [...MediaSettings::DEFAULTS, 'mode' => 'dub'], 'kind' => 'voice'];
        $id = $this->postJson('/media/projects/'.$project->id.'/previews', $payload)->assertAccepted()->json('preview.id');
        app()->call([new PrepareMediaPreview($id), 'handle']);
        $record = MediaPreview::findOrFail($id);
        Storage::disk('local')->delete($record->path);
        $this->get('/media/projects/'.$project->id.'/previews/'.$id.'/file')->assertNotFound();
    }

    private function project(): MediaProject
    {
        $project = MediaProject::factory()->create(['storage_disk' => 'local', 'metadata' => ['duration' => 3., 'width' => 320, 'height' => 240, 'audio' => true, 'aspect' => '4:3'], 'lines' => [['index' => 0, 'start' => 0.2, 'end' => 1., 'source_text' => 'Hello', 'text' => 'Hello Burmese'], ['index' => 1, 'start' => 1.3, 'end' => 2.8, 'source_text' => 'Welcome', 'text' => 'Welcome Burmese']]]);
        Storage::disk('local')->makeDirectory('media/'.$project->id);
        app(MediaRuntime::class)->execute('ffmpeg', ['-nostdin', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=blue:s=320x240:r=15', '-f', 'lavfi', '-i', 'sine=frequency=880:sample_rate=48000', '-t', '3', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-threads', '2', Storage::disk('local')->path($project->source_path)]);

        return $project;
    }

    private function createRun(MediaProject $project): MediaRun
    {
        $run = MediaRun::factory()->create(['media_project_id' => $project->id, 'phase' => 'generate', 'status' => 'queued', 'settings' => $project->settings, 'lines' => $project->lines, 'stages' => []]);
        $project->update(['active_run_id' => $run->id, 'status' => 'queued']);

        return $run;
    }

    private function provider(float $duration = 0.9): void
    {
        $path = Storage::disk('local')->path('voice-fixture.wav');
        app(MediaRuntime::class)->execute('ffmpeg', ['-nostdin', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=660:sample_rate=24000', '-t', (string) $duration, '-ac', '1', $path]);
        $audio = base64_encode(file_get_contents($path));
        Http::fake(['generativelanguage.googleapis.com/*' => function ($request) use ($audio) {
            return Http::response(['steps' => [['type' => 'model_output', 'content' => ($request['response_format']['type'] ?? '') === 'audio' ? [['type' => 'audio', 'mime_type' => 'audio/wav', 'data' => $audio]] : [['type' => 'text', 'text' => json_encode(['text' => 'Short Burmese text'])]]]]]);
        }]);
    }

    public function test_plan_daily_preview_limit_counts_new_previews_only(): void
    {
        Queue::fake([PrepareMediaPreview::class]);
        config(['media.studio_testing' => false]);
        $this->provider();
        $project = $this->project();
        $base = Package::factory()->make()->definition;
        $definition = [...$base, 'preview_daily_limit' => 2, 'features' => [...$base['features'], 'video_preview' => true, 'dub' => true]];
        $package = Package::factory()->create(['status' => 'active', 'published_version' => 1, 'definition' => $definition]);
        $version = PackageVersion::create(['package_id' => $package->id, 'number' => 1, 'definition' => $definition]);
        CustomerPackageAssignment::create(['user_id' => $project->user_id, 'package_id' => $package->id, 'package_version_id' => $version->id, 'source' => 'payment_new',
            'snapshot' => ['name' => 'Plan', 'code' => $package->code, 'type' => 'paid', 'version' => 1, 'definition' => $definition], 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(20)]);
        $url = '/media/projects/'.$project->id.'/previews';
        $payload = ['title' => $project->title, 'settings' => [...MediaSettings::DEFAULTS, 'mode' => 'dub'], 'kind' => 'video', 'duration' => 1];

        $first = $this->actingAs($project->user)->postJson($url, [...$payload, 'start' => 0])->assertAccepted()->json('preview.id');
        MediaPreview::whereKey($first)->update(['status' => 'completed']);
        // Reopening a ready preview returns the cached one and does not use the allowance.
        $this->postJson($url, [...$payload, 'start' => 0])->assertOk();
        $second = $this->postJson($url, [...$payload, 'start' => 1])->assertAccepted()->json('preview.id');
        MediaPreview::whereKey($second)->update(['status' => 'completed']);
        $this->postJson($url, [...$payload, 'start' => 2])->assertStatus(429)->assertJsonPath('code', 'preview_limit');

        // A failed preview gives its turn back.
        MediaPreview::whereKey($second)->update(['status' => 'failed']);
        $this->postJson($url, [...$payload, 'start' => 2])->assertAccepted();
        $this->assertSame(3, MediaPreview::count());
    }
}
