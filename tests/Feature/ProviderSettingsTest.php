<?php

namespace Tests\Feature;

use App\Exceptions\MediaFailure;
use App\Jobs\ProcessMediaRun;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Models\User;
use App\Services\Media\GeminiClient;
use App\Services\Media\MediaPipeline;
use App\Services\Media\ProviderSettings;
use App\Support\MediaSettings;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProviderSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::factory()->create(['is_admin' => true]);
        config(['media.gemini_key' => 'server-key-for-testing-only-1234', 'media.gemini_model' => 'gemini-test-flash', 'media.gemini_tts_model' => 'gemini-test-tts']);
        Http::preventStrayRequests();
    }

    private function draft(): array
    {
        $current = app(ProviderSettings::class)->present();

        return collect($current)->only(['revision', 'enabled', 'text_models', 'speech_model', 'voice', 'speech_rate', 'voice_volume', 'timeout'])->all() + [
            'credentials' => array_map(fn (array $key): array => collect($key)->only(['id', 'label', 'enabled'])->all(), $current['credentials']),
        ];
    }

    private function providerOutput(array $data): array
    {
        return ['steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => json_encode($data)]]]]];
    }

    public function test_provider_page_exposes_only_masked_configuration_and_requires_the_admin_guard(): void
    {
        $this->get('/admin/settings/providers')->assertRedirect('/admin/login');
        $this->getJson('/admin/settings/providers/health')->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'web');
        foreach (['/admin/settings/providers/test', '/admin/settings/providers/models'] as $path) {
            $this->postJson($path, $this->draft())->assertUnauthorized();
        }
        $this->putJson('/admin/settings/providers', $this->draft())->assertUnauthorized();
        $this->getJson('/admin/settings/providers/health')->assertUnauthorized();
        $this->actingAs($this->admin, 'web')->putJson('/admin/settings/providers', $this->draft())->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'admin')->getJson('/admin/settings/providers/health')->assertForbidden();
        $this->actingAs($this->admin, 'admin')->get('/admin/settings/providers')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('settings/providers')->where('frame', 'apiKeysModels')->missing('provider.credentials.0.secret')
            ->where('provider.source', 'server')->where('provider.revision', 0)
            ->where('providerLinks.save', '/admin/settings/providers')
            ->where('providerLinks.test', '/admin/settings/providers/test')
            ->where('providerLinks.models', '/admin/settings/providers/models')
            ->where('providerLinks.health', '/admin/settings/providers/health')
            ->where('adminLinks.providers', '/admin/settings/providers')
            ->where('links.logout', '/admin/logout')
            ->missing('frameLinks.apiKeysModels'));
        Http::assertNothingSent();
    }

    public function test_legacy_user_provider_routes_do_not_execute_configuration_changes(): void
    {
        $this->get('/settings/providers')->assertRedirect('/admin/settings/providers');
        $this->actingAs($this->admin, 'web');
        $this->putJson('/settings/providers', $this->draft())->assertStatus(405);
        $this->postJson('/settings/providers/test', $this->draft())->assertNotFound();
        $this->postJson('/settings/providers/models', $this->draft())->assertNotFound();
        $this->getJson('/settings/providers/health')->assertNotFound();
        $this->assertSame(0, app(ProviderSettings::class)->revision());
        Http::assertNothingSent();
    }

    public function test_admin_form_reuses_saved_configuration_without_copying_or_reseeding_it(): void
    {
        $settings = app(ProviderSettings::class);
        $configuration = $settings->draft($this->draft());
        $revision = $settings->save($configuration, 0, $this->admin->id);
        $rows = DB::table('media_provider_revisions')->count();
        $this->actingAs($this->admin, 'admin')->get('/admin/settings/providers')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('provider.revision', $revision)->where('provider.source', 'workspace')
            ->where('provider.voice', $configuration['voice'])->missing('provider.credentials.0.secret'));
        $this->assertSame($rows, DB::table('media_provider_revisions')->count());
        $this->assertSame($revision, $settings->revision());
        Http::assertNothingSent();
    }

    public function test_simultaneous_customer_session_cannot_change_admin_audit_ownership(): void
    {
        $customer = User::factory()->create(['is_admin' => false]);
        $this->actingAs($customer, 'web');
        $response = $this->actingAs($this->admin, 'admin')->putJson('/admin/settings/providers', $this->draft())->assertOk();
        $this->assertDatabaseHas('media_provider_revisions', ['id' => $response->json('provider.revision'), 'created_by' => $this->admin->id]);
        $this->actingAs($customer, 'web')->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->missing('frameLinks.apiKeysModels')->missing('provider')->where('auth.user.is_admin', false));
    }

    public function test_admin_provider_writes_require_csrf_outside_the_test_bypass(): void
    {
        $draft = $this->draft();
        $this->actingAs($this->admin, 'admin');
        $this->app['env'] = 'local';
        $this->putJson('/admin/settings/providers', $draft)->assertStatus(419);
        $this->postJson('/admin/settings/providers/test', $draft)->assertStatus(419);
        $this->postJson('/admin/settings/providers/models', $draft)->assertStatus(419);
        $this->assertSame(0, app(ProviderSettings::class)->revision());
        Http::assertNothingSent();
    }

    public function test_save_encrypts_credentials_changes_runtime_and_rejects_stale_writes(): void
    {
        $draft = $this->draft();
        $draft['credentials'][0]['secret'] = 'replacement-private-secret-9999';
        $draft['voice'] = 'Charon';
        $draft['speech_rate'] = 1.15;
        $draft['timeout'] = 90;
        $response = $this->actingAs($this->admin, 'admin')->putJson('/admin/settings/providers', $draft)->assertOk();
        $this->assertStringNotContainsString($draft['credentials'][0]['secret'], $response->getContent());
        $id = $response->json('provider.revision');
        $raw = DB::table('media_provider_revisions')->where('id', $id)->value('configuration');
        $this->assertStringNotContainsString('replacement-private-secret', $raw);
        $this->assertSame('replacement-private-secret-9999', json_decode(Crypt::decryptString($raw), true)['credentials'][0]['secret']);
        $this->assertSame('Charon', MediaSettings::defaults()['voice']);
        $this->assertSame(1.15, MediaSettings::defaults()['speech_rate']);
        $this->assertSame('Kore', MediaSettings::normalize(['voice' => 'Kore'])['voice']);
        config(['media.gemini_key' => '', 'media.gemini_model' => 'disabled-env-model', 'media.gemini_tts_model' => 'disabled-env-tts']);
        Http::fake(['*interactions' => Http::response($this->providerOutput(['text' => 'short']))]);
        app(GeminiClient::class)->shorten('A line', 2);
        Http::assertSent(fn ($request): bool => $request->hasHeader('x-goog-api-key', 'replacement-private-secret-9999') && $request['model'] === 'gemini-test-flash');
        $this->putJson('/admin/settings/providers', $draft)->assertConflict();
        $this->assertSame($id, app(ProviderSettings::class)->revision());
    }

    public function test_disabled_configuration_never_reactivates_environment_credentials(): void
    {
        $draft = $this->draft();
        $draft['enabled'] = false;
        $draft['credentials'] = [];
        $this->actingAs($this->admin, 'admin')->putJson('/admin/settings/providers', $draft)->assertOk()->assertJsonPath('provider.ready', false);
        $this->assertFalse(app(ProviderSettings::class)->ready());
        try {
            app(GeminiClient::class)->shorten('hello', 2);
            $this->fail('Disabled provider was called.');
        } catch (MediaFailure $failure) {
            $this->assertSame('ai_key_missing', $failure->reason);
        }
        Http::assertNothingSent();
    }

    public function test_snapshot_keeps_old_credentials_after_rotation_and_worker_restores_context(): void
    {
        $settings = app(ProviderSettings::class);
        $id = $settings->snapshot();
        $run = MediaRun::factory()->create(['provider_revision_id' => $id]);
        $run->project->update(['active_run_id' => $run->id, 'status' => 'queued']);
        $draft = $this->draft();
        $draft['credentials'][0]['secret'] = 'rotated-workspace-key-5678';
        $this->actingAs($this->admin, 'admin')->putJson('/admin/settings/providers', $draft)->assertOk();
        $pipeline = $this->mock(MediaPipeline::class);
        $pipeline->shouldReceive('check')->once()->andReturnUsing(function () use ($settings): void {
            $this->assertSame('server-key-for-testing-only-1234', $settings->resolved()['credentials'][0]['secret']);
        });
        $pipeline->shouldReceive('analyze')->once()->andReturnUsing(function () use ($run): void {
            $run->update(['phase' => 'analysis']);
        });
        (new ProcessMediaRun($run->id))->handle($pipeline);
        $this->assertSame('rotated-workspace-key-5678', $settings->resolved()['credentials'][0]['secret']);
        $this->assertSame($id, $run->fresh()->provider_revision_id);
    }

    public function test_first_save_protects_legacy_queued_work_with_an_encrypted_baseline_snapshot(): void
    {
        $run = MediaRun::factory()->create();
        $draft = $this->draft();
        $draft['credentials'][0]['secret'] = 'new-workspace-key-12345678';
        $this->actingAs($this->admin, 'admin')->putJson('/admin/settings/providers', $draft)->assertOk();
        $id = $run->fresh()->provider_revision_id;
        $this->assertNotNull($id);
        $this->assertNotSame(app(ProviderSettings::class)->revision(), $id);
        $this->assertSame('server-key-for-testing-only-1234', app(ProviderSettings::class)->withRevision($id, fn () => app(ProviderSettings::class)->resolved()['credentials'][0]['secret']));
    }

    public function test_models_are_discovered_before_selection_and_model_fallback_is_used(): void
    {
        Http::fake(['*/models*' => Http::response(['models' => [
            ['name' => 'models/gemini-fallback-flash', 'displayName' => 'Fallback', 'supportedGenerationMethods' => ['generateContent']],
            ['name' => 'models/gemini-fallback-tts', 'displayName' => 'Speech', 'supportedGenerationMethods' => ['generateContent']],
            ['name' => 'models/embedding-001', 'supportedGenerationMethods' => ['embedContent']],
        ]])]);
        $draft = $this->draft();
        $this->actingAs($this->admin, 'admin')->postJson('/admin/settings/providers/models', $draft)->assertOk()->assertJsonCount(2, 'models');
        $draft['text_models'][] = 'gemini-fallback-flash';
        $draft['speech_model'] = 'gemini-fallback-tts';
        $this->putJson('/admin/settings/providers', $draft)->assertOk();
        Http::fake(['*interactions' => Http::sequence()->push([], 404)->push($this->providerOutput(['text' => 'short']))]);
        $this->assertSame('short', app(GeminiClient::class)->shorten('A line', 2));
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), 'interactions') && $request['model'] === 'gemini-fallback-flash');
        $invalid = $this->draft();
        $invalid['text_models'][] = 'gemini-unverified-flash';
        $this->putJson('/admin/settings/providers', $invalid)->assertUnprocessable()->assertJsonValidationErrors('text_models');
    }

    public function test_authentication_failure_uses_next_credential_but_rate_limits_do_not_rotate_keys_or_models(): void
    {
        $draft = $this->draft();
        $draft['credentials'][] = ['id' => (string) Str::uuid(), 'label' => 'Secondary', 'secret' => 'secondary-private-key-5678', 'enabled' => true];
        $this->actingAs($this->admin, 'admin')->putJson('/admin/settings/providers', $draft)->assertOk();
        Http::fake(['*interactions' => Http::sequence()->push([], 403)->push($this->providerOutput(['text' => 'short']))->push(['error' => ['details' => [['retryDelay' => '55s']]]], 429)]);
        $this->assertSame('short', app(GeminiClient::class)->shorten('line', 2));
        Http::assertSent(fn ($request): bool => $request->hasHeader('x-goog-api-key', 'secondary-private-key-5678'));
        foreach ([1, 2] as $attempt) {
            try {
                app(GeminiClient::class)->shorten('line', 2);
                $this->fail('Rate limit was ignored.');
            } catch (MediaFailure $failure) {
                $this->assertSame('provider_rate_limit', $failure->reason);
                $this->assertGreaterThanOrEqual(54, $failure->retryAfter);
            }
        }
        Http::assertSentCount(3);
    }

    public function test_draft_connection_tests_are_revision_specific_and_do_not_save_failed_keys(): void
    {
        $draft = $this->draft();
        $draft['credentials'][0]['secret'] = 'invalid-draft-private-key-1234';
        Http::fake(fn ($request) => $request->hasHeader('x-goog-api-key', 'invalid-draft-private-key-1234') ? Http::response(['error' => ['message' => 'SECRET must never be shown']], 403) : Http::response($this->providerOutput(['translations' => [['index' => 0, 'text' => 'Burmese result']]])));
        $this->actingAs($this->admin, 'admin')->postJson('/admin/settings/providers/test', $draft)->assertOk()->assertJsonPath('report.status', 'failed')->assertJsonPath('report.errorCode', 'provider_authentication')->assertDontSee('SECRET');
        $this->assertSame(0, app(ProviderSettings::class)->revision());
        $this->assertSame([], app(ProviderSettings::class)->present()['checks']);
        $this->postJson('/admin/settings/providers/test', $this->draft())->assertOk()->assertJsonPath('report.status', 'success');
        $this->assertSame('success', app(ProviderSettings::class)->present()['checks'][0]['status']);
        $this->assertSame(0, app(ProviderSettings::class)->revision());
    }

    public function test_provider_http_failures_log_only_safe_diagnostics_and_keep_responses_sanitized(): void
    {
        Log::spy();
        Http::fake(['*interactions' => Http::response(['error' => ['message' => 'server-key-for-testing-only-1234 private provider response']], 503)]);
        $revision = app(ProviderSettings::class)->current()['api_revision'];

        $this->actingAs($this->admin, 'admin')->postJson('/admin/settings/providers/test', $this->draft())
            ->assertOk()->assertJsonPath('report.status', 'failed')->assertJsonPath('report.errorCode', 'provider_unavailable')
            ->assertDontSee('server-key-for-testing-only-1234')->assertDontSee('private provider response');

        Log::shouldHaveReceived('warning')->once()->with('Media provider HTTP request failed.', [
            'model' => 'gemini-test-flash', 'http_status' => 503, 'api_revision' => $revision,
        ]);
    }

    public function test_provider_transport_failures_log_only_the_numeric_transport_code(): void
    {
        Log::spy();
        Http::fake(function () {
            throw new ConnectionException('private transport details', 0,
                new ConnectException('cURL error 28: server-key-for-testing-only-1234',
                    new Request('POST', 'https://example.test')));
        });

        $this->actingAs($this->admin, 'admin')->postJson('/admin/settings/providers/test', $this->draft())
            ->assertOk()->assertJsonPath('report.errorCode', 'provider_unavailable')
            ->assertDontSee('server-key-for-testing-only-1234')->assertDontSee('private transport details');

        Log::shouldHaveReceived('warning')->once()->with('Media provider connection failed.', [
            'model' => 'gemini-test-flash', 'transport_code' => 28,
        ]);
    }

    public function test_validation_rejects_arbitrary_models_bad_voices_and_unbounded_settings(): void
    {
        $draft = $this->draft();
        $draft['voice'] = 'not-supported';
        $draft['timeout'] = 4000;
        $draft['speech_model'] = 'https://localhost/attack';
        $draft['credentials'][0]['secret'] = "bad\nheader";
        $this->actingAs($this->admin, 'admin')->putJson('/admin/settings/providers', $draft)->assertUnprocessable()->assertJsonValidationErrors(['voice', 'timeout', 'speech_model', 'credentials.0.secret']);
        $draft = $this->draft();
        $draft['text_models'][] = $draft['text_models'][0];
        $this->putJson('/admin/settings/providers', $draft)->assertUnprocessable();
        $this->assertSame(0, app(ProviderSettings::class)->revision());
    }

    public function test_new_analysis_and_preview_jobs_capture_the_saved_revision(): void
    {
        Queue::fake();
        $this->actingAs($this->admin, 'admin')->putJson('/admin/settings/providers', $this->draft())->assertOk();
        $customer = User::factory()->create(['is_admin' => false]);
        $project = MediaProject::factory()->create(['user_id' => $customer->id]);
        $this->actingAs($customer, 'web');
        $this->postJson('/media/projects/'.$project->id.'/start')->assertAccepted();
        $this->assertSame(app(ProviderSettings::class)->revision(), $project->fresh()->activeRun->provider_revision_id);
        $project = MediaProject::factory()->create(['user_id' => $customer->id, 'settings' => MediaSettings::normalize(['mode' => 'dub'])]);
        $response = $this->postJson('/media/projects/'.$project->id.'/previews', ['title' => $project->title, 'kind' => 'voice']);
        $response->assertAccepted();
        $this->assertSame(app(ProviderSettings::class)->revision(), $project->previews()->first()->provider_revision_id);
    }

    public function test_real_tts_test_produces_a_private_expiring_audio_sample(): void
    {
        Storage::fake('local');
        $pcm = str_repeat("\0", 48000);
        Http::fake(['*interactions' => Http::response(['steps' => [['type' => 'model_output', 'content' => [['type' => 'audio', 'data' => base64_encode($pcm), 'mime_type' => 'audio/L16']]]]])]);
        $draft = $this->draft() + ['capability' => 'speech'];
        $response = $this->actingAs($this->admin, 'admin')->postJson('/admin/settings/providers/test', $draft)->assertOk()->assertJsonPath('report.status', 'success');
        $url = $response->json('report.sampleUrl');
        $this->assertIsString($url);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'audio/wav');
        $this->actingAs(User::factory()->create(['is_admin' => true]), 'admin')->get($url)->assertNotFound();
        $this->actingAs($this->admin, 'admin');
        $this->travel(11)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_discovery_accepts_pending_model_choices_after_a_key_change_and_checks_the_new_key(): void
    {
        $draft = $this->draft();
        $draft['credentials'][0]['secret'] = 'new-discovery-credential-1234';
        $draft['text_models'] = ['gemini-discovered-flash'];
        Http::fake(['*/models*' => Http::response(['models' => [
            ['name' => 'models/gemini-discovered-flash', 'supportedGenerationMethods' => ['generateContent']],
        ]])]);
        $this->actingAs($this->admin, 'admin')->postJson('/admin/settings/providers/models', $draft)->assertOk();
        Http::assertSent(fn ($request): bool => $request->hasHeader('x-goog-api-key', 'new-discovery-credential-1234'));
        $this->putJson('/admin/settings/providers', $draft)->assertOk()->assertJsonPath('provider.text_models.0', 'gemini-discovered-flash');
    }

    public function test_validation_never_flashes_secrets_even_when_an_html_request_is_sent(): void
    {
        $draft = $this->draft();
        $draft['credentials'][0]['secret'] = 'secret-not-for-session-1234';
        $draft['timeout'] = 9999;
        $this->actingAs($this->admin, 'admin')->from('/admin/settings/providers')->put('/admin/settings/providers', $draft)->assertRedirect('/admin/settings/providers');
        $this->assertNull(session()->getOldInput('credentials'));
    }
}
