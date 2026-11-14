<?php

namespace Tests\Feature;

use App\Models\CustomerAccountEvent;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;
use App\Notifications\CustomerWelcome;
use App\Services\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CustomerLoginInformationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->freezeTime();
        config(['operations.auto_launch_allowed' => false, 'app.url' => 'https://studio.example.test']);
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($this->admin, 'admin');
        $this->package = Package::factory()->trial()->create(['status' => 'active', 'published_version' => 1]);
        PackageVersion::create(['package_id' => $this->package->id, 'number' => 1, 'definition' => $this->package->definition, 'actor_id' => $this->admin->id]);
        $this->smtp();
        Notification::fake();
    }

    private function smtp(bool $enabled = true): void
    {
        $settings = app(GeneralSettings::class);
        $c = $settings->current()['configuration'];
        $c['smtp'] = ['enabled' => $enabled, 'host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'tls',
            'username' => 'sender@example.test', 'password' => 'FAKE-SMTP-SECRET', 'from_name' => 'ZATLAN', 'from_email' => 'sender@example.test', 'reply_to' => 'support@example.test'];
        $settings->save([...$c, 'revision' => $settings->current()['revision']], [], $this->admin);
    }

    private function payload(array $extra = []): array
    {
        return array_replace(['name' => 'New Customer', 'email' => 'customer@example.test', 'password' => 'PrivatePass!2026',
            'password_confirmation' => 'PrivatePass!2026', 'preferred_locale' => 'en', 'note' => '', 'status' => 'active',
            'send_login' => true, 'request_id' => (string) Str::uuid(), 'package_id' => $this->package->id, 'package_version' => 1,
            'validity_mode' => 'package', 'starts_on' => now()->toDateString(), 'ends_on' => now()->addDays(30)->toDateString()], $extra);
    }

    private function resend(User $user, array $extra = []): TestResponse
    {
        return $this->postJson('/admin/users/'.$user->id.'/login-information', [...['request_id' => (string) Str::uuid(), 'revision' => $user->account_revision], ...$extra]);
    }

    public function test_creation_toggle_uses_saved_smtp_and_sends_a_private_link_once(): void
    {
        $payload = $this->payload();
        $result = $this->postJson('/admin/users', $payload)->assertCreated()->assertJsonPath('delivery', 'sent');
        $customer = User::findOrFail($result->json('customer.id'));
        Notification::assertSentTo($customer, CustomerWelcome::class, function ($notification) use ($customer): bool {
            $mail = $notification->toMail($customer);
            $this->assertSame('platform', $mail->mailer);
            $this->assertStringStartsWith('https://studio.example.test/reset-password/', $notification->resetUrl);
            $this->assertSame('https://studio.example.test/login', $notification->loginUrl);
            $this->assertStringContainsString($customer->email, json_encode($mail->toArray()));
            $this->assertStringNotContainsString('PrivatePass!2026', json_encode($mail->toArray()));
            $this->assertStringNotContainsString('FAKE-SMTP-SECRET', json_encode($mail->toArray()));
            $this->assertSame(60, $notification->expiresMinutes);

            return true;
        });
        $this->postJson('/admin/users', $payload)->assertOk()->assertJsonPath('delivery', 'not_repeated');
        Notification::assertSentToTimes($customer, CustomerWelcome::class, 1);
        $event = CustomerAccountEvent::where('type', 'welcome_email')->firstOrFail();
        $this->assertStringNotContainsString('reset-password', $event->toJson());
        $this->assertStringNotContainsString('PrivatePass', $event->toJson());
        $this->assertTrue(Hash::check('PrivatePass!2026', $customer->password));
    }

    public function test_delivery_uses_saved_database_smtp_and_sender_instead_of_environment_defaults(): void
    {
        Notification::shouldReceive('send')->once()->andReturnUsing(function ($notifiable, $notification): void {
            $this->assertTrue(config('mail.default') === 'platform');
            $this->assertTrue(config('mail.mailers.platform.host') === 'smtp.example.test');
            $this->assertTrue(config('mail.mailers.platform.password') === 'FAKE-SMTP-SECRET');
            $this->assertTrue(config('mail.from.address') === 'sender@example.test');
            $this->assertTrue(config('mail.reply_to.address') === 'support@example.test');
            $this->assertInstanceOf(CustomerWelcome::class, $notification);
        });
        $this->postJson('/admin/users', $this->payload())->assertCreated()->assertJsonPath('delivery', 'sent');
    }

    public function test_off_toggle_creates_account_without_email_even_with_smtp_ready(): void
    {
        $this->postJson('/admin/users', $this->payload(['send_login' => false]))->assertCreated()->assertJsonPath('delivery', 'not_requested');
        Notification::assertNothingSent();
        $this->getJson('/admin/users')->assertOk()->assertJsonPath('mailReady', true);
    }

    public function test_disabled_smtp_does_not_fall_back_to_environment_mail_and_does_not_partially_create(): void
    {
        $this->smtp(false);
        config(['mail.default' => 'smtp']);
        $this->postJson('/admin/users', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('send_login');
        $this->assertDatabaseCount('users', 1);
        Notification::assertNothingSent();
        $this->getJson('/admin/users')->assertOk()->assertJsonPath('mailReady', false);
    }

    public function test_delivery_failure_keeps_account_password_and_trial_and_can_be_retried(): void
    {
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('PRIVATE-provider-error'));
        $result = $this->postJson('/admin/users', $this->payload())->assertCreated()->assertJsonPath('delivery', 'failed');
        $customer = User::findOrFail($result->json('customer.id'));
        $this->assertTrue(Hash::check('PrivatePass!2026', $customer->password));
        $this->assertNotNull($customer->currentPackageAssignment);
        $this->assertStringNotContainsString('PRIVATE-provider-error', $result->getContent());
        $this->assertDatabaseHas('customer_account_events', ['user_id' => $customer->id, 'type' => 'welcome_email']);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Notification::fake();
        $this->travel(61)->seconds();
        $this->resend($customer)->assertOk()->assertJsonPath('delivery', 'sent');
        Notification::assertSentTo($customer, CustomerWelcome::class);
    }

    public function test_resend_requires_admin_guard_and_excludes_admin_and_archived_accounts(): void
    {
        $customer = User::factory()->create(['is_admin' => false]);
        Auth::guard('admin')->logout();
        $this->actingAs($customer, 'web');
        $this->resend($customer)->assertUnauthorized();
        $this->actingAs($customer, 'admin');
        $this->resend($customer)->assertForbidden();
        $this->actingAs($this->admin, 'admin');
        $this->resend($this->admin)->assertNotFound();
        $customer->forceFill(['account_status' => 'archived'])->save();
        $this->resend($customer)->assertConflict();
        Notification::assertNothingSent();
    }

    public function test_resend_has_revision_idempotency_and_customer_cooldown(): void
    {
        $customer = User::factory()->create(['is_admin' => false]);
        $this->resend($customer, ['revision' => 999])->assertConflict();
        $request = ['request_id' => (string) Str::uuid(), 'revision' => $customer->account_revision];
        $this->resend($customer, $request)->assertOk()->assertJsonPath('replayed', false);
        $this->resend($customer, $request)->assertOk()->assertJsonPath('replayed', true);
        $this->resend($customer)->assertTooManyRequests();
        Notification::assertSentToTimes($customer, CustomerWelcome::class, 1);
        $this->getJson('/admin/users/'.$customer->id)->assertOk()->assertJsonPath('mailReady', true)->assertJsonPath('activity.0.metadata.delivery', 'sent');
    }

    public function test_sent_link_sets_a_password_once_without_granting_a_paid_package(): void
    {
        $customer = User::factory()->create(['is_admin' => false, 'preferred_locale' => 'en']);
        $this->resend($customer)->assertOk();
        $notice = Notification::sent($customer, CustomerWelcome::class)->first();
        $token = basename(parse_url($notice->resetUrl, PHP_URL_PATH));
        $this->assertTrue(Password::broker('users')->tokenExists($customer, $token));
        $payload = ['email' => $customer->email, 'token' => $token, 'password' => 'NewPrivatePass!2026', 'password_confirmation' => 'NewPrivatePass!2026'];
        $this->post('/reset-password', $payload)->assertRedirect('/login');
        $this->assertTrue(Hash::check('NewPrivatePass!2026', $customer->fresh()->password));
        $this->assertFalse(Password::broker('users')->tokenExists($customer, $token));
        $this->postJson('/reset-password', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('customer_package_assignments', 0);
    }

    public function test_email_and_its_password_link_expire_and_follow_customer_language(): void
    {
        foreach (['my', 'th', 'en'] as $locale) {
            $customer = User::factory()->create(['is_admin' => false, 'preferred_locale' => $locale]);
            $this->resend($customer)->assertOk();
            $notice = Notification::sent($customer, CustomerWelcome::class)->first();
            $this->assertNotEmpty($notice->toMail($customer)->subject);
        }
        $token = basename(parse_url($notice->resetUrl, PHP_URL_PATH));
        $this->travel(61)->minutes();
        $this->assertFalse(Password::broker('users')->tokenExists($customer, $token));
    }
}
