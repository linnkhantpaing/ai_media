<?php

namespace Tests\Feature;

use App\Exceptions\MediaFailure;
use App\Jobs\ImportYouTubeVideo;
use App\Jobs\InspectMediaProject;
use App\Models\MediaProject;
use App\Models\User;
use App\Services\Media\MediaRuntime;
use App\Services\Media\YouTubeDownloader;
use App\Support\YouTubeUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class YouTubeImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('r2');
        Queue::fake();
        Http::preventStrayRequests();
        config(['filesystems.media_disk' => 'local', 'media.max_upload_bytes' => 5 * 1024 * 1024, 'media.storage_limit_bytes' => 10 * 1024 * 1024]);
        $this->mock(YouTubeDownloader::class, function (MockInterface $mock): void {
            $mock->shouldReceive('available')->andReturn(true);
        });
    }

    public function test_only_single_youtube_video_urls_are_normalized_and_external_targets_rejected(): void
    {
        foreach ([
            'https://www.youtube.com/watch?v=jNQXAC9IVRw&list=PLabc&t=4',
            'https://youtu.be/jNQXAC9IVRw?si=test',
            'https://m.youtube.com/shorts/jNQXAC9IVRw',
            'http://youtube.com/embed/jNQXAC9IVRw',
        ] as $url) {
            $this->assertSame('https://www.youtube.com/watch?v=jNQXAC9IVRw', YouTubeUrl::normalize($url));
        }
        $this->actingAs(User::factory()->create());
        foreach ([
            'file:///etc/passwd', 'http://127.0.0.1/private', 'https://youtube.com.evil.test/watch?v=jNQXAC9IVRw',
            'https://youtube.com@127.0.0.1/watch?v=jNQXAC9IVRw', 'https://evil.test@youtube.com/watch?v=jNQXAC9IVRw',
            'https://youtube.com:8080/watch?v=jNQXAC9IVRw', 'https://youtube.com/playlist?list=PLabc',
            'https://youtube.com/watch?v[]=jNQXAC9IVRw', 'https://youtu.be/jNQXAC9IVRw/../../private',
            'https://youtube.com/channel/example', '--exec=unsafe', 'https://youtu.be/short',
        ] as $url) {
            $this->assertNull(YouTubeUrl::normalize($url));
            $this->postJson('/media/projects/youtube', $this->input($url))->assertUnprocessable()->assertJsonValidationErrors('url');
        }
        $this->assertDatabaseCount('media_projects', 0);
        Queue::assertNotPushed(ImportYouTubeVideo::class);
    }

    public function test_import_requires_authentication_and_is_idempotent_with_quota_reserved(): void
    {
        $input = $this->input();
        $this->postJson('/media/projects/youtube', $input)->assertUnauthorized();
        $this->actingAs(User::factory()->create());
        $first = $this->postJson('/media/projects/youtube', $input)->assertAccepted()
            ->assertJsonPath('project.sourceType', 'youtube')->assertJsonPath('project.import.stage', 'queued');
        $again = $this->postJson('/media/projects/youtube', [...$input, 'url' => 'https://youtu.be/jNQXAC9IVRw?si=other'])->assertAccepted();
        $this->assertSame($first->json('project.id'), $again->json('project.id'));
        Queue::assertPushed(ImportYouTubeVideo::class, 1);
        $this->postJson('/media/projects/youtube', $this->input())->assertAccepted();
        $this->postJson('/media/projects/youtube', $this->input())->assertUnprocessable()->assertJsonPath('code', 'storage_limit');
        $this->assertDatabaseCount('media_projects', 2);
    }

    public function test_upload_and_link_initialization_cannot_share_the_same_client_identity(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $input = $this->input();
        $this->postJson('/media/projects/youtube', $input)->assertAccepted();
        $this->postJson('/media/projects', ['client_id' => $input['client_id'], 'filename' => 'YouTube video.mp4', 'size' => config('media.max_upload_bytes')])->assertConflict();
        $this->postJson('/media/projects/youtube', [...$input, 'url' => 'https://www.youtube.com/watch?v=aqz-KE-bpKQ'])->assertConflict();
    }

    public function test_import_cancel_retry_and_progress_are_owner_scoped(): void
    {
        $project = $this->project();
        $this->actingAs(User::factory()->create());
        $this->getJson('/media/projects/'.$project->id)->assertNotFound();
        $this->postJson('/media/projects/'.$project->id.'/import/cancel')->assertNotFound();
        $this->postJson('/media/projects/'.$project->id.'/import/retry')->assertNotFound();
        $this->actingAs($project->user);
        $this->postJson('/media/projects/'.$project->id.'/import/cancel')->assertOk()->assertJsonPath('project.status', 'import_cancelled');
        $this->assertSame(0, $project->fresh()->expected_bytes);
        $this->postJson('/media/projects/'.$project->id.'/import/retry')->assertAccepted()
            ->assertJsonPath('project.status', 'importing')->assertJsonPath('project.import.cancelRequested', false);
        Queue::assertPushed(ImportYouTubeVideo::class, 1);
    }

    public function test_import_failure_releases_unused_reservation_and_can_retry(): void
    {
        $project = $this->project();
        $downloader = $this->app->make(YouTubeDownloader::class);
        $downloader->shouldReceive('inspect')->once()->andThrow(new MediaFailure('duration_exceeded'));
        $downloader->shouldNotReceive('download');
        (new ImportYouTubeVideo($project->id))->handle($downloader, app(MediaRuntime::class));
        $this->assertSame('import_failed', $project->fresh()->status);
        $this->assertSame('duration_exceeded', $project->fresh()->error_code);
        $this->assertSame(0, $project->fresh()->expected_bytes);
        $this->actingAs($project->user)->postJson('/media/projects/'.$project->id.'/import/retry')->assertAccepted()
            ->assertJsonPath('project.errorCode', null)->assertJsonPath('project.import.stage', 'queued');
    }

    public function test_cancel_requested_during_download_prevents_inspection_and_publication(): void
    {
        $project = $this->project();
        $downloader = $this->app->make(YouTubeDownloader::class);
        $downloader->shouldReceive('inspect')->once()->andReturn(['title' => 'Test', 'duration' => 2]);
        $downloader->shouldReceive('download')->once()->andReturnUsing(function () use ($project): string {
            $project->update(['import_cancel_requested' => true]);

            return 'unused.mp4';
        });
        (new ImportYouTubeVideo($project->id))->handle($downloader, app(MediaRuntime::class));
        $this->assertSame('import_cancelled', $project->fresh()->status);
        Queue::assertNotPushed(InspectMediaProject::class);
        Storage::disk('r2')->assertMissing($project->source_path);
    }

    public function test_imported_video_uses_real_inspection_and_private_r2_storage(): void
    {
        $runtime = app(MediaRuntime::class);
        if (! $runtime->available()) {
            $this->markTestSkipped('FFmpeg runtime is required for media integration testing.');
        }
        $fixture = Storage::disk('local')->path('youtube-fixture.mp4');
        $runtime->execute('ffmpeg', ['-nostdin', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=size=320x240:rate=24', '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000', '-t', '1.2', '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-threads', '1', $fixture]);
        $project = $this->project(['storage_disk' => 'r2']);
        $downloader = $this->app->make(YouTubeDownloader::class);
        $downloader->shouldReceive('inspect')->once()->andReturn(['title' => 'Imported video', 'duration' => 1.2]);
        $downloader->shouldReceive('download')->once()->andReturnUsing(function (string $url, string $directory, callable $progress) use ($fixture): string {
            $target = $directory.'/download.mp4';
            copy($fixture, $target);
            $progress(filesize($target));

            return $target;
        });
        (new ImportYouTubeVideo($project->id))->handle($downloader, $runtime);
        $this->assertSame('validating', $project->fresh()->status, (string) $project->fresh()->error_code);
        Queue::assertPushed(InspectMediaProject::class, 1);
        app()->call([new InspectMediaProject($project->id), 'handle']);
        $project->refresh();
        $this->assertSame('ready', $project->status, (string) $project->error_code);
        $this->assertSame('Imported video', $project->title);
        $this->assertSame('completed', $project->import_stage);
        $this->assertSame(320, $project->metadata['width']);
        Storage::disk('r2')->assertExists($project->source_path);
        Storage::disk('r2')->assertExists('media/'.$project->id.'/poster.jpg');
        $this->actingAs($project->user)->get('/projects/'.$project->id.'/setup')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('projects/setup')->where('initialProject.sourceType', 'youtube'));
    }

    private function input(string $url = 'https://www.youtube.com/watch?v=jNQXAC9IVRw'): array
    {
        return ['client_id' => (string) Str::uuid(), 'url' => $url];
    }

    private function project(array $attributes = []): MediaProject
    {
        return MediaProject::factory()->create([
            'title' => 'YouTube video', 'source_type' => 'youtube', 'source_url' => 'https://www.youtube.com/watch?v=jNQXAC9IVRw',
            'status' => 'importing', 'import_stage' => 'queued', 'expected_bytes' => config('media.max_upload_bytes'), 'uploaded_bytes' => 0, 'metadata' => null,
            ...$attributes,
        ]);
    }
}
