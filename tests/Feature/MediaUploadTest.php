<?php

namespace Tests\Feature;

use App\Jobs\InspectMediaProject;
use App\Jobs\ProcessMediaRun;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Models\User;
use App\Services\Media\StorageSettings;
use App\Support\MediaSettings;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MediaUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Queue::fake();
        config(['filesystems.media_disk' => 'local', 'media.separation_python' => 'missing-test-python', 'media.separation_command' => null]);
        $this->actingAs(User::factory()->create());
    }

    public function test_new_project_page_exposes_capabilities_without_credentials(): void
    {
        config(['media.gemini_key' => 'private-provider-key']);
        $this->get('/projects/new')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('projects/create')->where('runtime.aiReady', true)->where('storage.disk', 'local')
            ->missing('runtime.key')->missing('gemini_key')->where('limits.bytes', 2147483648));
    }

    public function test_initialization_is_idempotent_and_quota_is_reserved(): void
    {
        $input = ['client_id' => (string) Str::uuid(), 'filename' => 'Chase.mp4', 'size' => 12, 'fingerprint' => str_repeat('a', 64)];
        $first = $this->postJson('/media/projects', $input)->assertCreated()->assertJsonPath('project.title', 'Chase');
        $second = $this->postJson('/media/projects', $input)->assertCreated();
        $this->assertSame($first->json('project.id'), $second->json('project.id'));
        $this->assertDatabaseCount('media_projects', 1);
        $this->postJson('/media/projects', [...$input, 'size' => 13])->assertConflict();
        config(['media.storage_limit_bytes' => 15]);
        $this->postJson('/media/projects', ['client_id' => (string) Str::uuid(), 'filename' => 'new.mp4', 'size' => 4])->assertUnprocessable()->assertJsonPath('code', 'storage_limit');
    }

    public function test_invalid_extensions_paths_and_size_are_rejected(): void
    {
        foreach (['payload.php', '../video.mp4', 'folder\\video.mp4'] as $filename) {
            $this->postJson('/media/projects', ['client_id' => (string) Str::uuid(), 'filename' => $filename, 'size' => 12])->assertUnprocessable()->assertJsonValidationErrors('filename');
        }
        $this->postJson('/media/projects', ['client_id' => (string) Str::uuid(), 'filename' => 'video.mp4', 'size' => 2147483649])->assertUnprocessable()->assertJsonValidationErrors('size');
    }

    public function test_acknowledged_chunks_resume_without_duplicate_bytes_and_complete_only_when_full(): void
    {
        $init = $this->postJson('/media/projects', ['client_id' => (string) Str::uuid(), 'filename' => 'video.mp4', 'size' => 8])->assertCreated();
        $id = $init->json('project.id');
        $this->chunk($id, 0, 'abcd')->assertOk()->assertJsonPath('offset', 4);
        $this->chunk($id, 0, 'abcd')->assertConflict()->assertJsonPath('offset', 4);
        $this->postJson('/media/projects/'.$id.'/complete')->assertConflict()->assertJsonPath('code', 'upload_incomplete');
        $this->getJson('/media/projects/'.$id)->assertJsonPath('project.uploadedBytes', 4);
        $this->chunk($id, 4, 'efgh')->assertOk()->assertJsonPath('offset', 8);
        $this->postJson('/media/projects/'.$id.'/complete')->assertOk()->assertJsonPath('project.status', 'validating');
        $project = MediaProject::findOrFail($id);
        $this->assertSame('abcdefgh', Storage::disk('local')->get($project->source_path));
        $this->postJson('/media/projects/'.$id.'/complete')->assertOk();
        Queue::assertPushed(InspectMediaProject::class, 1);
        $this->chunk($id, 8, 'i')->assertConflict();
    }

    public function test_wrong_account_cannot_discover_modify_or_download_a_project(): void
    {
        $project = MediaProject::factory()->create();
        foreach (['', '/files/source', '/files/poster', '/files/video', '/files/subtitles'] as $suffix) {
            $this->getJson('/media/projects/'.$project->id.$suffix)->assertNotFound();
        }
        $this->patchJson('/media/projects/'.$project->id, ['title' => 'stolen'])->assertNotFound();
        $this->deleteJson('/media/projects/'.$project->id)->assertNotFound();
        $this->postJson('/media/projects/'.$project->id.'/start')->assertNotFound();
        $this->get('/projects/'.$project->id.'/setup')->assertNotFound();
    }

    public function test_settings_enforce_dub_output_cover_bounds_and_available_background_engine(): void
    {
        $project = $this->owned();
        $url = '/media/projects/'.$project->id;
        $settings = MediaSettings::DEFAULTS;
        $this->patchJson($url, ['title' => 'Recap', 'settings' => [...$settings, 'mode' => 'dub', 'output' => 'srt']])->assertUnprocessable()->assertJsonValidationErrors('settings.output');
        $this->patchJson($url, ['title' => 'Recap', 'settings' => [...$settings, 'cover_mode' => 'manual', 'cover_top' => 0.9, 'cover_height' => 0.2]])->assertUnprocessable()->assertJsonValidationErrors('settings.cover_height');
        $this->patchJson($url, ['title' => 'Recap', 'settings' => [...$settings, 'mode' => 'dub', 'keep_background' => true]])->assertUnprocessable()->assertJsonValidationErrors('settings.keep_background');
        $this->patchJson($url, ['title' => '  Saved recap  ', 'settings' => [...$settings, 'aspect' => '9:16', 'fill' => 'blur']])->assertOk()->assertJsonPath('project.title', 'Saved recap')->assertJsonPath('project.settings.aspect', '9:16');
    }

    public function test_language_change_requires_explicit_confirmation_before_discarding_saved_transcript(): void
    {
        $project = $this->owned(['lines' => [['index' => 0, 'start' => 0, 'end' => 1, 'source_text' => 'hello', 'text' => 'hello']]]);
        $input = ['title' => $project->title, 'settings' => [...MediaSettings::DEFAULTS, 'source_lang' => 'en']];
        $this->patchJson('/media/projects/'.$project->id, $input)->assertConflict()->assertJsonPath('code', 'confirm_reanalysis');
        $this->assertNotEmpty($project->fresh()->lines);
        $this->patchJson('/media/projects/'.$project->id, [...$input, 'confirm_reanalysis' => true])->assertOk()->assertJsonPath('project.lines', []);
    }

    public function test_missing_ai_key_never_starts_a_fake_run_and_start_is_idempotently_guarded(): void
    {
        $project = $this->owned();
        config(['media.gemini_key' => '']);
        $this->postJson('/media/projects/'.$project->id.'/start')->assertStatus(503)->assertJsonPath('code', 'ai_key_missing');
        $this->assertDatabaseCount('media_runs', 0);
        config(['media.gemini_key' => 'test-key']);
        $this->postJson('/media/projects/'.$project->id.'/start')->assertAccepted()->assertJsonPath('project.status', 'queued');
        $this->postJson('/media/projects/'.$project->id.'/start')->assertConflict()->assertJsonPath('code', 'project_busy');
        $this->assertDatabaseCount('media_runs', 1);
        Queue::assertPushed(ProcessMediaRun::class, 1);
    }

    public function test_review_edits_only_text_preserves_timestamps_and_generation_requires_valid_saved_script(): void
    {
        config(['media.gemini_key' => 'test-key']);
        $line = ['index' => 0, 'start' => 1, 'end' => 3, 'source_text' => 'Original', 'text' => 'Translation'];
        $project = $this->owned(['status' => 'awaiting_review', 'lines' => [$line]]);
        $run = MediaRun::factory()->for($project, 'project')->create(['status' => 'awaiting_review', 'lines' => [$line]]);
        $project->update(['active_run_id' => $run->id]);
        $url = '/media/projects/'.$project->id;
        $this->patchJson($url.'/script', ['lines' => [['index' => 1, 'text' => 'wrong']]])->assertUnprocessable();
        $this->patchJson($url.'/script', ['lines' => [['index' => 0, 'text' => 'Edited', 'start' => 99]]])->assertOk()->assertJsonPath('project.lines.0.start', 1)->assertJsonPath('project.lines.0.text', 'Edited');
        $this->postJson($url.'/generate')->assertAccepted()->assertJsonPath('project.run.phase', 'generate');
        $this->patchJson($url.'/script', ['lines' => [['index' => 0, 'text' => 'late']]])->assertConflict();
        $this->postJson($url.'/cancel')->assertOk()->assertJsonPath('project.status', 'cancelled');
        $this->assertTrue($run->fresh()->cancel_requested);
    }

    public function test_clear_only_removes_an_unprocessed_owned_draft(): void
    {
        $project = $this->owned();
        Storage::disk('local')->put($project->source_path, 'test');
        $this->deleteJson('/media/projects/'.$project->id)->assertOk();
        Storage::disk('local')->assertMissing($project->source_path);
        $processed = $this->owned(['status' => 'completed', 'lines' => [['text' => 'saved']]]);
        $this->deleteJson('/media/projects/'.$processed->id)->assertConflict();
        $this->assertDatabaseHas('media_projects', ['id' => $processed->id]);
    }

    public function test_history_is_owner_scoped_and_completed_deletion_requires_confirmation(): void
    {
        $mine = $this->owned(['status' => 'completed', 'lines' => [['index' => 0, 'start' => 0, 'end' => 1, 'text' => 'saved']]]);
        MediaProject::factory()->create(['status' => 'completed']);
        $this->get('/projects?status=completed')->assertInertia(fn (Assert $page) => $page
            ->component('projects/index')->has('projects.data', 1)->where('projects.data.0.id', $mine->id));
        $this->deleteJson('/media/projects/'.$mine->id)->assertConflict();
        $this->deleteJson('/media/projects/'.$mine->id, ['confirm' => true])->assertOk();
        $this->assertDatabaseMissing('media_projects', ['id' => $mine->id]);
        $busy = $this->owned(['status' => 'rendering']);
        $this->deleteJson('/media/projects/'.$busy->id, ['confirm' => true])->assertConflict();
    }

    public function test_reopen_keeps_completed_files_and_only_creates_an_editable_review_run(): void
    {
        $line = ['index' => 0, 'start' => 0.2, 'end' => 1.0, 'source_text' => 'Hello', 'text' => 'Saved'];
        $project = $this->owned(['status' => 'completed', 'lines' => [$line], 'output_path' => 'media/previous.mp4']);
        $this->postJson('/media/projects/'.$project->id.'/reopen')->assertOk()
            ->assertJsonPath('project.status', 'awaiting_review')->assertJsonPath('project.hasOutput', true)
            ->assertJsonPath('project.lines.0.text', 'Saved');
        Queue::assertNotPushed(ProcessMediaRun::class);
        $this->assertDatabaseCount('media_runs', 1);
    }

    public function test_confirmed_delete_removes_all_local_and_r2_artifacts_only_for_the_owned_project(): void
    {
        Storage::fake('r2');
        $owner = auth()->user();
        $mine = $this->owned(['status' => 'completed', 'storage_disk' => 'r2', 'storage_profile_id' => null, 'lines' => [['text' => 'Saved script']]]);
        $other = MediaProject::factory()->create(['storage_disk' => 'r2', 'storage_profile_id' => null]);
        $files = ['source.mp4', 'runs/finished/recap.mp4', 'runs/finished/recap.srt', 'runs/preview/preview.mp4', 'voices/cached.wav', 'background/instrumental.wav', 'unregistered-cache/thumbnail.jpg'];
        foreach ($files as $file) {
            foreach (['local', 'r2'] as $disk) {
                Storage::disk($disk)->put('media/'.$mine->id.'/'.$file, 'owned fixture');
                Storage::disk($disk)->put('media/'.$other->id.'/'.$file, 'other customer fixture');
            }
        }
        $this->actingAs(User::factory()->create())->deleteJson('/media/projects/'.$mine->id, ['confirm' => true])->assertNotFound();
        Storage::disk('r2')->assertExists('media/'.$mine->id.'/source.mp4');
        $this->actingAs($owner)->deleteJson('/media/projects/'.$mine->id, ['confirm' => true])->assertOk()->assertJsonPath('removed', true);
        foreach ($files as $file) {
            foreach (['local', 'r2'] as $disk) {
                Storage::disk($disk)->assertMissing('media/'.$mine->id.'/'.$file);
                Storage::disk($disk)->assertExists('media/'.$other->id.'/'.$file);
            }
        }
        $this->assertDatabaseMissing('media_projects', ['id' => $mine->id]);
        $this->assertDatabaseHas('media_projects', ['id' => $other->id]);
    }

    public function test_failed_r2_deletion_never_reports_success_or_deletes_the_project_record(): void
    {
        $mine = $this->owned(['status' => 'completed', 'storage_disk' => 'r2', 'storage_profile_id' => null, 'lines' => [['text' => 'Saved script']]]);
        Storage::disk('local')->put($mine->source_path, 'retained local source');
        $remote = \Mockery::mock(Filesystem::class);
        $remote->shouldReceive('deleteDirectory')->once()->with('media/'.$mine->id)->andReturn(false);
        $this->partialMock(StorageSettings::class, function ($mock) use ($remote): void {
            $mock->shouldReceive('filesystem')->with(null, 'r2')->andReturn($remote);
        });
        $this->deleteJson('/media/projects/'.$mine->id, ['confirm' => true])->assertStatus(503);
        $this->assertDatabaseHas('media_projects', ['id' => $mine->id]);
        Storage::disk('local')->assertExists($mine->source_path);
    }

    private function owned(array $attributes = []): MediaProject
    {
        return MediaProject::factory()->create(['user_id' => auth()->id(), ...$attributes]);
    }

    private function chunk(string $id, int $offset, string $bytes): TestResponse
    {
        return $this->call('PUT', '/media/projects/'.$id.'/upload', [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/octet-stream', 'HTTP_UPLOAD_OFFSET' => (string) $offset, 'CONTENT_LENGTH' => strlen($bytes)], $bytes);
    }
}
