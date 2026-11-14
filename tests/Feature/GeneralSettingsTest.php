<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\GeneralSettings;
use App\Services\PlatformMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GeneralSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();
        config(['operations.auto_launch_allowed' => false]);
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($this->admin, 'admin');
    }

    private function configuration(array $overrides = []): array
    {
        $c = array_replace_recursive(app(GeneralSettings::class)->defaults(), $overrides);
        unset($c['main_logo'],$c['light_logo'],$c['favicon']);

        return [...$c, 'revision' => app(GeneralSettings::class)->current()['revision']];
    }

    private function save(array $overrides = []): TestResponse
    {
        return $this->postJson('/admin/settings/general', ['configuration' => $this->configuration($overrides)]);
    }

    public function test_admin_authorization_is_required_for_read_save_and_smtp_tests(): void
    {
        Auth::guard('admin')->logout();
        $this->get('/admin/settings/general')->assertRedirect('/admin/login');
        $this->postJson('/admin/settings/general', [])->assertUnauthorized();
        $this->postJson('/admin/settings/general/test', [])->assertUnauthorized();
        $customer = User::factory()->create(['is_admin' => false]);
        $this->actingAs($customer, 'web');
        $this->getJson('/admin/settings/general')->assertUnauthorized();
        $this->actingAs($customer, 'admin')->getJson('/admin/settings/general')->assertForbidden();
    }

    public function test_page_shows_safe_defaults_and_working_navigation(): void
    {
        $this->get('/admin/settings/general')->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/general-settings')
            ->where('general.revision', 0)->where('general.configuration.name', 'ZATLAN')
            ->where('general.configuration.smtp.password', '')->where('general.has_password', false)
            ->where('adminLinks.generalSettings', '/admin/settings/general'));
    }

    public function test_save_is_atomic_encrypted_and_immediately_shared_without_env_changes(): void
    {
        Mail::fake();
        $response = $this->save(['name' => 'Studio Updated', 'language' => 'th', 'timezone' => 'Asia/Yangon', 'date_format' => 'iso', 'time_format' => '12',
            'support_email' => 'support@example.com', 'support_url' => 'https://example.com/help',
            'smtp' => ['enabled' => true, 'host' => 'smtp.example.com', 'username' => 'mailer@example.com', 'password' => 'PRIVATE-SMTP-SECRET', 'from_email' => 'sender@example.com']]);
        $response->assertOk()->assertJsonPath('general.revision', 1)->assertJsonPath('general.configuration.smtp.password', '')->assertJsonPath('general.has_password', true);
        $this->assertStringNotContainsString('PRIVATE-SMTP-SECRET', $response->getContent());
        $raw = DB::table('platform_settings')->value('configuration');
        $this->assertStringNotContainsString('PRIVATE-SMTP-SECRET', $raw);
        $this->assertStringNotContainsString('smtp.example.com', $raw);
        $this->assertSame('PRIVATE-SMTP-SECRET', PlatformSetting::findOrFail(1)->configuration['smtp']['password']);
        $this->get('/admin/settings/general')->assertInertia(fn (Assert $page) => $page->where('app.name', 'Studio Updated')->where('app.timezone', 'Asia/Yangon')->missing('app.smtp')->missing('app.password'));
        $audit = DB::table('platform_setting_events')->first();
        $this->assertStringNotContainsString('PRIVATE', $audit->fields);
        $this->assertSame($this->admin->id, $audit->actor_id);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_blank_password_preserves_secret_and_replacement_updates_secret(): void
    {
        $this->save(['smtp' => ['password' => 'old-secret']])->assertOk();
        $this->save(['smtp' => ['password' => '']])->assertOk();
        $this->assertSame('old-secret', app(GeneralSettings::class)->current()['configuration']['smtp']['password']);
        $this->save(['smtp' => ['password' => 'new-secret']])->assertOk();
        $this->assertSame('new-secret', app(GeneralSettings::class)->current()['configuration']['smtp']['password']);
    }

    public function test_invalid_values_return_field_errors_and_do_not_save_partial_configuration(): void
    {
        $this->save(['name' => '', 'language' => 'xx', 'timezone' => 'Invalid/Zone', 'support_url' => 'javascript:alert(1)', 'smtp' => ['enabled' => true, 'host' => 'bad/host', 'from_email' => 'invalid']])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'language', 'timezone', 'support_url', 'smtp.host', 'smtp.from_email']);
        $this->assertDatabaseCount('platform_settings', 0);
        $this->save(['smtp' => ['enabled' => true, 'host' => 'example.com', 'encryption' => 'none', 'from_email' => 'sender@example.com']])->assertUnprocessable()->assertJsonValidationErrors('smtp.encryption');
    }

    public function test_stale_revision_cannot_overwrite_saved_settings_or_upload_files(): void
    {
        $this->save(['name' => 'First'])->assertOk();
        $c = $this->configuration(['name' => 'Stale']);
        $c['revision'] = 0;
        $this->postJson('/admin/settings/general', ['configuration' => $c])->assertConflict();
        $this->assertSame('First', app(GeneralSettings::class)->current()['configuration']['name']);
        $this->assertDatabaseCount('platform_setting_events', 1);
    }

    public function test_only_valid_saved_branding_assets_are_public_and_discard_does_not_change_logo(): void
    {
        Storage::fake('local');
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1sAAAAASUVORK5CYII=');
        $upload = UploadedFile::fake()->createWithContent('brand.png', $image);
        $response = $this->post('/admin/settings/general', ['configuration' => json_encode($this->configuration()), 'main_logo' => $upload], ['Accept' => 'application/json']);
        $response->assertOk();
        $url = $response->json('app.logo');
        $this->assertStringStartsWith('/branding/', $url);
        Auth::guard('admin')->logout();
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/branding/00000000-0000-0000-0000-000000000000.png')->assertNotFound();
        $this->actingAs($this->admin, 'admin');
        $this->post('/admin/settings/general', ['configuration' => json_encode($this->configuration()), 'main_logo' => UploadedFile::fake()->createWithContent('x.svg', '<svg/>')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame($url, app(GeneralSettings::class)->publicData()['logo']);
    }

    public function test_maintenance_needs_confirmation_and_blocks_only_new_work(): void
    {
        $this->save(['maintenance' => ['enabled' => true, 'message' => 'Scheduled maintenance']])->assertUnprocessable()->assertJsonValidationErrors('confirm_maintenance');
        $c = $this->configuration(['maintenance' => ['enabled' => true, 'message' => 'Scheduled maintenance']]);
        $c['confirm_maintenance'] = true;
        $this->postJson('/admin/settings/general', ['configuration' => $c])->assertOk();
        $customer = User::factory()->create(['is_admin' => false]);
        $this->actingAs($customer, 'web');
        $this->postJson('/media/projects', [])->assertStatus(503)->assertJsonPath('code', 'platform_maintenance')->assertJsonPath('message', 'Scheduled maintenance');
        $this->postJson('/media/projects/youtube', [])->assertStatus(503);
        $this->get('/projects')->assertOk();
        $this->get('/admin/settings/general')->assertOk();
        $this->save(['maintenance' => ['enabled' => false]])->assertOk();
        $this->postJson('/media/projects', [])->assertUnprocessable();
    }

    public function test_default_language_changes_only_visitors_without_a_preference(): void
    {
        $this->save(['language' => 'th'])->assertOk();
        Auth::guard('admin')->logout();
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('locale', 'th'));
        $this->withCookie('ui_locale', 'xx')->get('/login')->assertInertia(fn (Assert $page) => $page->where('locale', 'th'));
        $this->withCookie('ui_locale', 'en')->get('/login')->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
    }

    public function test_smtp_uses_saved_revision_records_safe_result_and_does_not_send_on_save(): void
    {
        $this->mock(PlatformMail::class, function ($mock): void {
            $mock->shouldReceive('test')->once()->withArgs(fn (array $c, ?string $recipient) => $c['smtp']['host'] === 'smtp.example.com' && $recipient === null);
        });
        $this->save(['smtp' => ['enabled' => true, 'host' => 'smtp.example.com', 'from_email' => 'sender@example.com']])->assertOk();
        $savedAt = PlatformSetting::findOrFail(1)->updated_at->toISOString();
        $this->postJson('/admin/settings/general/test', ['revision' => 1, 'kind' => 'connection'])->assertOk()->assertJsonPath('report.status', 'success');
        $this->assertSame($savedAt, PlatformSetting::findOrFail(1)->updated_at->toISOString());
        $this->postJson('/admin/settings/general/test', ['revision' => 99, 'kind' => 'connection'])->assertConflict();
        $this->save(['smtp' => ['host' => 'changed.example.com']])->assertOk()->assertJsonPath('general.mail_report', null);
    }

    public function test_test_email_requires_explicit_recipient_and_hides_provider_secrets_on_failure(): void
    {
        $this->save(['smtp' => ['enabled' => true, 'host' => 'smtp.example.com', 'from_email' => 'sender@example.com']])->assertOk();
        $this->postJson('/admin/settings/general/test', ['revision' => 1, 'kind' => 'email'])->assertUnprocessable()->assertJsonValidationErrors('recipient');
        $this->mock(PlatformMail::class, function ($mock): void {
            $mock->shouldReceive('test')->once()->withArgs(fn (array $c, ?string $recipient) => $recipient === 'test@example.com')->andThrow(new \RuntimeException('Failed to authenticate PRIVATE-SECRET'));
        });
        $result = $this->postJson('/admin/settings/general/test', ['revision' => 1, 'kind' => 'email', 'recipient' => 'test@example.com']);
        $result->assertOk()->assertJsonPath('report.code', 'smtp_authentication_failed');
        $this->assertStringNotContainsString('PRIVATE-SECRET', $result->getContent());
        $this->assertStringNotContainsString('PRIVATE-SECRET', DB::table('platform_setting_events')->orderByDesc('id')->value('fields'));
    }

    public function test_turned_off_smtp_cannot_be_tested(): void
    {
        $this->save()->assertOk();
        $this->postJson('/admin/settings/general/test', ['revision' => 1, 'kind' => 'connection'])->assertUnprocessable()->assertJsonValidationErrors('smtp.enabled');
    }
}
