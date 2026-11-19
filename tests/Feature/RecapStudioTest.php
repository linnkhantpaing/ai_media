<?php

namespace Tests\Feature;

use App\Exceptions\MediaFailure;
use App\Models\CustomerPackageAssignment;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;
use App\Services\Media\GeminiClient;
use App\Services\Media\MediaBackground;
use App\Services\Media\MediaRenderer;
use App\Services\Media\MediaRuntime;
use App\Services\Media\MediaStorage;
use App\Services\Media\MediaVoices;
use App\Services\Media\RecapPlanner;
use App\Support\MediaSettings;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Tests\TestCase;

class RecapStudioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('r2');
        config(['filesystems.media_disk' => 'local', 'media.gemini_key' => 'test-key']);
        Http::preventStrayRequests();
    }

    public function test_scene_edits_are_owned_revision_checked_and_saved_to_the_active_run(): void
    {
        $project = $this->review();
        $payload = $this->payload($project);
        $payload['lines'][0]['text'] = 'An edited narration';
        $this->actingAs(User::factory()->create())->patchJson('/media/projects/'.$project->id.'/scenes', $payload)->assertNotFound();
        $this->actingAs($project->user)->patchJson('/media/projects/'.$project->id.'/scenes', $payload)->assertOk()->assertJsonPath('project.lines.0.text', 'An edited narration');
        $this->assertSame('An edited narration', $project->fresh()->activeRun->lines[0]['text']);
        $this->patchJson('/media/projects/'.$project->id.'/scenes', $payload)->assertUnprocessable()->assertJsonValidationErrors('lines');
    }

    public function test_scene_validation_rejects_out_of_source_bounds_empty_scripts_and_busy_projects(): void
    {
        $project = $this->review();
        $this->actingAs($project->user);
        $payload = $this->payload($project);
        $payload['lines'][0]['end'] = 999;
        $this->patchJson('/media/projects/'.$project->id.'/scenes', $payload)->assertUnprocessable()->assertJsonValidationErrors('lines.0.end');
        $payload = $this->payload($project);
        $payload['lines'][0]['included'] = false;
        $this->patchJson('/media/projects/'.$project->id.'/scenes', $payload)->assertUnprocessable();
        $project->update(['status' => 'rendering']);
        $this->patchJson('/media/projects/'.$project->id.'/scenes', $this->payload($project))->assertConflict();
    }

    public function test_movie_scenes_can_be_reordered_but_translation_cannot_have_overlapping_dialogue(): void
    {
        $project = $this->review();
        $payload = $this->payload($project);
        $payload['lines'][] = [...$payload['lines'][0], 'index' => 1, 'start' => 1, 'end' => 2];
        $this->actingAs($project->user)->patchJson('/media/projects/'.$project->id.'/scenes', $payload)->assertOk();
        $project->refresh()->update(['settings' => MediaSettings::normalize(['workflow' => 'translate'])]);
        $this->patchJson('/media/projects/'.$project->id.'/scenes', $this->payload($project))->assertUnprocessable();
    }

    public function test_story_changes_require_confirmation_but_voice_changes_keep_the_script(): void
    {
        $project = $this->review();
        $this->actingAs($project->user);
        $settings = [...$project->settings, 'voice_pitch' => 2];
        $this->patchJson('/media/projects/'.$project->id, ['title' => $project->title, 'settings' => $settings])->assertOk()->assertJsonCount(1, 'project.lines');
        $settings['story_order'] = 'hook';
        $this->patchJson('/media/projects/'.$project->id, ['title' => $project->title, 'settings' => $settings])->assertConflict()->assertJsonPath('code', 'confirm_reanalysis');
        $this->patchJson('/media/projects/'.$project->id, ['title' => $project->title, 'settings' => $settings, 'confirm_reanalysis' => true])->assertOk()->assertJsonCount(0, 'project.lines')->assertJsonPath('project.status', 'ready');
        $this->assertNull($project->fresh()->active_run_id);
    }

    public function test_untrusted_fonts_and_invalid_long_movie_settings_are_rejected(): void
    {
        $project = $this->review();
        $this->actingAs($project->user)->patchJson('/media/projects/'.$project->id, ['title' => $project->title, 'settings' => [...$project->settings, 'subtitle_font' => '../evil', 'target_seconds' => 900]])->assertUnprocessable()->assertJsonValidationErrors(['settings.subtitle_font']);
        $this->patchJson('/media/projects/'.$project->id, ['title' => $project->title, 'settings' => [...$project->settings, 'mode' => 'subs']])->assertUnprocessable()->assertJsonValidationErrors('settings.mode');
    }

    public function test_visual_catalog_covers_every_source_chunk_and_is_reused_on_resume(): void
    {
        $project = MediaProject::factory()->create(['metadata' => ['duration' => 500, 'width' => 320, 'height' => 240, 'audio' => true], 'settings' => MediaSettings::normalize(['workflow' => 'movie', 'mode' => 'dub', 'target_seconds' => 60])]);
        Storage::disk('local')->put($project->source_path, 'source');
        $run = MediaRun::factory()->create(['media_project_id' => $project->id, 'settings' => $project->settings]);
        $runtime = Mockery::mock(MediaRuntime::class);
        $runtime->shouldReceive('execute')->times(3)->andReturnUsing(function ($tool, $args): string {
            file_put_contents(end($args), 'chapter');

            return '';
        });
        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('structuredAsync')->times(4)->andReturnUsing(function ($input, $schema): PromiseInterface {
            if (isset($schema['properties']['events'])) {
                return new FulfilledPromise(['summary' => 'Actual chapter', 'events' => [['start' => 1, 'end' => 10, 'source_text' => 'A visible action.']]]);
            }
            $catalog = json_decode($input[1]['text'], true);
            $this->assertSame([0, 240, 480], array_column($catalog, 'start'));
            $this->assertSame(481, $catalog[2]['events'][0]['start']);

            return new FulfilledPromise(['scenes' => [['start' => 241, 'end' => 250, 'source_text' => 'Visible action', 'text' => 'Coherent narration', 'subtitle_text' => 'Translated subtitle', 'planned_duration' => 15]]]);
        });
        $planner = new RecapPlanner($runtime, $gemini, app(MediaStorage::class));
        $directory = Storage::disk('local')->path('plan');
        mkdir($directory);
        $lines = $planner->analyze($run, $directory, fn () => null);
        $this->assertSame(60.0, $lines[0]['planned_duration']);
        $this->assertEquals($lines, $planner->analyze($run->fresh(), $directory, fn () => null));
        $this->assertCount(3, $run->fresh()->artifacts['catalog']);
    }

    public function test_malformed_model_timestamps_never_reach_ffmpeg(): void
    {
        $this->expectException(MediaFailure::class);
        app(RecapPlanner::class)->validate([['start' => 0, 'end' => 500, 'source_text' => 'fake']], 10, false);
    }

    public function test_real_movie_render_omits_unselected_source_gaps_and_exports_styled_subtitles(): void
    {
        $runtime = app(MediaRuntime::class);
        if (! $runtime->available()) {
            $this->markTestSkipped('FFmpeg is required.');
        }
        $project = $this->review();
        Storage::disk('local')->makeDirectory(dirname($project->source_path));
        $runtime->execute('ffmpeg', ['-nostdin', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=blue:s=320x240:r=15', '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000', '-t', '8', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-threads', '2', Storage::disk('local')->path($project->source_path)]);
        $runtime->execute('ffmpeg', ['-nostdin', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=660:sample_rate=24000', '-t', '1', '-ac', '1', Storage::disk('local')->path('voice.wav')]);
        $audio = base64_encode(Storage::disk('local')->get('voice.wav'));
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['steps' => [['type' => 'model_output', 'content' => [['type' => 'audio', 'mime_type' => 'audio/wav', 'data' => $audio]]]]])]);
        $run = $project->activeRun;
        $run->update(['phase' => 'generate', 'settings' => [...$project->settings, 'target_seconds' => 1, 'burn_subtitles' => false, 'voice_pitch' => 2], 'lines' => [[...$project->lines[0], 'start' => 4, 'end' => 5, 'planned_duration' => 1, 'subtitle_text' => 'Separate subtitle'], [...$project->lines[0], 'index' => 1, 'included' => false]]]);
        $directory = Storage::disk('local')->path('media/'.$project->id.'/runs/'.$run->id);
        mkdir($directory, 0755, true);
        $result = app(MediaRenderer::class)->render($run, $directory, fn () => null, fn () => false);
        $this->assertLessThan(1.3, $result['metadata']['duration']);
        $this->assertGreaterThan(.8, $result['metadata']['duration']);
        $this->assertSame(1, $result['metadata']['segments']);
        $this->assertStringContainsString('Separate subtitle', file_get_contents($directory.'/recap.srt'));
        $this->assertFileExists($directory.'/recap.ass');
        $this->assertTrue($result['metadata']['styled']);
        $clip = $directory.'/clip-00000.mp4';
        touch($clip, 946684800);
        app(MediaRenderer::class)->render($run, $directory, fn () => null, fn () => false);
        clearstatcache(true, $clip);
        $this->assertSame(946684800, filemtime($clip));
        Http::assertSentCount(1);
    }

    public function test_all_three_studio_pages_render_owned_project_data_without_credentials(): void
    {
        $project = $this->review();
        $this->actingAs($project->user);
        foreach (['/projects/new', '/projects/'.$project->id.'/upload', '/projects/'.$project->id.'/analyze'] as $path) {
            $this->get($path)->assertOk()->assertDontSee('test-key');
        }
        if (getenv('RECAP_UI_CAPTURE')) {
            $this->withVite();
            $project->update(['title' => 'Recap Studio Preview', 'metadata' => ['duration' => 7200, 'width' => 1920, 'height' => 1080, 'audio' => true, 'aspect' => '16:9'], 'settings' => MediaSettings::normalize(['workflow' => 'movie', 'mode' => 'dub', 'target_seconds' => 600])]);
            file_put_contents(sys_get_temp_dir().'/recap-source-qa.html', $this->get('/projects/'.$project->id.'/upload')->getContent());
            file_put_contents(sys_get_temp_dir().'/recap-review-qa.html', $this->get('/projects/'.$project->id.'/analyze')->getContent());
            $project->activeRun->update(['phase' => 'generate']);
            file_put_contents(sys_get_temp_dir().'/recap-render-qa.html', $this->get('/projects/'.$project->id.'/analyze')->getContent());
        }
    }

    public function test_completed_live_edits_reopen_and_queue_new_settings_without_losing_the_previous_output(): void
    {
        Queue::fake();
        $project = $this->review();
        $project->activeRun->update(['phase' => 'generate', 'status' => 'completed']);
        $project->update(['status' => 'completed', 'output_path' => 'media/'.$project->id.'/previous.mp4']);
        $previousRun = $project->active_run_id;
        $this->actingAs(User::factory()->create())->postJson('/media/projects/'.$project->id.'/reopen')->assertNotFound();
        $opened = $this->actingAs($project->user)->postJson('/media/projects/'.$project->id.'/reopen')->assertOk();
        $revision = $opened->json('project.scriptRevision');
        $this->assertNotSame($previousRun, $project->fresh()->active_run_id);
        $this->patchJson('/media/projects/'.$project->id.'/scenes', ['revision' => $revision, 'lines' => $project->lines])->assertOk();
        $settings = [...$project->settings, 'subtitle_color' => '#72E2C3', 'subtitle_size' => 32, 'aspect' => '9:16', 'cover_mode' => 'manual', 'cover_top' => .7, 'cover_height' => .1];
        $this->patchJson('/media/projects/'.$project->id, ['title' => $project->title, 'settings' => $settings])->assertOk();
        $this->postJson('/media/projects/'.$project->id.'/generate')->assertAccepted();
        $project->refresh();
        $this->assertSame('queued', $project->status);
        $this->assertSame('#72E2C3', $project->activeRun->settings['subtitle_color']);
        $this->assertSame('9:16', $project->activeRun->settings['aspect']);
        $this->assertSame('media/'.$project->id.'/previous.mp4', $project->output_path);
        $this->assertSame($project->lines, $project->activeRun->lines);
        $this->postJson('/media/projects/'.$project->id.'/generate')->assertConflict();
    }

    public function test_pitch_extremes_keep_the_requested_speaking_speed(): void
    {
        $runtime = app(MediaRuntime::class);
        if (! $runtime->available()) {
            $this->markTestSkipped('FFmpeg is required.');
        }
        foreach ([[0.5, 4], [2, -4]] as [$speed, $pitch]) {
            $file = Storage::disk('local')->path('pitch-'.$speed.'.wav');
            $filter = MediaVoices::filter(MediaSettings::normalize(['speech_rate' => $speed, 'voice_pitch' => $pitch]));
            $runtime->execute('ffmpeg', ['-nostdin', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=660:sample_rate=24000:duration=2', '-af', $filter, $file]);
            $duration = (float) $runtime->probe($file)['format']['duration'];
            $this->assertEqualsWithDelta(2 / $speed, $duration, 0.15);
        }
    }

    public function test_package_editing_and_preview_permissions_are_enforced_and_upload_limits_are_shown(): void
    {
        $project = $this->review();
        $admin = User::factory()->create(['is_admin' => true]);
        $package = Package::factory()->create(['status' => 'active', 'published_version' => 1]);
        $definition = $package->definition;
        $definition['features']['script_edit'] = false;
        $definition['features']['voice_preview'] = false;
        $version = PackageVersion::create(['package_id' => $package->id, 'number' => 1, 'definition' => $definition, 'actor_id' => $admin->id]);
        CustomerPackageAssignment::create(['user_id' => $project->user_id, 'package_id' => $package->id, 'package_version_id' => $version->id, 'assigned_by' => $admin->id, 'source' => 'account_creation', 'snapshot' => ['id' => $package->id, 'name' => $package->name, 'code' => $package->code, 'type' => 'paid', 'version' => 1, 'definition' => $definition], 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(29), 'allowance_starts_at' => now()->subDay()]);
        $this->actingAs($project->user)->get('/projects/new')->assertInertia(fn (AssertableInertia $page) => $page->where('features.script_edit', false)->where('limits.duration', 300)->where('limits.bytes', 104857600));
        $this->patchJson('/media/projects/'.$project->id.'/scenes', $this->payload($project))->assertOk();
        $payload = $this->payload($project->fresh());
        $payload['lines'][0]['text'] = 'Unauthorized edit';
        $this->patchJson('/media/projects/'.$project->id.'/scenes', $payload)->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->postJson('/media/projects/'.$project->id.'/previews', ['title' => $project->title, 'settings' => $project->settings, 'kind' => 'voice'])->assertUnprocessable()->assertJsonValidationErrors('kind');
        Http::assertNothingSent();
    }

    public function test_subtitle_transcription_retries_invalid_timing_and_checkpoints_short_chapters(): void
    {
        $project = MediaProject::factory()->create(['metadata' => ['duration' => 171.642, 'audio' => true], 'settings' => MediaSettings::normalize(['workflow' => 'subtitles', 'mode' => 'subs'])]);
        Storage::disk('local')->put($project->source_path, 'source');
        $run = MediaRun::factory()->create(['media_project_id' => $project->id, 'settings' => $project->settings]);
        $runtime = Mockery::mock(MediaRuntime::class);
        $runtime->shouldReceive('execute')->times(3)->andReturnUsing(function ($tool, $args): string {
            file_put_contents(end($args), 'chapter');

            return '';
        });
        $calls = 0;
        Http::fake(['generativelanguage.googleapis.com/*' => function ($request) use (&$calls) {
            $prompt = $request['input'][0]['text'];
            if (str_starts_with($prompt, 'Transcribe')) {
                $calls++;
                $this->assertStringContainsString('This audio clip is exactly', $prompt);
                $response = ['summary' => 'Chapter context', 'segments' => [
                    ['start' => $calls === 1 ? 172.96 : .2, 'end' => $calls === 1 ? 178.6 : 2, 'source_text' => 'Real dialogue'],
                ]];
            } else {
                $lines = json_decode($request['input'][1]['text'], true);
                $response = ['translations' => array_map(fn ($line) => ['index' => $line['index'], 'text' => 'Translated dialogue'], $lines)];
            }

            return Http::response(['steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => json_encode($response)]]]]]);
        }]);
        $planner = new RecapPlanner($runtime, app(GeminiClient::class), app(MediaStorage::class));
        $directory = Storage::disk('local')->path('short-chapters');
        mkdir($directory);
        $lines = $planner->analyze($run, $directory, fn () => null);
        $this->assertSame(4, $calls);
        $this->assertCount(3, $lines);
        $this->assertEquals([.2, 60.2, 120.2], array_column($lines, 'start'));
        $this->assertSame(60, $run->fresh()->artifacts['catalog_chunk_seconds']);
        $this->assertEquals($lines, $planner->analyze($run->fresh(), $directory, fn () => null));
        Http::assertSentCount(5);
    }

    public function test_persistently_invalid_chapter_is_subdivided_without_dropping_dialogue(): void
    {
        $project = MediaProject::factory()->create(['metadata' => ['duration' => 60, 'audio' => true], 'settings' => MediaSettings::normalize(['workflow' => 'subtitles', 'mode' => 'subs'])]);
        Storage::disk('local')->put($project->source_path, 'source');
        $run = MediaRun::factory()->create(['media_project_id' => $project->id, 'settings' => $project->settings]);
        $runtime = Mockery::mock(MediaRuntime::class);
        $runtime->shouldReceive('execute')->times(3)->andReturnUsing(function ($tool, $args): string {
            file_put_contents(end($args), 'chapter');

            return '';
        });
        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('transcribeAsync')->with(Mockery::type('string'), 'auto', 60.0, Mockery::type('callable'))->twice()->andReturn(new FulfilledPromise(['summary' => 'Invalid timing', 'segments' => [['start' => 59, 'end' => 78, 'source_text' => 'Dialogue']]]));
        $gemini->shouldReceive('transcribeAsync')->with(Mockery::type('string'), 'auto', 30.0, Mockery::type('callable'))->twice()->andReturn(new FulfilledPromise(['summary' => 'Actual context', 'segments' => [['start' => 1, 'end' => 3, 'source_text' => 'Dialogue']]]));
        $gemini->shouldReceive('translateAsync')->once()->andReturn(new FulfilledPromise(['translations' => [['index' => 0, 'text' => 'First half'], ['index' => 1, 'text' => 'Second half']]]));
        $planner = new RecapPlanner($runtime, $gemini, app(MediaStorage::class));
        $directory = Storage::disk('local')->path('split-chapters');
        mkdir($directory);
        $lines = $planner->analyze($run, $directory, fn () => null);
        $this->assertEquals([1, 31], array_column($lines, 'start'));
        $this->assertSame(['First half', 'Second half'], array_column($lines, 'text'));
    }

    public function test_scene_audio_and_subtitle_switches_affect_real_subtitle_and_dub_exports(): void
    {
        $runtime = app(MediaRuntime::class);
        if (! $runtime->available()) {
            $this->markTestSkipped('FFmpeg is required.');
        }
        $project = $this->review();
        $project->update(['metadata' => ['duration' => 2, 'width' => 320, 'height' => 240, 'audio' => true]]);
        Storage::disk('local')->makeDirectory(dirname($project->source_path));
        $source = Storage::disk('local')->path($project->source_path);
        $runtime->execute('ffmpeg', ['-nostdin', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=blue:s=320x240:r=30', '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000', '-t', '2', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-threads', '2', $source]);
        $runtime->execute('ffmpeg', ['-nostdin', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=660:sample_rate=24000', '-t', '0.7', '-ac', '1', Storage::disk('local')->path('voice.wav')]);
        $audio = base64_encode(Storage::disk('local')->get('voice.wav'));
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['steps' => [['type' => 'model_output', 'content' => [['type' => 'audio', 'mime_type' => 'audio/wav', 'data' => $audio]]]]])]);
        $background = Mockery::mock(MediaBackground::class);
        $background->shouldReceive('obtain')->andReturn($source);
        $this->app->instance(MediaBackground::class, $background);
        $base = [...$project->lines[0], 'start' => 0, 'end' => 1, 'planned_duration' => 1, 'subtitle_text' => 'Hidden caption', 'audio_muted' => true, 'subtitle_hidden' => true];
        $rows = [$base, [...$base, 'index' => 1, 'start' => 1, 'end' => 2, 'text' => 'Second voice', 'subtitle_text' => 'Visible caption', 'audio_muted' => false, 'subtitle_hidden' => false]];
        foreach (['subs', 'dub'] as $mode) {
            $run = $project->activeRun;
            $run->update(['phase' => 'generate', 'settings' => [...$project->settings, 'workflow' => $mode === 'dub' ? 'movie' : 'subtitles', 'mode' => $mode, 'burn_subtitles' => true, 'keep_background' => $mode === 'dub'], 'lines' => $rows]);
            $directory = Storage::disk('local')->path('media/'.$project->id.'/runs/'.$run->id.'/'.$mode);
            mkdir($directory, 0755, true);
            $result = app(MediaRenderer::class)->render($run, $directory, fn () => null, fn () => false);
            $this->assertEqualsWithDelta(2, $result['metadata']['duration'], .2);
            $srt = file_get_contents($directory.'/recap.srt');
            $this->assertStringNotContainsString('Hidden caption', $srt);
            $this->assertStringContainsString('Visible caption', $srt);
            $energy = function (float $start) use ($runtime, $directory): float {
                $pcm = $runtime->execute('ffmpeg', ['-v', 'error', '-ss', (string) $start, '-i', $directory.'/recap.mp4', '-t', '0.3', '-vn', '-ac', '1', '-ar', '8000', '-f', 's16le', '-']);
                $samples = array_values(unpack('v*', $pcm));

                return array_sum(array_map(fn (int $value): int => min($value, 65536 - $value), $samples)) / count($samples);
            };
            $this->assertLessThan(15, $energy(.3), $mode.' muted scene must be silent, including background.');
            $this->assertGreaterThan(300, $energy(1.2), $mode.' unmuted scene must retain audio.');
            $run->update(['lines' => array_map(fn (array $line): array => [...$line, 'start' => $line['start'] + 0.2, 'end' => $line['end'] - 0.2, 'subtitle_hidden' => true, 'audio_muted' => true], $rows)]);
            app(MediaRenderer::class)->render($run, $directory, fn () => null, fn () => false);
            $this->assertSame('', file_get_contents($directory.'/recap.srt'));
            $this->assertStringNotContainsString('Dialogue:', file_get_contents($directory.'/recap.ass'));
            $this->assertLessThan(15, $energy(1.2));
            $this->assertLessThan(15, $energy(0.9), 'Whole-track mute must also silence gaps between scenes.');
        }
    }

    public function test_manual_subtitle_cover_renders_with_narrow_and_two_pixel_blur_bands(): void
    {
        $runtime = app(MediaRuntime::class);
        if (! $runtime->available()) {
            $this->markTestSkipped('FFmpeg is required.');
        }
        $project = $this->review();
        $project->update(['metadata' => ['duration' => 1, 'width' => 640, 'height' => 360, 'audio' => true]]);
        Storage::disk('local')->makeDirectory(dirname($project->source_path));
        $runtime->execute('ffmpeg', ['-nostdin', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=s=640x360:r=15', '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000', '-t', '1', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-threads', '2', Storage::disk('local')->path($project->source_path)]);
        $run = $project->activeRun;
        foreach ([['smart_blur', .09], ['smart_blur', .006], ['blur_strip', .09], ['blur_strip', .006]] as $index => [$style, $height]) {
            $run->update(['settings' => [...$project->settings, 'workflow' => 'subtitles', 'mode' => 'subs', 'cover_mode' => 'manual', 'cover_style' => $style, 'cover_top' => .78812307463905, 'cover_height' => $height, 'burn_subtitles' => true], 'lines' => [[...$project->lines[0], 'start' => 0, 'end' => 1]]]);
            $directory = Storage::disk('local')->path('blur-case-'.$index);
            mkdir($directory, 0755, true);
            $result = app(MediaRenderer::class)->render($run, $directory, fn () => null, fn () => false);
            $this->assertSame('mp4', $result['metadata']['format']);
            $this->assertSame(640, $result['metadata']['width']);
            $this->assertSame(360, $result['metadata']['height']);
            $this->assertGreaterThan(0, $result['metadata']['duration']);
            $this->assertGreaterThan(100, filesize($directory.'/recap.mp4'));
        }
        Http::assertNothingSent();
    }

    private function review(): MediaProject
    {
        $line = ['index' => 0, 'start' => 1, 'end' => 3, 'source_text' => 'A real event.', 'text' => 'A narrated event.', 'included' => true, 'locked' => false, 'note' => ''];
        $project = MediaProject::factory()->create(['status' => 'awaiting_review', 'metadata' => ['duration' => 60, 'width' => 320, 'height' => 240, 'audio' => true], 'settings' => MediaSettings::normalize(['workflow' => 'movie', 'mode' => 'dub', 'target_seconds' => 30]), 'lines' => [$line]]);
        $run = MediaRun::factory()->create(['media_project_id' => $project->id, 'status' => 'awaiting_review', 'settings' => $project->settings, 'lines' => [$line]]);
        $project->update(['active_run_id' => $run->id]);

        return $project;
    }

    private function payload(MediaProject $project): array
    {
        return ['revision' => hash('sha256', json_encode($project->lines)), 'lines' => $project->lines];
    }
}
