<?php

namespace Tests\Feature;

use App\Models\MediaPreset;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Models\User;
use App\Services\Media\MediaBackground;
use App\Services\Media\ProjectDefaults;
use App\Services\Media\ProviderSettings;
use App\Services\Media\YouTubeDownloader;
use App\Support\MediaSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Queue::fake();
        Http::preventStrayRequests();
        config(['filesystems.media_disk' => 'local', 'media.max_upload_bytes' => 1024 * 1024, 'media.storage_limit_bytes' => 20 * 1024 * 1024, 'media.gemini_key' => 'private-provider-key-for-tests']);
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->mock(MediaBackground::class)->shouldReceive('available')->andReturn(false);
        $this->mock(YouTubeDownloader::class)->shouldReceive('available')->andReturn(true);
    }

    private function draft(array $settings = [], bool $inherit = true, int $revision = 0): array
    {
        return ['revision' => $revision, 'inherit_voice' => $inherit, 'settings' => array_replace(MediaSettings::DEFAULTS, $settings)];
    }

    public function test_only_the_admin_guard_can_read_or_save_system_defaults(): void
    {
        $this->get('/admin/settings/defaults')->assertRedirect('/admin/login');
        $this->putJson('/admin/settings/defaults', $this->draft())->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'web')->getJson('/admin/settings/defaults')->assertUnauthorized();
        $this->putJson('/admin/settings/defaults', $this->draft())->assertUnauthorized();
        $this->actingAs($this->admin, 'web')->putJson('/admin/settings/defaults', $this->draft())->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'admin')->getJson('/admin/settings/defaults')->assertForbidden();
        $this->assertSame(0, DB::table('media_project_defaults')->value('revision'));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_initial_page_exposes_recommended_settings_and_capabilities_without_secrets(): void
    {
        $this->actingAs($this->admin, 'admin')->get('/admin/settings/defaults')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('settings/defaults')->where('frame', 'projectDefaults')->where('defaults.revision', 0)
            ->where('defaults.inherit_voice', true)->where('defaults.savedAt', null)->where('defaults.settings.mode', 'subs')
            ->where('defaults.settings.output', 'burn')->where('providerVoice.voice', 'Kore')->where('backgroundReady', false)
            ->missing('credentials')->missing('providerVoice.credentials')->missing('gemini_key'))
            ->assertDontSee('private-provider-key-for-tests');
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_admin_navigation_and_save_url_are_consistent_and_legacy_writes_are_closed(): void
    {
        $this->get('/settings/defaults')->assertRedirect('/admin/settings/defaults');
        $this->actingAs($this->admin, 'admin')->get('/admin/settings/defaults')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('adminLinks.defaults', '/admin/settings/defaults')
            ->where('adminLinks.providers', '/admin/settings/providers')
            ->where('adminLinks.storage', '/admin/settings/storage')
            ->where('saveUrl', '/admin/settings/defaults')
            ->where('links.logout', '/admin/logout')
            ->missing('frameLinks.projectDefaults'));
        $this->putJson('/settings/defaults', $this->draft())->assertStatus(405);
        $this->assertSame(0, DB::table('media_project_defaults')->value('revision'));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_admin_page_reuses_saved_defaults_without_reseeding_or_overwriting_them(): void
    {
        $defaults = app(ProjectDefaults::class);
        $defaults->save(array_replace(MediaSettings::DEFAULTS, ['source_lang' => 'ja', 'subtitle_color' => '#72E2C3']), false, 0, $this->admin->id);
        $before = (array) DB::table('media_project_defaults')->where('id', 1)->first();
        $this->actingAs($this->admin, 'admin')->get('/admin/settings/defaults')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('defaults.revision', 1)->where('defaults.settings.source_lang', 'ja')
            ->where('defaults.settings.subtitle_color', '#72E2C3')->where('defaults.inherit_voice', false));
        $this->assertSame($before, (array) DB::table('media_project_defaults')->where('id', 1)->first());
        $this->assertDatabaseCount('media_project_defaults', 1);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_simultaneous_customer_session_does_not_change_admin_audit_ownership(): void
    {
        $customer = User::factory()->create(['is_admin' => false]);
        $this->actingAs($customer, 'web');
        $this->actingAs($this->admin, 'admin')->putJson('/admin/settings/defaults', $this->draft(['source_lang' => 'th']))->assertOk();
        $this->assertDatabaseHas('media_project_defaults', ['id' => 1, 'updated_by' => $this->admin->id, 'revision' => 1]);
        $this->actingAs($customer, 'web')->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->missing('frameLinks.projectDefaults')->missing('defaults')->missing('providerVoice'));
        $this->assertSame('th', MediaSettings::defaults()['source_lang']);
    }

    public function test_admin_defaults_save_requires_csrf_outside_the_test_bypass(): void
    {
        $this->actingAs($this->admin, 'admin');
        $this->app['env'] = 'local';
        $this->putJson('/admin/settings/defaults', $this->draft())->assertStatus(419);
        $this->assertSame(0, DB::table('media_project_defaults')->value('revision'));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_defaults_save_without_a_provider_key_or_ai_request_and_survive_a_fresh_service(): void
    {
        config(['media.gemini_key' => '']);
        $this->actingAs($this->admin, 'admin')->putJson('/admin/settings/defaults', $this->draft(['mode' => 'dub', 'output' => 'mp3', 'voice_style' => 'Clear Burmese narration']))
            ->assertOk()->assertJsonPath('defaults.revision', 1)->assertJsonPath('defaults.settings.output', 'mp3');
        $this->get('/admin/settings/defaults')->assertInertia(fn (Assert $page) => $page->where('defaults.revision', 1)->where('defaults.settings.voice_style', 'Clear Burmese narration'));
        $this->assertSame('mp3', app(ProjectDefaults::class)->resolved()['output']);
        $this->assertNotNull(DB::table('media_project_defaults')->value('updated_at'));
        $this->assertDatabaseCount('media_provider_revisions', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_stale_administrator_cannot_overwrite_saved_defaults(): void
    {
        $this->actingAs($this->admin, 'admin')->putJson('/admin/settings/defaults', $this->draft(['source_lang' => 'th']))->assertOk();
        $this->putJson('/admin/settings/defaults', $this->draft(['source_lang' => 'en']))->assertConflict();
        $this->assertSame('th', MediaSettings::defaults()['source_lang']);
        $this->putJson('/admin/settings/defaults', $this->draft(['source_lang' => 'en'], revision: 1))->assertOk()->assertJsonPath('defaults.revision', 2);
    }

    public function test_local_and_youtube_projects_capture_defaults_once_and_existing_uploads_keep_their_settings(): void
    {
        $this->actingAs($this->admin, 'admin')->putJson('/admin/settings/defaults', $this->draft(['mode' => 'dub', 'output' => 'wav', 'audio_track' => 'narration', 'aspect' => '9:16']))->assertOk();
        $customer = User::factory()->create(['is_admin' => false]);
        $local = ['client_id' => (string) Str::uuid(), 'filename' => 'Local.mp4', 'size' => 12];
        $youtube = ['client_id' => (string) Str::uuid(), 'url' => 'https://youtu.be/jNQXAC9IVRw'];
        $id = $this->actingAs($customer, 'web')->postJson('/media/projects', $local)->assertCreated()->assertJsonPath('project.settings.output', 'wav')->json('project.id');
        $youtubeId = $this->actingAs($customer, 'web')->postJson('/media/projects/youtube', $youtube)->assertAccepted()->assertJsonPath('project.settings.output', 'wav')->json('project.id');
        $this->putJson('/admin/settings/defaults', $this->draft(['source_lang' => 'ja'], revision: 1))->assertOk();
        $this->actingAs($customer, 'web')->postJson('/media/projects', $local)->assertCreated()->assertJsonPath('project.id', $id)->assertJsonPath('project.settings.output', 'wav');
        $this->actingAs($customer, 'web')->postJson('/media/projects/youtube', $youtube)->assertAccepted()->assertJsonPath('project.id', $youtubeId)->assertJsonPath('project.settings.output', 'wav');
        $this->actingAs($customer, 'web')->postJson('/media/projects', [...$local, 'client_id' => (string) Str::uuid()])->assertCreated()->assertJsonPath('project.settings.source_lang', 'ja')->assertJsonPath('project.settings.output', 'burn');
        Http::assertNothingSent();
    }

    public function test_existing_projects_jobs_and_personal_presets_are_not_changed_by_workspace_saves(): void
    {
        $project = MediaProject::factory()->create(['user_id' => $this->admin->id, 'settings' => MediaSettings::DEFAULTS, 'status' => 'ready']);
        $run = MediaRun::factory()->for($project, 'project')->create(['settings' => MediaSettings::DEFAULTS]);
        $preset = MediaPreset::factory()->create(['user_id' => $this->admin->id, 'settings' => [...MediaSettings::DEFAULTS, 'source_lang' => 'ko']]);
        $this->actingAs($this->admin, 'admin')->putJson('/admin/settings/defaults', $this->draft(['mode' => 'dub', 'voice' => 'Charon'], false))->assertOk();
        $this->assertSame('subs', $project->fresh()->settings['mode']);
        $this->assertSame('subs', $run->fresh()->settings['mode']);
        $this->assertSame('ko', $preset->fresh()->settings['source_lang']);
        $this->actingAs($this->admin, 'web')->patchJson('/media/projects/'.$project->id, ['title' => $project->title, 'settings' => $preset->settings])->assertOk()->assertJsonPath('project.settings.source_lang', 'ko')->assertJsonPath('project.settings.mode', 'subs');
    }

    public function test_inherited_provider_voice_changes_only_affect_future_project_defaults(): void
    {
        $provider = app(ProviderSettings::class);
        $this->actingAs($this->admin, 'admin')->putJson('/admin/settings/defaults', $this->draft(['mode' => 'dub']))->assertOk();
        $input = ['client_id' => (string) Str::uuid(), 'filename' => 'Before.mp4', 'size' => 12];
        $id = $this->actingAs($this->admin, 'web')->postJson('/media/projects', $input)->assertCreated()->json('project.id');
        $configuration = $provider->current();
        $configuration['voice'] = 'Charon';
        $configuration['speech_rate'] = 1.25;
        $configuration['voice_volume'] = 0.8;
        $provider->save($configuration, $provider->revision(), $this->admin->id);
        $this->assertSame('Charon', MediaSettings::defaults()['voice']);
        $this->assertSame(1.25, MediaSettings::defaults()['speech_rate']);
        $this->assertSame('Kore', MediaProject::findOrFail($id)->settings['voice']);
        $this->putJson('/admin/settings/defaults', $this->draft(['mode' => 'dub', 'voice' => 'Leda', 'speech_rate' => 0.9], false, 1))->assertOk();
        $this->assertSame('Leda', MediaSettings::defaults()['voice']);
        $this->assertSame(0.9, MediaSettings::defaults()['speech_rate']);
        $this->putJson('/admin/settings/defaults', $this->draft(['mode' => 'dub', 'voice' => 'Leda'], true, 2))->assertOk()->assertJsonPath('defaults.settings.voice', 'Charon');
    }

    public function test_invalid_combinations_unknown_keys_and_out_of_range_values_cannot_be_saved(): void
    {
        $this->actingAs($this->admin, 'admin');
        foreach ([
            ['mode' => 'dub', 'output' => 'srt'], ['mode' => 'subs', 'output' => 'mp3'],
            ['voice' => 'unknown'], ['speech_rate' => 4], ['subtitle_lift' => 0.8],
            ['cover_mode' => 'manual', 'cover_top' => 0.9, 'cover_height' => 0.2],
            ['mode' => 'dub', 'keep_background' => true], ['unexpected_key' => 'private'],
        ] as $settings) {
            $this->putJson('/admin/settings/defaults', $this->draft($settings, false))->assertUnprocessable();
        }
        $incomplete = $this->draft();
        unset($incomplete['settings']['source_lang']);
        $this->putJson('/admin/settings/defaults', $incomplete)->assertUnprocessable()->assertJsonValidationErrors('settings.source_lang');
        $this->assertSame(0, DB::table('media_project_defaults')->value('revision'));
    }

    public function test_background_is_saved_only_when_applicable_and_separation_is_ready(): void
    {
        $this->mock(MediaBackground::class)->shouldReceive('available')->andReturn(true);
        $this->actingAs($this->admin, 'admin')->putJson('/admin/settings/defaults', $this->draft(['mode' => 'dub', 'keep_background' => true, 'background_volume' => 0.45]))
            ->assertOk()->assertJsonPath('defaults.settings.keep_background', true)->assertJsonPath('defaults.settings.background_volume', 0.45);
        $this->putJson('/admin/settings/defaults', $this->draft(['mode' => 'dub', 'output' => 'mp3', 'audio_track' => 'narration', 'keep_background' => true], revision: 1))
            ->assertOk()->assertJsonPath('defaults.settings.keep_background', false);
    }
}
