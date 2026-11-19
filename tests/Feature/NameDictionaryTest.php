<?php

namespace Tests\Feature;

use App\Models\MediaProject;
use App\Models\User;
use App\Services\Media\YouTubeDownloader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class NameDictionaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Queue::fake();
        Http::preventStrayRequests();
        config(['filesystems.media_disk' => 'local', 'media.separation_python' => 'missing-test-python', 'media.separation_command' => null]);
    }

    private function entries(): array
    {
        return [['original' => 'John', 'translated' => 'Jon', 'pronunciation' => 'JON']];
    }

    public function test_done_saves_account_defaults_and_a_fresh_page_restores_them(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->putJson('/media/name-dictionary', ['name_dictionary' => $this->entries()])
            ->assertOk()->assertJsonPath('name_dictionary', $this->entries());
        $this->assertSame($this->entries(), $user->fresh()->recap_name_dictionary);
        $this->get('/projects/new')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('studioDefaults.name_dictionary', $this->entries())->where('dictionaryUrl', '/media/name-dictionary'));
        $this->assertArrayNotHasKey('recap_name_dictionary', $user->fresh()->toArray());
    }

    public function test_saving_a_dictionary_only_changes_that_field_on_the_owned_project(): void
    {
        $project = MediaProject::factory()->create(['status' => 'awaiting_review', 'settings' => ['voice' => 'Leda', 'name_dictionary' => []], 'lines' => [['text' => 'Existing script']]]);
        $this->actingAs($project->user)->putJson('/media/name-dictionary', ['project_id' => $project->id, 'name_dictionary' => $this->entries()])->assertOk();
        $project->refresh();
        $this->assertSame($this->entries(), $project->settings['name_dictionary']);
        $this->assertSame('Leda', $project->settings['voice']);
        $this->assertSame([['text' => 'Existing script']], $project->lines);
        $this->assertSame('awaiting_review', $project->status);
    }

    public function test_dictionary_is_private_and_foreign_project_ids_cannot_update_either_record(): void
    {
        $project = MediaProject::factory()->create();
        $user = User::factory()->create();
        $this->actingAs($user)->putJson('/media/name-dictionary', ['project_id' => $project->id, 'name_dictionary' => $this->entries()])->assertNotFound();
        $this->assertNull($user->fresh()->recap_name_dictionary);
        $this->assertEmpty($project->fresh()->settings['name_dictionary'] ?? []);
        $this->actingAs($project->user)->putJson('/media/name-dictionary', ['name_dictionary' => $this->entries()])->assertOk();
        $this->actingAs($user)->get('/projects/new')->assertInertia(fn (Assert $page) => $page->where('studioDefaults.name_dictionary', []));
    }

    public function test_busy_project_does_not_partially_save_the_personal_dictionary(): void
    {
        $project = MediaProject::factory()->create(['status' => 'rendering']);
        $this->actingAs($project->user)->putJson('/media/name-dictionary', ['project_id' => $project->id, 'name_dictionary' => $this->entries()])
            ->assertConflict()->assertJsonPath('code', 'project_busy');
        $this->assertNull($project->user->fresh()->recap_name_dictionary);
        $this->assertEmpty($project->fresh()->settings['name_dictionary'] ?? []);
    }

    public function test_invalid_or_oversized_entries_are_rejected_and_required_names_are_trimmed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->putJson('/media/name-dictionary', ['name_dictionary' => [['original' => '   ', 'translated' => '']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['name_dictionary.0.original', 'name_dictionary.0.translated']);
        $this->putJson('/media/name-dictionary', ['name_dictionary' => array_fill(0, 51, $this->entries()[0])])
            ->assertUnprocessable()->assertJsonValidationErrors('name_dictionary');
        $this->putJson('/media/name-dictionary', ['name_dictionary' => [['original' => str_repeat('a', 101), 'translated' => 'Name']]])
            ->assertUnprocessable()->assertJsonValidationErrors('name_dictionary.0.original');
        $this->putJson('/media/name-dictionary', ['name_dictionary' => [['original' => '  John  ', 'translated' => '  Jon  ']]])
            ->assertOk()->assertJsonPath('name_dictionary.0', ['original' => 'John', 'translated' => 'Jon', 'pronunciation' => '']);
    }

    public function test_new_local_and_youtube_projects_inherit_saved_names_without_rewriting_older_projects(): void
    {
        $user = User::factory()->create();
        $old = MediaProject::factory()->for($user)->create(['settings' => ['name_dictionary' => []]]);
        $this->actingAs($user)->putJson('/media/name-dictionary', ['name_dictionary' => $this->entries()])->assertOk();
        $this->postJson('/media/projects', ['client_id' => (string) Str::uuid(), 'filename' => 'movie.mp4', 'size' => 12])
            ->assertCreated()->assertJsonPath('project.settings.name_dictionary', $this->entries());
        $downloader = Mockery::mock(YouTubeDownloader::class);
        $downloader->shouldReceive('available')->andReturn(true);
        $this->app->instance(YouTubeDownloader::class, $downloader);
        $this->postJson('/media/projects/youtube', ['client_id' => (string) Str::uuid(), 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'])
            ->assertAccepted()->assertJsonPath('project.settings.name_dictionary', $this->entries());
        $this->assertSame([], $old->fresh()->settings['name_dictionary']);
    }

    public function test_removing_all_entries_is_persisted_and_guests_cannot_save(): void
    {
        $this->putJson('/media/name-dictionary', ['name_dictionary' => []])->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user)->putJson('/media/name-dictionary', ['name_dictionary' => $this->entries()])->assertOk();
        $this->putJson('/media/name-dictionary', ['name_dictionary' => []])->assertOk()->assertJsonPath('name_dictionary', []);
        $this->assertSame([], $user->fresh()->recap_name_dictionary);
        $this->get('/projects/new')->assertInertia(fn (Assert $page) => $page->where('studioDefaults.name_dictionary', []));
    }
}
