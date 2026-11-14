<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminPortalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class AdminAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function administrator(): User
    {
        return User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'AdminPassword!123',
            'is_admin' => true,
        ]);
    }

    public function test_admin_routes_redirect_guests_to_the_separate_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/dashboard')->assertRedirect('/admin/login');
        $this->get('/')->assertRedirect('/login');
        $this->get('/admin/login')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/login')->where('auth.user', null)
            ->where('links.login', '/admin/login')->where('links.logout', '/admin/logout')
            ->where('links.workspace', '/admin/dashboard')->where('links.locale', '/admin/locale')
        );
    }

    public function test_admin_sign_in_normalizes_email_regenerates_session_and_does_not_sign_into_web_guard(): void
    {
        $admin = $this->administrator();
        $this->get('/admin/login');
        $before = session()->getId();
        $this->post('/admin/login', ['email' => ' ADMIN@EXAMPLE.COM ', 'password' => 'AdminPassword!123'])
            ->assertSessionHasNoErrors()->assertRedirect('/admin/dashboard');
        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertGuest('web');
        $this->assertNotSame($before, session()->getId());
        $this->get('/admin/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/dashboard')->where('auth.user.email', $admin->email)
            ->where('auth.user.is_admin', true)->missing('auth.user.password')->missing('auth.user.remember_token')
        );
        $this->get('/')->assertRedirect('/login');
    }

    public function test_a_web_session_even_for_an_admin_does_not_authorize_the_admin_area(): void
    {
        $admin = $this->administrator();
        $this->actingAs($admin, 'web')->get('/admin/dashboard')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.user', null));
        $this->assertGuest('admin');
    }

    public function test_non_admin_unknown_and_wrong_password_attempts_have_the_same_generic_error(): void
    {
        $this->administrator();
        User::factory()->create(['email' => 'user@example.com', 'password' => 'UserPassword!123']);
        $message = null;
        foreach ([['admin@example.com', 'WrongPassword!'], ['user@example.com', 'UserPassword!123'], ['unknown@example.com', 'UnknownPassword!']] as [$email, $password]) {
            $this->from('/admin/login')->post('/admin/login', ['email' => $email, 'password' => $password, 'is_admin' => true])
                ->assertRedirect('/admin/login')->assertSessionHasErrors('email')->assertSessionMissing('_old_input.password');
            $current = session('errors')->first('email');
            if ($message !== null) {
                $this->assertSame($message, $current);
            }
            $message = $current;
            $this->assertGuest('admin');
        }
    }

    public function test_admin_login_validates_input_and_the_bcrypt_byte_limit(): void
    {
        $this->post('/admin/login', ['email' => 'invalid', 'password' => ''])->assertSessionHasErrors(['email', 'password']);
        $this->post('/admin/login', ['email' => 'admin@example.com', 'password' => str_repeat('a', 73)])->assertSessionHasErrors('password');
        $this->assertGuest('admin');
    }

    public function test_failed_admin_attempts_are_throttled_and_expire_without_blocking_user_login(): void
    {
        $admin = $this->administrator();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/admin/login', ['email' => $admin->email, 'password' => 'Incorrect!'])->assertSessionHasErrors('email');
        }
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'AdminPassword!123'])->assertSessionHasErrors('email');
        $this->assertGuest('admin');
        $this->post('/login', ['email' => $admin->email, 'password' => 'AdminPassword!123'])->assertRedirect('/');
        $this->travel(61)->seconds();
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'AdminPassword!123'])->assertRedirect('/admin/dashboard');
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_admin_login_never_follows_untrusted_or_user_area_intended_redirects(): void
    {
        $admin = $this->administrator();
        $this->withSession(['url.intended' => 'https://attacker.example/steal'])
            ->post('/admin/login', ['email' => $admin->email, 'password' => 'AdminPassword!123'])->assertRedirect('/admin/dashboard');
    }

    public function test_user_login_does_not_follow_an_admin_area_intended_redirect(): void
    {
        $user = User::factory()->create(['password' => 'UserPassword!123']);
        $this->withSession(['url.intended' => '/admin/dashboard'])
            ->post('/login', ['email' => $user->email, 'password' => 'UserPassword!123'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertGuest('admin');
    }

    public function test_authenticated_admin_is_redirected_away_from_admin_login(): void
    {
        $this->actingAs($this->administrator(), 'admin')->get('/admin/login')->assertRedirect('/admin/dashboard');
        $this->get('/admin')->assertRedirect('/admin/dashboard');
    }

    public function test_admin_logout_rotates_session_and_csrf_but_preserves_a_separate_user_session(): void
    {
        $user = User::factory()->create();
        $admin = $this->administrator();
        $this->actingAs($user, 'web');
        $this->actingAs($admin, 'admin')->get('/admin/dashboard')->assertOk();
        $oldSession = session()->getId();
        $oldToken = session()->token();
        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $this->assertGuest('admin');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame($oldSession, session()->getId());
        $this->assertNotSame($oldToken, session()->token());
        $this->get('/admin/dashboard')->assertRedirect('/admin/login');
        $this->get('/')->assertOk();
    }

    public function test_an_admin_session_cannot_access_the_dashboard_after_role_revocation(): void
    {
        $admin = $this->administrator();
        $this->actingAs($admin, 'admin')->get('/admin/dashboard')->assertOk();
        $admin->forceFill(['is_admin' => false])->save();
        $this->get('/admin/dashboard')->assertForbidden();
        $this->post('/admin/logout')->assertRedirect('/admin/login');
    }

    public function test_password_change_revokes_an_existing_admin_session(): void
    {
        $admin = $this->administrator();
        $this->actingAs($admin, 'admin')->get('/admin/dashboard')->assertOk();
        $admin->password = 'NewPassword!123';
        $admin->save();
        $this->get('/admin/dashboard')->assertRedirect('/admin/login');
        $this->assertGuest('admin');
    }

    public function test_admin_locale_updates_only_the_admin_identity_when_both_are_signed_in(): void
    {
        $user = User::factory()->create(['preferred_locale' => 'en']);
        $admin = $this->administrator();
        $this->actingAs($user, 'web');
        $this->actingAs($admin, 'admin')->post('/admin/locale', ['locale' => 'th', 'return_to' => '/admin/dashboard'])->assertRedirect('/admin/dashboard');
        $this->assertSame('th', $admin->fresh()->preferred_locale);
        $this->assertSame('en', $user->fresh()->preferred_locale);
        $this->get('/admin/dashboard')->assertInertia(fn (Assert $page) => $page->where('locale', 'th'));
    }

    public function test_admin_login_does_not_issue_a_persistent_remember_cookie_by_default(): void
    {
        $admin = $this->administrator();
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'AdminPassword!123'])
            ->assertRedirect('/admin/dashboard')->assertCookieMissing(Auth::guard('admin')->getRecallerName());
        $this->assertSame($admin->remember_token, $admin->fresh()->remember_token);
    }

    public function test_remember_me_restores_only_the_admin_guard_and_expires_after_seven_days(): void
    {
        $admin = $this->administrator();
        $name = Auth::guard('admin')->getRecallerName();
        $response = $this->post('/admin/login', ['email' => $admin->email, 'password' => 'AdminPassword!123', 'remember' => true])
            ->assertRedirect('/admin/dashboard')->assertCookie($name)->assertCookieMissing(Auth::guard('web')->getRecallerName());
        $cookie = $response->getCookie($name);
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $cookie->getExpiresTime(), 3);
        $this->assertTrue($cookie->isHttpOnly());
        $this->app['session']->flush();
        Auth::forgetGuards();
        $this->withCookie($name, $cookie->getValue())->get('/admin/dashboard')->assertOk();
        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertTrue(Auth::guard('admin')->viaRemember());
        $this->assertGuest('web');
        $this->post('/admin/logout')->assertRedirect('/admin/login')->assertCookieExpired($name);
    }

    public function test_a_remembered_admin_cannot_access_the_dashboard_after_role_revocation(): void
    {
        $admin = $this->administrator();
        $name = Auth::guard('admin')->getRecallerName();
        $response = $this->post('/admin/login', ['email' => $admin->email, 'password' => 'AdminPassword!123', 'remember' => true]);
        $cookie = $response->getCookie($name);
        $admin->forceFill(['is_admin' => false])->save();
        $this->app['session']->flush();
        Auth::forgetGuards();
        $this->withCookie($name, $cookie->getValue())->get('/admin/dashboard')->assertForbidden();
        $this->assertGuest('web');
    }

    public function test_admin_login_and_logout_require_csrf_and_logout_cannot_use_get(): void
    {
        $this->get('/admin/logout')->assertStatus(405);
        $this->app['env'] = 'local';
        $this->post('/admin/login', ['email' => 'admin@example.com', 'password' => 'AnyPassword!123'])->assertStatus(419);
        $this->actingAs($this->administrator(), 'admin')->post('/admin/logout')->assertStatus(419);
    }

    public function test_expired_inertia_admin_form_returns_to_admin_login(): void
    {
        $this->app['env'] = 'local';
        $this->withHeader('X-Inertia', 'true')->post('/admin/login', ['email' => 'admin@example.com', 'password' => 'AnyPassword!123'])
            ->assertRedirect('/admin/login')->assertSessionHasErrors('email');
    }

    public function test_admin_responses_have_private_cache_and_browser_protection_headers(): void
    {
        $response = $this->get('/admin/login')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_portal_seeder_hashes_the_password_is_idempotent_and_preserves_password_changes(): void
    {
        config(['admin.portal_seed' => ['name' => 'Portal Admin', 'email' => ' ADMIN@EXAMPLE.COM ', 'password' => '123456']]);
        $this->seed(AdminPortalSeeder::class);
        $admin = User::query()->firstOrFail();
        $this->assertTrue($admin->is_admin);
        $this->assertTrue(Hash::check('123456', $admin->password));
        $this->assertNotSame('123456', $admin->password);
        $this->post('/admin/login', ['email' => $admin->email, 'password' => '123456'])->assertRedirect('/admin/dashboard');
        $admin->password = 'ChangedPassword!456';
        $admin->save();
        $this->seed(AdminPortalSeeder::class);
        $this->assertDatabaseCount('users', 1);
        $this->assertTrue(Hash::check('ChangedPassword!456', $admin->fresh()->password));
    }

    public function test_portal_seeder_never_promotes_an_existing_user_account(): void
    {
        $user = User::factory()->create(['email' => 'existing@example.com']);
        config(['admin.portal_seed' => ['name' => 'Portal Admin', 'email' => $user->email, 'password' => '123456']]);
        try {
            $this->seed(AdminPortalSeeder::class);
            $this->fail('An existing non-admin account must not be promoted.');
        } catch (RuntimeException) {
            $this->assertFalse($user->fresh()->is_admin);
            $this->assertDatabaseCount('users', 1);
        }
    }

    public function test_portal_development_seeder_cannot_run_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->expectException(RuntimeException::class);
        $this->app->make(AdminPortalSeeder::class)->run();
    }
}
