<?php

namespace Tests\Feature;

use App\Models\CustomerPackageAssignment;
use App\Models\MediaPreset;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;
use App\Services\Media\GeminiClient;
use App\Services\Media\ProviderSettings;
use App\Services\StudioPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PreferencesTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Queue::fake();
        Http::preventStrayRequests();
        config(['filesystems.media_disk' => 'local', 'media.separation_python' => 'missing-test-python', 'media.separation_command' => null]);
        $this->customer = User::factory()->create(['preferred_locale' => 'en']);
    }

    /** @return array<string, mixed> */
    private function settings(array $changes = []): array
    {
        return [...app(StudioPreferences::class)->baseline(), ...$changes];
    }

    /** @return array<string, mixed> */
    private function payload(array $settings = [], array $dictionary = [], string $locale = 'en'): array
    {
        return ['locale' => $locale, 'settings' => $this->settings($settings), 'name_dictionary' => $dictionary];
    }

    private function package(array $features): void
    {
        $package = Package::factory()->create(['status' => 'active', 'published_version' => 1]);
        $definition = $package->definition;
        $definition['features'] = [...$definition['features'], ...$features];
        $version = PackageVersion::create(['package_id' => $package->id, 'number' => 1, 'definition' => $definition, 'actor_id' => User::factory()->create(['is_admin' => true])->id]);
        CustomerPackageAssignment::create(['user_id' => $this->customer->id, 'package_id' => $package->id, 'package_version_id' => $version->id, 'source' => 'payment_new',
            'snapshot' => ['name' => 'Starter', 'code' => $package->code, 'version' => 1, 'type' => 'paid', 'definition' => $definition], 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(20)]);
    }

    private function preset(string $name, array $settings = []): int
    {
        return $this->postJson('/settings/preferences/presets', ['name' => $name, 'settings' => $this->settings($settings)])->assertCreated()->json('preset_id');
    }

    public function test_page_renders_the_customers_preferences_without_secrets(): void
    {
        $this->actingAs($this->customer)->get('/settings/preferences')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('settings/preferences')->where('preferences.locale', 'en')->where('preferences.baseline.workflow', 'movie')
            ->where('preferences.limits.presets', 12)->where('preferences.limits.dictionary', 50)->where('preferences.presets', [])
            ->where('preferences.endpoints.save', '/settings/preferences')->missing('preferences.studio_preferences'));
        auth('web')->logout();
        $this->get('/settings/preferences')->assertRedirect('/login');
    }

    public function test_saving_stores_only_changes_and_new_projects_start_from_them(): void
    {
        $this->actingAs($this->customer);
        $dictionary = [['original' => ' Ananda ', 'translated' => 'အာနန္ဒာ', 'type' => 'character', 'language' => 'my', 'note' => 'Chief attendant']];
        $this->putJson('/settings/preferences', $this->payload(['narration_lang' => 'th', 'speech_rate' => 1.2, 'subtitle_bold' => true], $dictionary, 'th'))
            ->assertOk()->assertJsonPath('preferences.saved.narration_lang', 'th')->assertJsonPath('preferences.dictionary.0.original', 'Ananda')
            ->assertCookie('ui_locale', 'th');
        $user = $this->customer->fresh();
        $this->assertSame('th', $user->preferred_locale);
        $this->assertEqualsCanonicalizing(['narration_lang', 'speech_rate', 'subtitle_bold'], array_keys($user->studio_preferences));
        $this->assertSame('Chief attendant', $user->recap_name_dictionary[0]['note']);

        $this->get('/projects/new')->assertInertia(fn (Assert $page) => $page->where('personalDefaults.narration_lang', 'th')->where('personalDefaults.speech_rate', 1.2)
            ->where('studioDefaults.narration_lang', 'th')->where('studioDefaults.name_dictionary.0.type', 'character'));
        $this->postJson('/media/projects', ['client_id' => (string) Str::uuid(), 'filename' => 'movie.mp4', 'size' => 12])
            ->assertCreated()->assertJsonPath('project.settings.narration_lang', 'th')->assertJsonPath('project.settings.subtitle_bold', true);

        $this->putJson('/settings/preferences', $this->payload())->assertOk();
        $this->assertNull($this->customer->fresh()->studio_preferences);
    }

    public function test_invalid_combinations_and_duplicate_names_are_rejected(): void
    {
        $this->actingAs($this->customer);
        $this->putJson('/settings/preferences', $this->payload(['workflow' => 'subtitles', 'output' => 'mp3']))->assertUnprocessable()->assertJsonValidationErrors('settings.output');
        $this->putJson('/settings/preferences', $this->payload(['voice' => 'Unknown', 'target_seconds' => 10, 'speech_rate' => 3]))
            ->assertUnprocessable()->assertJsonValidationErrors(['settings.voice', 'settings.target_seconds', 'settings.speech_rate']);
        $this->putJson('/settings/preferences', $this->payload([], [['original' => 'Jon', 'translated' => 'A'], ['original' => 'jon', 'translated' => 'B']]))
            ->assertUnprocessable()->assertJsonValidationErrors('name_dictionary.1.original');
        $this->putJson('/settings/preferences', $this->payload([], [['original' => 'Jon', 'translated' => 'A', 'type' => 'monster']]))
            ->assertUnprocessable()->assertJsonValidationErrors('name_dictionary.0.type');
        $this->putJson('/settings/preferences', $this->payload([], array_fill(0, 51, ['original' => 'x', 'translated' => 'y'])))->assertUnprocessable();
        $this->putJson('/settings/preferences', [...$this->payload(), 'locale' => 'fr'])->assertUnprocessable()->assertJsonValidationErrors('locale');
        $this->assertNull($this->customer->fresh()->studio_preferences);
    }

    public function test_package_features_lock_styling_quality_workflows_and_outputs(): void
    {
        $this->package(['subtitle_style' => false, 'layout' => false, 'movie' => false, 'audio' => false]);
        $this->actingAs($this->customer);
        $this->get('/settings/preferences')->assertInertia(fn (Assert $page) => $page->where('preferences.features.subtitle_style', false));
        $this->putJson('/settings/preferences', $this->payload(['workflow' => 'translate', 'mode' => 'dub']))->assertOk();
        $this->putJson('/settings/preferences', $this->payload(['workflow' => 'translate', 'subtitle_bold' => true, 'quality' => '1080p', 'output' => 'mp3']))
            ->assertUnprocessable()->assertJsonValidationErrors(['settings.subtitle_bold', 'settings.quality', 'settings.output']);
        $this->putJson('/settings/preferences', $this->payload(['workflow' => 'movie']))->assertUnprocessable()->assertJsonValidationErrors('settings.workflow');
    }

    public function test_values_a_plan_no_longer_includes_are_not_applied_to_new_projects(): void
    {
        $this->customer->forceFill(['studio_preferences' => ['quality' => '1080p', 'subtitle_bold' => true, 'narration_lang' => 'en', 'workflow' => 'movie', 'mode' => 'dub']])->save();
        $this->package(['subtitle_style' => false, 'layout' => false, 'movie' => false]);
        $overrides = app(StudioPreferences::class)->overrides($this->customer->fresh());
        $this->assertSame(['narration_lang' => 'en'], $overrides);
    }

    public function test_presets_can_be_created_edited_duplicated_ordered_defaulted_and_deleted(): void
    {
        $this->actingAs($this->customer);
        $first = $this->preset('Movie Recap', ['narration_style' => 'suspense']);
        $second = $this->preset('Subtitle Pack', ['workflow' => 'subtitles', 'output' => 'srt']);
        $this->postJson('/settings/preferences/presets', ['name' => 'Movie Recap', 'settings' => $this->settings()])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->putJson('/settings/preferences/presets/'.$first, ['name' => 'Movie Recap (Standard)', 'settings' => $this->settings(['narration_style' => 'standard'])])
            ->assertOk()->assertJsonPath('preferences.presets.0.name', 'Movie Recap (Standard)')->assertJsonPath('preferences.presets.0.settings.narration_style', 'standard');
        $copy = $this->postJson('/settings/preferences/presets/'.$first.'/duplicate')->assertCreated()->json('preset_id');
        $this->assertSame('Movie Recap (Standard) (copy)', MediaPreset::find($copy)->name);
        $this->assertSame('subs', MediaPreset::find($second)->settings['mode']);

        $this->putJson('/settings/preferences/presets/order', ['ids' => [$second, $copy]])->assertUnprocessable();
        $this->putJson('/settings/preferences/presets/order', ['ids' => [$second, $copy, $first]])->assertOk()
            ->assertJsonPath('preferences.presets.0.id', $second)->assertJsonPath('preferences.presets.2.id', $first);

        $this->putJson('/settings/preferences/presets/default', ['preset_id' => $second])->assertOk()->assertJsonPath('preferences.presets.0.is_default', true);
        $this->get('/projects/new')->assertInertia(fn (Assert $page) => $page->where('personalDefaults.workflow', 'subtitles')->where('personalDefaults.output', 'srt'));
        $this->get('/projects/new?preset='.$first)->assertInertia(fn (Assert $page) => $page->where('personalDefaults.workflow', 'movie')->where('startPreset.id', $first));
        $this->deleteJson('/settings/preferences/presets/'.$second)->assertOk()->assertJsonCount(2, 'preferences.presets');
        $this->assertNull($this->customer->fresh()->default_preset_id);
        $this->putJson('/settings/preferences/presets/default', ['preset_id' => null])->assertOk();
    }

    public function test_preset_limit_and_ownership_are_enforced(): void
    {
        $other = User::factory()->create();
        $foreign = MediaPreset::create(['user_id' => $other->id, 'name' => 'Theirs', 'settings' => $this->settings()]);
        $this->actingAs($this->customer);
        for ($n = 1; $n <= 12; $n++) {
            $this->preset('Preset '.$n);
        }
        $this->postJson('/settings/preferences/presets', ['name' => 'Thirteen', 'settings' => $this->settings()])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/settings/preferences/presets/'.MediaPreset::where('user_id', $this->customer->id)->value('id').'/duplicate')->assertUnprocessable();
        $this->putJson('/settings/preferences/presets/'.$foreign->id, ['name' => 'Mine', 'settings' => $this->settings()])->assertNotFound();
        $this->deleteJson('/settings/preferences/presets/'.$foreign->id)->assertNotFound();
        $this->putJson('/settings/preferences/presets/default', ['preset_id' => $foreign->id])->assertNotFound();
        $this->get('/projects/new?preset='.$foreign->id)->assertInertia(fn (Assert $page) => $page->where('startPreset', null));
        $this->assertSame('Theirs', $foreign->fresh()->name);
    }

    public function test_voice_samples_are_generated_once_and_respect_plan_and_provider_state(): void
    {
        $providers = $this->mock(ProviderSettings::class);
        $providers->shouldReceive('ready')->andReturn(true);
        $providers->shouldReceive('resolved')->andReturn(['speech_model' => 'test-tts']);
        $gemini = $this->mock(GeminiClient::class);
        $gemini->shouldReceive('speech')->once()->andReturnUsing(function (string $text, string $voice, string $style, string $file, string $language): void {
            $this->assertSame('my', $language);
            file_put_contents($file, 'RIFF-sample');
        });
        $this->actingAs($this->customer);
        $this->get('/settings/preferences/voice-sample?voice=Kore&lang=my')->assertOk()->assertHeader('Content-Type', 'audio/wav');
        $this->get('/settings/preferences/voice-sample?voice=Kore&lang=my')->assertOk();
        $this->getJson('/settings/preferences/voice-sample?voice=Nobody&lang=my')->assertUnprocessable();
        $this->package(['voice_preview' => false]);
        $this->customer->unsetRelation('currentPackageAssignment');
        $this->getJson('/settings/preferences/voice-sample?voice=Kore&lang=my')->assertForbidden()->assertJsonPath('code', 'feature_unavailable');
    }

    public function test_voice_sample_reports_a_missing_provider(): void
    {
        $this->mock(ProviderSettings::class)->shouldReceive('ready')->andReturn(false);
        $this->actingAs($this->customer)->getJson('/settings/preferences/voice-sample?voice=Kore&lang=en')->assertStatus(503)->assertJsonPath('code', 'ai_key_missing');
    }

    public function test_studio_dictionary_endpoint_keeps_the_new_entry_details(): void
    {
        $entry = ['original' => 'Kapilavastu', 'translated' => 'ကပိလဝတ်', 'pronunciation' => '', 'type' => 'place', 'language' => 'my', 'note' => 'Hometown'];
        $this->actingAs($this->customer)->putJson('/media/name-dictionary', ['name_dictionary' => [$entry]])->assertOk()->assertJsonPath('name_dictionary.0.type', 'place');
        $this->assertSame('Hometown', $this->customer->fresh()->recap_name_dictionary[0]['note']);
    }
}
