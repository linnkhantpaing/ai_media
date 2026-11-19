<?php

namespace Tests\Feature;

use App\Exceptions\MediaFailure;
use App\Http\Controllers\MediaToolsController;
use App\Jobs\PrepareMediaPreview;
use App\Jobs\ProcessMediaRun;
use App\Models\MediaPreview;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Models\User;
use App\Services\Media\GeminiClient;
use App\Services\Media\MediaNarrationFit;
use App\Services\Media\MediaPreviews;
use App\Services\Media\MediaRenderer;
use App\Services\Media\MediaRuntime;
use App\Services\Media\MediaVoices;
use App\Support\MediaSettings;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\Create;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MediaNarrationFitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('r2');
        Queue::fake();
        config(['filesystems.media_disk' => 'local', 'media.gemini_key' => 'test-key']);
        Http::preventStrayRequests();
        if (! app(MediaRuntime::class)->available()) {
            $this->markTestSkipped('FFmpeg is required.');
        }
    }

    public function test_measured_expansion_preserves_thirty_seconds_and_effective_fast_speech_without_overwriting_reviewed_text(): void
    {
        $project = $this->project(1.5);
        $this->provider();
        $original = $project->lines;
        $directory = Storage::disk('local')->path('media/'.$project->id.'/fit-work');
        mkdir($directory);
        $progress = [];
        $result = app(MediaNarrationFit::class)->fit($project, $original, $project->settings, $directory, fn (): bool => false, function (int $percent) use (&$progress): void {
            $progress[] = $percent;
        });
        $this->assertTrue($result['ready']);
        $this->assertSame($original, $project->fresh()->lines);
        $this->assertSame('More supported detail about the same journey.', $result['scenes'][0]['text']);
        $this->assertSame('scene-one', $result['scenes'][0]['scene_id']);
        $this->assertSame('Keep the journey clear.', $result['scenes'][0]['note']);
        $this->assertEqualsWithDelta(30, $result['measurements'][0]['actual'], .05);
        $this->assertEqualsWithDelta(30, $result['scenes'][0]['planned_duration'], .001);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request): bool => str_contains(json_encode($request->data()), 'measured_seconds') && str_contains(json_encode($request->data()), 'A journey through the forest'));
        $again = app(MediaNarrationFit::class)->fit($project, $original, $project->settings, $directory, fn (): bool => false, fn () => null);
        $this->assertSame($result['signature'], $again['signature']);
        Http::assertSentCount(3);
        $run = MediaRun::factory()->create(['media_project_id' => $project->id, 'settings' => [...$project->settings, 'output' => 'wav'], 'lines' => $result['scenes']]);
        $renderDirectory = Storage::disk('local')->path('media/'.$project->id.'/runs/'.$run->id);
        mkdir($renderDirectory, 0755, true);
        $output = app(MediaRenderer::class)->render($run, $renderDirectory, fn () => null, fn (): bool => false);
        $probe = app(MediaRuntime::class)->probe(Storage::disk('local')->path($output['audio']));
        $this->assertEqualsWithDelta(30, (float) $probe['format']['duration'], .05);
        $this->assertEqualsWithDelta(30, MediaRenderer::sceneDuration($result['scenes'][0], $project->settings, 90), .001);
        $this->assertSame(95, end($progress));
        Http::assertSentCount(3);
    }

    public function test_preview_is_private_and_only_applied_measured_text_can_unlock_export_at_the_same_speed(): void
    {
        $project = $this->project(1);
        $this->provider();
        $payload = ['title' => $project->title, 'settings' => $project->settings, 'kind' => 'fit'];
        $this->actingAs(User::factory()->create())->postJson('/media/projects/'.$project->id.'/previews', $payload)->assertNotFound();
        $id = $this->actingAs($project->user)->postJson('/media/projects/'.$project->id.'/previews', $payload)->assertAccepted()->json('preview.id');
        $preview = MediaPreview::findOrFail($id);
        app(MediaPreviews::class)->process($preview);
        $preview->refresh();
        $this->assertSame('completed', $preview->status);
        $this->assertTrue($preview->result['ready']);
        $this->postJson('/media/projects/'.$project->id.'/previews', [...$payload, 'line' => 0, 'start' => 15, 'duration' => 10])->assertOk()->assertJsonPath('preview.id', $id);
        app(MediaNarrationFit::class)->requireReady($project);
        $project->settings = [...$project->settings, 'speech_rate' => 1.25];
        try {
            app(MediaNarrationFit::class)->requireReady($project);
            $this->fail('Changing speaking speed must invalidate the old fit.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('settings.speech_rate', $exception->errors());
        }
        $this->assertNull($preview->path);
        $this->assertSame(1, MediaPreview::where('kind', 'fit')->count());
        Http::assertSentCount(1);
    }

    public function test_expanded_script_must_be_applied_and_remeasured_after_a_manual_edit_before_generation(): void
    {
        $project = $this->project(1.5);
        $this->provider();
        $this->actingAs($project->user);
        $id = $this->postJson('/media/projects/'.$project->id.'/previews', ['title' => $project->title, 'settings' => $project->settings, 'kind' => 'fit'])->assertAccepted()->json('preview.id');
        $preview = MediaPreview::findOrFail($id);
        app(MediaPreviews::class)->process($preview);
        $preview->refresh();
        $this->postJson('/media/projects/'.$project->id.'/generate')->assertUnprocessable()->assertJsonValidationErrors('settings.speech_rate');
        $opened = $this->getJson('/media/projects/'.$project->id)->assertOk();
        $saved = $this->patchJson('/media/projects/'.$project->id.'/scenes', ['revision' => $opened->json('project.scriptRevision'), 'lines' => $preview->result['scenes']])->assertOk();
        app(MediaNarrationFit::class)->requireReady($project->fresh());
        $edited = $preview->result['scenes'];
        $edited[0]['text'] .= ' A manual correction.';
        $changed = $this->patchJson('/media/projects/'.$project->id.'/scenes', ['revision' => $saved->json('project.scriptRevision'), 'lines' => $edited])->assertOk();
        $this->postJson('/media/projects/'.$project->id.'/generate')->assertUnprocessable()->assertJsonValidationErrors('settings.speech_rate');
        $this->patchJson('/media/projects/'.$project->id.'/scenes', ['revision' => $changed->json('project.scriptRevision'), 'lines' => $preview->result['scenes']])->assertOk();
        $this->postJson('/media/projects/'.$project->id.'/generate')->assertAccepted();
        $this->assertSame('queued', $project->fresh()->status);
        $this->assertSame(1.5, (float) $project->fresh()->activeRun->settings['speech_rate']);
        Queue::assertPushed(ProcessMediaRun::class);
    }

    public function test_slow_speech_shortens_the_script_instead_of_changing_the_fixed_video_duration(): void
    {
        $project = $this->project(.75);
        $this->provider(22.5);
        $directory = Storage::disk('local')->path('slow-fit');
        mkdir($directory);
        $result = app(MediaNarrationFit::class)->fit($project, $project->lines, $project->settings, $directory, fn (): bool => false, fn () => null);
        $this->assertTrue($result['ready']);
        $this->assertEqualsWithDelta(30, $result['measurements'][0]['actual'], .05);
        $this->assertEqualsWithDelta(30, $result['target'], .001);
        Http::assertSentCount(3);
    }

    public function test_normal_speed_also_keeps_the_target_without_unnecessary_rewriting_and_cosmetic_edits_reuse_the_fit(): void
    {
        $project = $this->project(1);
        $this->provider();
        $directory = Storage::disk('local')->path('normal-fit');
        mkdir($directory);
        $result = app(MediaNarrationFit::class)->fit($project, $project->lines, $project->settings, $directory, fn (): bool => false, fn () => null);
        $this->assertTrue($result['ready']);
        $this->assertSame($project->lines[0]['text'], $result['scenes'][0]['text']);
        Http::assertSentCount(1);
        $this->assertSame($result['signature'], MediaNarrationFit::signature($project, $result['scenes'], [...$project->settings, 'subtitle_color' => '#FF0000', 'background_volume' => 1]));
    }

    public function test_locked_or_insufficient_duration_is_never_faked_with_silence_or_a_changed_speed(): void
    {
        $project = $this->project(1.5);
        $this->provider();
        $lines = $project->lines;
        $lines[0]['locked'] = true;
        $directory = Storage::disk('local')->path('locked-fit');
        mkdir($directory);
        $result = app(MediaNarrationFit::class)->fit($project, $lines, $project->settings, $directory, fn (): bool => false, fn () => null);
        $this->assertFalse($result['ready']);
        $this->assertEqualsWithDelta(20, $result['measurements'][0]['actual'], .05);
        $this->assertSame($lines[0]['text'], $result['scenes'][0]['text']);
        Http::assertSentCount(1);
        $this->expectException(MediaFailure::class);
        app(MediaVoices::class)->sceneFilter(30, 30, $project->settings);
    }

    public function test_cancelled_fit_does_no_provider_work_and_subtitles_cannot_use_narration_fitting(): void
    {
        $project = $this->project(1);
        $directory = Storage::disk('local')->path('cancel-fit');
        mkdir($directory);
        try {
            app(MediaNarrationFit::class)->fit($project, $project->lines, $project->settings, $directory, fn (): bool => true, fn () => null);
            $this->fail('Expected cancellation.');
        } catch (MediaFailure $failure) {
            $this->assertSame('cancelled', $failure->reason);
        }
        Http::assertNothingSent();
        $this->actingAs($project->user)->postJson('/media/projects/'.$project->id.'/previews', ['title' => $project->title, 'settings' => [...$project->settings, 'mode' => 'subs'], 'kind' => 'fit'])->assertUnprocessable();
    }

    public function test_pending_scenes_are_rewritten_in_one_bounded_batch_and_completed_measurements_are_reused(): void
    {
        $project = $this->project(1.5);
        $lines = [];
        for ($i = 0; $i < 3; $i++) {
            $lines[] = [...$project->lines[0], 'index' => $i, 'scene_id' => 'scene-'.$i, 'start' => $i * 30, 'end' => ($i + 1) * 30, 'text' => 'Original '.$i];
        }
        $settings = [...$project->settings, 'target_seconds' => 90];
        $project->update(['lines' => $lines, 'settings' => $settings, 'metadata' => [...$project->metadata, 'duration' => 120]]);
        $this->mock(MediaVoices::class, function ($mock): void {
            $mock->shouldReceive('line')->times(6)->andReturnUsing(fn ($project, $line) => ['duration' => str_starts_with($line['text'], 'Original') ? 20 : 30]);
            $mock->shouldReceive('prepareScene')->times(6)->andReturnUsing(fn ($voice) => $voice);
        });
        $this->mock(GeminiClient::class, function ($mock): void {
            $mock->shouldReceive('structuredAsync')->once()->withArgs(function ($input): bool {
                $data = json_decode($input[1]['text'], true);

                return count($data['scenes']) === 3 && $data['scenes'][0]['target_characters'] > mb_strlen($data['scenes'][0]['current_narration']);
            })->andReturn(Create::promiseFor(['scenes' => array_map(fn ($i) => ['scene' => $i, 'text' => 'Factual expansion '.$i, 'subtitle_text' => 'Factual expansion '.$i], range(0, 2))]));
        });
        $directory = Storage::disk('local')->path('batch-fit');
        mkdir($directory);
        $service = app(MediaNarrationFit::class);
        $result = $service->fit($project, $lines, $settings, $directory, fn () => false, fn () => null);
        $this->assertTrue($result['ready']);
        $this->assertSame(['scene-0', 'scene-1', 'scene-2'], array_column($result['scenes'], 'scene_id'));
        $this->assertEquals(90, array_sum(array_column($result['measurements'], 'actual')));
        $this->assertEquals($result, $service->fit($project, $lines, $settings, $directory, fn () => false, fn () => null));
        $this->assertSame($lines, $project->fresh()->lines);
    }

    public function test_retry_resumes_after_a_verified_scene_without_measuring_it_again(): void
    {
        $project = $this->project(1);
        $lines = [$project->lines[0], [...$project->lines[0], 'index' => 1, 'scene_id' => 'scene-two', 'start' => 30, 'end' => 60]];
        $settings = [...$project->settings, 'target_seconds' => 60];
        $project->update(['lines' => $lines, 'settings' => $settings]);
        $this->mock(MediaVoices::class, function ($mock): void {
            $mock->shouldReceive('line')->twice()->andReturn(['duration' => 30]);
            $mock->shouldReceive('prepareScene')->twice()->andReturnUsing(fn ($voice) => $voice);
        });
        $directory = Storage::disk('local')->path('resume-fit');
        mkdir($directory);
        $service = app(MediaNarrationFit::class);
        try {
            $service->fit($project, $lines, $settings, $directory, fn () => false, function ($percent, $work): void {
                if (($work['scene'] ?? 0) === 2) {
                    throw new MediaFailure('provider_rate_limit', true, 90);
                }
            });
            $this->fail('Expected retryable provider interruption.');
        } catch (MediaFailure $failure) {
            $this->assertSame('provider_rate_limit', $failure->reason);
        }
        $result = $service->fit($project, $lines, $settings, $directory, fn () => false, fn () => null);
        $this->assertTrue($result['ready']);
        $this->assertCount(2, $result['measurements']);
        Http::assertNothingSent();
    }

    public function test_rate_limited_preview_keeps_progress_and_exposes_its_actual_retry_time(): void
    {
        $project = $this->project(1.2);
        $preview = MediaPreview::factory()->create(['media_project_id' => $project->id, 'kind' => 'fit', 'status' => 'running', 'progress' => 77, 'settings' => $project->settings, 'lines' => $project->lines, 'result' => ['work' => ['phase' => 'rewriting', 'scene' => 3, 'total' => 5, 'completed' => 4]]]);
        $service = \Mockery::mock(MediaPreviews::class);
        $service->shouldReceive('process')->once()->andThrow(new MediaFailure('provider_rate_limit', true, 90));
        $queue = \Mockery::mock(Job::class);
        $queue->shouldReceive('attempts')->andReturn(1);
        $queue->shouldReceive('release')->once()->with(90);
        $job = new PrepareMediaPreview($preview->id);
        $job->setJob($queue);
        $job->handle($service);
        $preview->refresh();
        $this->assertSame('queued', $preview->status);
        $this->assertSame(77, $preview->progress);
        $this->assertSame(4, $preview->result['work']['completed']);
        $this->assertEqualsWithDelta(90, now()->diffInSeconds(CarbonImmutable::parse($preview->result['retryAt'])), 2);
        $data = app(MediaToolsController::class)->data($preview);
        $this->assertSame($preview->result['retryAt'], $data['result']['retryAt']);
        $this->assertSame('provider_rate_limit', $data['errorCode']);
    }

    public function test_measured_clip_slots_keep_the_total_and_delivery_speed_without_unnecessary_ai_rewrites(): void
    {
        $project = $this->project(1.5);
        $lines = [$project->lines[0], [...$project->lines[0], 'index' => 1, 'scene_id' => 'scene-two', 'start' => 30, 'end' => 60]];
        $settings = [...$project->settings, 'target_seconds' => 60];
        $project->update(['lines' => $lines, 'settings' => $settings]);
        $this->mock(MediaVoices::class, function ($mock): void {
            $mock->shouldReceive('line')->twice()->andReturn(['duration' => 24], ['duration' => 36]);
            $mock->shouldReceive('prepareScene')->twice()->andReturnUsing(fn ($voice) => $voice);
        });
        $directory = Storage::disk('local')->path('balanced-fit');
        mkdir($directory);
        $result = app(MediaNarrationFit::class)->fit($project, $lines, $settings, $directory, fn () => false, fn () => null);
        $this->assertTrue($result['ready']);
        $this->assertEquals(60, array_sum(array_column($result['scenes'], 'planned_duration')));
        $this->assertEquals(24, $result['scenes'][0]['planned_duration']);
        $this->assertEquals(36, $result['scenes'][1]['planned_duration']);
        $this->assertSame(array_column($lines, 'text'), array_column($result['scenes'], 'text'));
        $this->assertSame(array_column($lines, 'start'), array_column($result['scenes'], 'start'));
        $this->assertSame($lines, $project->fresh()->lines);
        Http::assertNothingSent();
    }

    private function project(float $rate): MediaProject
    {
        $settings = MediaSettings::normalize(['workflow' => 'movie', 'mode' => 'dub', 'fit_script_to_speed' => true, 'target_seconds' => 30, 'speech_rate' => $rate, 'burn_subtitles' => false]);
        $line = ['scene_id' => 'scene-one', 'index' => 0, 'start' => 10, 'end' => 20, 'planned_duration' => 30, 'source_text' => 'A journey through the forest. The traveler reaches the village.', 'text' => 'The traveler reaches the village.', 'subtitle_text' => 'The traveler reaches the village.', 'included' => true, 'locked' => false, 'note' => 'Keep the journey clear.'];
        $project = MediaProject::factory()->create(['storage_disk' => 'local', 'settings' => $settings, 'status' => 'awaiting_review', 'metadata' => ['duration' => 60, 'width' => 160, 'height' => 120, 'audio' => true], 'lines' => [$line]]);
        Storage::disk('local')->makeDirectory('media/'.$project->id);
        app(MediaRuntime::class)->execute('ffmpeg', ['-v', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=blue:s=160x120:r=15', '-f', 'lavfi', '-i', 'sine=frequency=880:sample_rate=48000', '-t', '60', '-c:v', 'libx264', '-threads', '2', '-c:a', 'aac', Storage::disk('local')->path($project->source_path)]);
        $run = MediaRun::factory()->create(['media_project_id' => $project->id, 'phase' => 'analysis', 'status' => 'awaiting_review', 'settings' => $settings, 'lines' => [$line]]);
        $project->update(['active_run_id' => $run->id]);

        return $project;
    }

    private function provider(float $rewrittenDuration = 45): void
    {
        $audio = [];
        foreach ([30, $rewrittenDuration] as $duration) {
            $file = Storage::disk('local')->path('voice-'.$duration.'.wav');
            app(MediaRuntime::class)->execute('ffmpeg', ['-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=660:sample_rate=24000:duration='.$duration, $file]);
            $audio[(string) $duration] = base64_encode(file_get_contents($file));
        }
        Http::fake(['generativelanguage.googleapis.com/*' => function ($request) use ($audio, $rewrittenDuration) {
            $speech = ($request['response_format']['type'] ?? '') === 'audio';
            $text = $request['input'][0]['content'][0]['text'] ?? '';
            $duration = $text === 'The traveler reaches the village.' ? 30 : $rewrittenDuration;
            $content = $speech ? ['type' => 'audio', 'mime_type' => 'audio/wav', 'data' => $audio[(string) $duration]] : ['type' => 'text', 'text' => json_encode(['text' => 'More supported detail about the same journey.', 'subtitle_text' => 'More supported detail about the same journey.'])];

            return Http::response(['steps' => [['type' => 'model_output', 'content' => [$content]]]]);
        }]);
    }
}
