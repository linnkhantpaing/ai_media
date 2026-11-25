<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Services\GeneralSettings;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function owner(): User
    {
        $owner = User::factory()->create(['email' => 'owner@example.com', 'password' => 'OwnerPassword!123']);
        $this->enableMail();

        return $owner;
    }

    private function enableMail(bool $enabled = true): void
    {
        $settings = app(GeneralSettings::class);
        $c = $settings->current()['configuration'];
        $c['smtp'] = ['enabled' => $enabled, 'host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'tls',
            'username' => '', 'password' => '', 'from_name' => 'ZATLAN', 'from_email' => 'sender@example.test', 'reply_to' => ''];
        $settings->save([...$c, 'revision' => $settings->current()['revision']], [], User::factory()->create(['is_admin' => true]));
    }

    public function test_password_recovery_says_when_email_cannot_be_sent_without_revealing_accounts(): void
    {
        Notification::fake();
        $owner = $this->owner();
        $this->enableMail(false);

        foreach ([$owner->email, 'unknown@example.com'] as $email) {
            $this->post('/forgot-password', ['email' => $email])->assertSessionHas('status', 'mail_unavailable')->assertSessionHasNoErrors();
        }
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_form_errors_return_to_a_page_reached_by_an_in_app_link(): void
    {
        $this->get('/login')->assertOk();
        $version = (string) app(HandleInertiaRequests::class)->version(request());
        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Requested-With' => 'XMLHttpRequest'])
            ->get('/forgot-password')->assertOk();
        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Requested-With' => 'XMLHttpRequest'])
            ->post('/forgot-password', ['email' => 'not-an-email'])->assertRedirect('/forgot-password');
    }

    public function test_an_expired_reset_link_offers_a_prefilled_request_for_a_new_one(): void
    {
        $this->get('/forgot-password?email=owner%40example.com')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/forgot-password')->where('email', 'owner@example.com'));
        $this->get('/forgot-password?email=not-an-email')->assertInertia(fn (Assert $page) => $page->where('email', ''));
    }

    public function test_guests_are_redirected_to_login_and_can_view_the_login_form(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('auth/login')
            ->where('auth.user', null)
            ->where('locale', 'my')
        );
    }

    public function test_owner_can_sign_in_with_normalized_email_and_a_regenerated_session(): void
    {
        $owner = $this->owner();
        $this->get('/login');
        $oldSessionId = session()->getId();

        $this->post('/login', ['email' => ' OWNER@EXAMPLE.COM ', 'password' => 'OwnerPassword!123'])
            ->assertSessionHasNoErrors()->assertRedirect('/');

        $this->assertAuthenticatedAs($owner);
        $this->assertNotSame($oldSessionId, session()->getId());
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('workspace')->where('auth.user.email', $owner->email)->missing('auth.user.password')
        );
    }

    public function test_invalid_credentials_and_unknown_accounts_receive_the_same_error_without_flashing_passwords(): void
    {
        $this->owner();

        $this->post('/login', ['email' => 'owner@example.com', 'password' => 'IncorrectSecret!'])
            ->assertSessionHasErrors('email')->assertSessionMissing('_old_input.password');
        $knownAccountError = session('errors')->first('email');

        $this->post('/login', ['email' => 'missing@example.com', 'password' => 'IncorrectSecret!'])
            ->assertSessionHasErrors('email')->assertSessionMissing('_old_input.password');

        $this->assertSame($knownAccountError, session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_login_requires_valid_email_password_and_boolean_remember_input(): void
    {
        $this->post('/login', ['email' => 'invalid-email', 'password' => '', 'remember' => 'arbitrary'])
            ->assertSessionHasErrors(['email', 'password', 'remember']);

        $this->assertGuest();
    }

    public function test_login_rejects_passwords_beyond_the_bcrypt_byte_limit(): void
    {
        $password = str_repeat('Aa1!', 18);
        User::factory()->create(['email' => 'owner@example.com', 'password' => $password]);

        $this->post('/login', ['email' => 'owner@example.com', 'password' => $password.'extra'])
            ->assertSessionHasErrors('password');

        $this->assertGuest();
    }

    public function test_five_failed_attempts_temporarily_block_even_a_correct_password(): void
    {
        $owner = $this->owner();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $owner->email, 'password' => 'WrongPassword!'])
                ->assertSessionHasErrors('email');
        }

        $this->post('/login', ['email' => $owner->email, 'password' => 'OwnerPassword!123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->travel(61)->seconds();

        $this->post('/login', ['email' => $owner->email, 'password' => 'OwnerPassword!123'])
            ->assertSessionHasNoErrors()->assertRedirect('/');
        $this->assertAuthenticatedAs($owner);
    }

    public function test_remember_me_is_opt_in(): void
    {
        $owner = $this->owner();

        $this->post('/login', ['email' => $owner->email, 'password' => 'OwnerPassword!123', 'remember' => true])
            ->assertSessionHasNoErrors()->assertRedirect('/');

        $this->assertNotNull($owner->fresh()->remember_token);
        $this->assertAuthenticatedAs($owner);
    }

    public function test_logout_invalidates_authentication_and_regenerates_the_csrf_token(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->get('/');
        $oldToken = session()->token();

        $this->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertNotSame($oldToken, session()->token());
        $this->get('/')->assertRedirect('/login');
    }

    public function test_an_external_intended_url_cannot_redirect_the_owner_off_site(): void
    {
        $owner = $this->owner();

        $this->withSession(['url.intended' => 'https://attacker.example/collect'])
            ->post('/login', ['email' => $owner->email, 'password' => 'OwnerPassword!123'])
            ->assertRedirect('/');
    }

    public function test_signed_in_users_cannot_open_guest_authentication_pages(): void
    {
        $this->actingAs($this->owner())->get('/login')->assertRedirect('/');
        $this->get('/forgot-password')->assertRedirect('/');
    }

    public function test_public_registration_is_not_available(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['email' => 'new@example.com', 'password' => 'Password!12345'])->assertNotFound();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_password_recovery_sends_a_reset_notification_without_revealing_account_existence(): void
    {
        Notification::fake();
        $owner = $this->owner();

        $this->post('/forgot-password', ['email' => $owner->email])
            ->assertSessionHas('status', 'reset_link_sent');

        Notification::assertSentTo($owner, ResetPassword::class);
        $this->post('/forgot-password', ['email' => 'unknown@example.com'])
            ->assertSessionHas('status', 'reset_link_sent')->assertSessionHasNoErrors();

        Notification::assertCount(1);
    }

    public function test_reset_links_use_the_configured_app_origin_instead_of_an_untrusted_host(): void
    {
        Notification::fake();
        $owner = $this->owner();
        config(['app.url' => 'https://media.example.com']);

        $this->withHeader('Host', 'untrusted.example')->post('/forgot-password', ['email' => $owner->email]);

        Notification::assertSentTo($owner, ResetPassword::class, function (ResetPassword $notification) use ($owner): bool {
            $url = $notification->toMail($owner)->actionUrl;
            $this->assertStringStartsWith('https://media.example.com/reset-password/', $url);
            $this->assertStringNotContainsString('untrusted.example', $url);

            return true;
        });
    }

    public function test_valid_reset_changes_the_hash_rotates_remember_token_and_cannot_be_reused(): void
    {
        $owner = $this->owner();
        $oldRememberToken = $owner->remember_token;
        $token = Password::createToken($owner);
        $newPassword = 'FreshOwnerPassword!456';
        $data = ['email' => $owner->email, 'token' => $token, 'password' => $newPassword, 'password_confirmation' => $newPassword];

        $this->post('/reset-password', $data)
            ->assertRedirect('/login')->assertSessionHas('status', 'password_updated');

        $this->assertTrue(Hash::check($newPassword, $owner->fresh()->password));
        $this->assertNotSame($oldRememberToken, $owner->fresh()->remember_token);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $owner->email]);
        $this->assertGuest();

        $this->post('/reset-password', $data)->assertSessionHasErrors('email');
    }

    public function test_weak_mismatched_and_expired_password_resets_do_not_change_the_password(): void
    {
        $owner = $this->owner();
        $oldHash = $owner->password;
        $token = Password::createToken($owner);

        $this->post('/reset-password', ['email' => $owner->email, 'token' => $token, 'password' => 'weak', 'password_confirmation' => 'different'])
            ->assertSessionHasErrors('password');

        $this->travel(61)->minutes();

        $this->post('/reset-password', ['email' => $owner->email, 'token' => $token, 'password' => 'StrongNewPassword!123', 'password_confirmation' => 'StrongNewPassword!123'])
            ->assertSessionHasErrors('email');

        $this->assertSame($oldHash, $owner->fresh()->password);
    }

    public function test_a_token_for_one_account_cannot_reset_another_account(): void
    {
        $owner = $this->owner();
        $other = User::factory()->create();
        $oldHash = $other->password;
        $token = Password::createToken($owner);

        $this->post('/reset-password', ['email' => $other->email, 'token' => $token, 'password' => 'StrongNewPassword!123', 'password_confirmation' => 'StrongNewPassword!123'])
            ->assertSessionHasErrors('email');

        $this->assertSame($oldHash, $other->fresh()->password);
    }

    public function test_password_reset_revokes_existing_database_sessions(): void
    {
        config(['session.driver' => 'database']);
        $owner = $this->owner();
        DB::table('sessions')->insert(['id' => 'old-owner-session', 'user_id' => $owner->id, 'payload' => base64_encode(''), 'last_activity' => time()]);
        $token = Password::createToken($owner);

        $this->post('/reset-password', ['email' => $owner->email, 'token' => $token, 'password' => 'FreshOwnerPassword!456', 'password_confirmation' => 'FreshOwnerPassword!456'])
            ->assertSessionHasNoErrors()->assertRedirect('/login');

        $this->assertDatabaseMissing('sessions', ['id' => 'old-owner-session']);
    }

    public function test_login_responses_are_private_and_have_browser_security_headers(): void
    {
        $this->get('/login')->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $this->assertStringContainsString('no-store', $this->get('/login')->headers->get('Cache-Control'));
    }

    public function test_production_https_responses_include_csp_and_hsts(): void
    {
        $this->app['env'] = 'production';

        $response = $this->get('https://localhost/login')->assertOk();

        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("'nonce-", $response->headers->get('Content-Security-Policy'));
        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_login_rejects_a_post_without_csrf_when_not_in_the_test_environment(): void
    {
        $this->app['env'] = 'local';

        $this->post('/login', ['email' => 'owner@example.com', 'password' => 'AnyPassword!123'])->assertStatus(419);
        $this->assertGuest();
    }

    public function test_owner_creation_uses_an_explicit_password_and_does_not_seed_defaults(): void
    {
        $this->seed();
        $this->assertDatabaseCount('users', 0);

        $this->artisan('app:create-owner owner@example.com')
            ->expectsQuestion('Owner name', 'Studio Owner')
            ->expectsQuestion('Password (12+ characters, upper/lower case, number and symbol; max 72 bytes)', 'OwnerPassword!123')
            ->expectsQuestion('Confirm password', 'OwnerPassword!123')
            ->assertSuccessful();

        $owner = User::firstOrFail();
        $this->assertTrue(Hash::check('OwnerPassword!123', $owner->password));
        $this->assertNotSame('OwnerPassword!123', $owner->password);
        $this->artisan('app:create-owner other@example.com')->assertFailed();
        $this->assertDatabaseCount('users', 1);
    }
}
