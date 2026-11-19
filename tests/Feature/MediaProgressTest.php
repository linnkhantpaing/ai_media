<?php

namespace Tests\Feature;

use App\Exceptions\MediaFailure;
use App\Jobs\ImportYouTubeVideo;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Models\User;
use App\Services\Media\MediaPipeline;
use App\Services\Media\MediaRuntime;
use App\Services\Media\YouTubeDownloader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class MediaProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Queue::fake();
        Http::preventStrayRequests();
        config(['filesystems.media_disk' => 'local', 'operations.auto_launch_allowed' => false]);
    }

    public function test_measured_stage_progress_reaches_the_owner_and_queue_without_regression_or_early_completion(): void
    {
        $project = MediaProject::factory()->create();
        $run = MediaRun::factory()->create(['media_project_id' => $project->id, 'phase' => 'generate', 'progress' => 0, 'stages' => ['voice' => 'waiting', 'render' => 'waiting', 'store' => 'waiting'], 'artifacts' => ['cached' => 'preserved']]);
        $project->update(['active_run_id' => $run->id]);
        $pipeline = app(MediaPipeline::class);
        $pipeline->stage($run, 'voice', false, 50);
        $this->assertSame(16, $run->fresh()->progress);
        $pipeline->stage($run, 'voice', false, 20);
        $this->assertSame(50, $run->fresh()->artifacts['stage_progress']);
        $this->actingAs($project->user)->getJson('/media/projects/'.$project->id)
            ->assertOk()->assertJsonPath('project.run.progress', 16)->assertJsonPath('project.run.stageProgress', 50);
        $this->getJson('/media/queue')->assertOk()->assertJsonPath('items.0.progress', 16);
        $pipeline->stage($run, 'voice', true);
        $pipeline->stage($run, 'render', false, 50);
        $this->assertSame(50, $run->fresh()->progress);
        $pipeline->stage($run, 'render', true);
        $pipeline->stage($run, 'store', true);
        $this->assertSame(99, $run->fresh()->progress);
        $this->assertSame('rendering', $run->status);
        $this->assertSame('preserved', $run->artifacts['cached']);
        $this->actingAs(User::factory()->create())->getJson('/media/projects/'.$project->id)->assertNotFound();
    }

    public function test_cancelled_runs_cannot_be_advanced_by_progress_callbacks(): void
    {
        $project = MediaProject::factory()->create();
        $run = MediaRun::factory()->create(['media_project_id' => $project->id, 'cancel_requested' => true, 'progress' => 12]);
        $project->update(['active_run_id' => $run->id]);
        try {
            app(MediaPipeline::class)->stage($run, 'render', false, 90);
            $this->fail('Cancelled work must not report new progress.');
        } catch (MediaFailure $error) {
            $this->assertSame('cancelled', $error->reason);
        }
        $this->assertSame(12, $run->fresh()->progress);
    }

    public function test_import_download_progress_is_persisted_and_retry_starts_a_new_attempt_at_zero(): void
    {
        $project = MediaProject::factory()->create(['source_type' => 'youtube', 'source_url' => 'https://www.youtube.com/watch?v=jNQXAC9IVRw', 'status' => 'importing', 'import_stage' => 'queued']);
        $downloader = Mockery::mock(YouTubeDownloader::class);
        $downloader->shouldReceive('inspect')->andReturn(['title' => 'Fixture', 'duration' => 4, 'parts' => 2]);
        $downloader->shouldReceive('download')->once()->andReturnUsing(function ($url, $directory, $progress, $cancelled, $parts) use ($project): string {
            $this->assertSame(2, $parts);
            $progress(512000, 50);
            $this->assertSame(40, $project->fresh()->source_progress);
            $this->actingAs($project->user)->getJson('/media/projects/'.$project->id)
                ->assertOk()->assertJsonPath('project.sourceProgress', 40)->assertJsonPath('project.import.downloadedBytes', 512000);
            $progress(900000, 99);
            $this->assertSame(74, $project->fresh()->source_progress);
            throw new MediaFailure('youtube_timeout');
        });
        (new ImportYouTubeVideo($project->id))->handle($downloader, app(MediaRuntime::class));
        $this->assertSame('import_failed', $project->fresh()->status);
        $this->assertLessThan(100, $project->source_progress);
        $this->app->instance(YouTubeDownloader::class, $downloader);
        $downloader->shouldReceive('available')->andReturn(true);
        $this->postJson('/media/projects/'.$project->id.'/import/retry')->assertAccepted()->assertJsonPath('project.sourceProgress', 0);
    }

    public function test_ffmpeg_reports_encoded_time_and_preserves_binary_stdout(): void
    {
        $runtime = app(MediaRuntime::class);
        if (! $runtime->available()) {
            $this->markTestSkipped('FFmpeg runtime is required.');
        }
        $percentages = [];
        $output = $runtime->execute('ffmpeg', ['-nostdin', '-v', 'error', '-re', '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=8000', '-t', '2', '-f', 's16le', '-acodec', 'pcm_s16le', '-'], progress: function (int $percent) use (&$percentages): void {
            $percentages[] = $percent;
        }, duration: 2);
        $this->assertSame(0, $percentages[0]);
        $this->assertSame(100, $percentages[array_key_last($percentages)]);
        $this->assertTrue(collect($percentages)->contains(fn (int $percent): bool => $percent > 0 && $percent < 100));
        $sorted = $percentages;
        sort($sorted);
        $this->assertSame($sorted, $percentages);
        $this->assertSame(32000, strlen($output));
    }

    public function test_failed_encoding_does_not_report_one_hundred_percent(): void
    {
        $runtime = app(MediaRuntime::class);
        if (! $runtime->available()) {
            $this->markTestSkipped('FFmpeg runtime is required.');
        }
        $percentages = [];
        try {
            $runtime->execute('ffmpeg', ['-nostdin', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=32x32', '-vf', 'invalid_filter_for_test', '-t', '1', '-f', 'null', '-'], progress: function (int $percent) use (&$percentages): void {
                $percentages[] = $percent;
            }, duration: 1);
            $this->fail('Invalid encoding must fail.');
        } catch (MediaFailure $error) {
            $this->assertSame('invalid_media', $error->reason);
        }
        $this->assertNotContains(100, $percentages);
    }
}
