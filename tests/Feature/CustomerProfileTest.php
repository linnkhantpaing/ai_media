<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CustomerProfile;
use App\Services\StudioPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomerProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->customer = User::factory()->create(['name' => 'Aye', 'email' => 'aye@example.test', 'password' => Hash::make('OldPassword!2026'), 'preferred_locale' => 'en']);
    }

    /** @return array<string, mixed> */
    private function payload(array $profile): array
    {
        return ['settings' => app(StudioPreferences::class)->baseline(), 'name_dictionary' => [],
            'profile' => array_replace_recursive(['name' => 'Aye', 'display' => ['timezone' => null, 'date_format' => null, 'time_format' => null]], $profile)];
    }

    public function test_general_tab_shows_the_customers_own_account_without_package_controls(): void
    {
        $this->actingAs($this->customer)->get('/settings/preferences')->assertInertia(fn (Assert $page) => $page
            ->where('preferences.profile.name', 'Aye')->where('preferences.profile.email', 'aye@example.test')->where('preferences.profile.avatar_url', null)
            ->where('preferences.profile.display.timezone', null)->where('preferences.profile.photo_storage_ready', false)
            ->has('preferences.profile.timezones')->missing('preferences.profile.password'));
    }

    public function test_name_and_personal_date_display_are_saved_and_shared_with_every_page(): void
    {
        $this->actingAs($this->customer)->putJson('/settings/preferences', $this->payload(['name' => '  Aye Aye ', 'display' => ['timezone' => 'Asia/Yangon', 'time_format' => '12']]))
            ->assertOk()->assertJsonPath('preferences.profile.name', 'Aye Aye')->assertJsonPath('preferences.profile.display.timezone', 'Asia/Yangon');
        $this->get('/projects')->assertInertia(fn (Assert $page) => $page->where('auth.user.name', 'Aye Aye')->where('app.timezone', 'Asia/Yangon')->where('app.time_format', '12'));
        $this->putJson('/settings/preferences', $this->payload(['name' => ' ']))->assertUnprocessable()->assertJsonValidationErrors('profile.name');
        $this->putJson('/settings/preferences', $this->payload(['display' => ['timezone' => 'Mars/Base', 'date_format' => 'weird']]))
            ->assertUnprocessable()->assertJsonValidationErrors(['profile.display.timezone', 'profile.display.date_format']);
        $this->assertDatabaseHas('customer_account_events', ['user_id' => $this->customer->id, 'type' => 'self_profile_updated']);
    }

    public function test_only_myanmar_thailand_japan_and_korea_time_zones_are_offered_and_applied(): void
    {
        $this->actingAs($this->customer)->get('/settings/preferences')
            ->assertInertia(fn (Assert $page) => $page->where('preferences.profile.timezones', ['Asia/Yangon', 'Asia/Bangkok', 'Asia/Tokyo', 'Asia/Seoul']));
        $this->putJson('/settings/preferences', $this->payload(['display' => ['timezone' => 'Africa/Accra']]))->assertUnprocessable()->assertJsonValidationErrors('profile.display.timezone');
        foreach (['Asia/Bangkok', 'Asia/Tokyo', 'Asia/Seoul', 'Asia/Yangon'] as $zone) {
            $this->putJson('/settings/preferences', $this->payload(['display' => ['timezone' => $zone]]))->assertOk();
            $this->get('/projects')->assertInertia(fn (Assert $page) => $page->where('app.timezone', $zone));
        }
        $this->customer->forceFill(['display_preferences' => ['timezone' => 'Africa/Accra', 'time_format' => '12']])->save();
        $this->get('/projects')->assertInertia(fn (Assert $page) => $page->where('app.timezone', 'Asia/Bangkok')->where('app.time_format', '12'));
        $this->get('/settings/preferences')->assertInertia(fn (Assert $page) => $page->where('preferences.profile.display.timezone', null));
    }

    public function test_email_change_requires_the_current_password_and_a_free_address(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);
        $this->actingAs($this->customer);
        $this->putJson('/settings/preferences/email', ['email' => 'new@example.test', 'password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson('/settings/preferences/email', ['email' => 'TAKEN@example.test', 'password' => 'OldPassword!2026'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $version = $this->customer->fresh()->auth_version;
        $this->putJson('/settings/preferences/email', ['email' => ' New@Example.test ', 'password' => 'OldPassword!2026'])->assertOk()->assertJsonPath('preferences.profile.email', 'new@example.test');
        $this->assertGreaterThan($version, $this->customer->fresh()->auth_version);
        $this->getJson('/settings/preferences/avatar')->assertNotFound();
        $this->get('/settings/preferences')->assertOk();
        $this->assertDatabaseHas('customer_account_events', ['user_id' => $this->customer->id, 'type' => 'self_email_changed']);
    }

    public function test_password_change_validates_rules_keeps_this_session_and_records_the_date(): void
    {
        $this->actingAs($this->customer);
        $this->putJson('/settings/preferences/password', ['current_password' => 'nope', 'password' => 'NewPassword!2027', 'password_confirmation' => 'NewPassword!2027'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson('/settings/preferences/password', ['current_password' => 'OldPassword!2026', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson('/settings/preferences/password', ['current_password' => 'OldPassword!2026', 'password' => 'OldPassword!2026', 'password_confirmation' => 'OldPassword!2026'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson('/settings/preferences/password', ['current_password' => 'OldPassword!2026', 'password' => 'NewPassword!2027', 'password_confirmation' => 'NewPassword!2027'])
            ->assertOk()->assertJsonPath('preferences.profile.password_changed_at', fn (?string $value) => $value !== null);
        $this->assertTrue(Hash::check('NewPassword!2027', $this->customer->fresh()->password));
        $this->get('/settings/preferences')->assertOk();
    }

    public function test_profile_photo_is_stored_only_in_cloud_storage_and_can_be_removed(): void
    {
        $this->actingAs($this->customer)->post('/settings/preferences/photo', ['photo' => UploadedFile::fake()->image('me.png', 300, 200)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('photo');

        config(['filesystems.media_disk' => 'r2']);
        Storage::fake('r2');
        $this->post('/settings/preferences/photo', ['photo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/settings/preferences/photo', ['photo' => UploadedFile::fake()->image('me.png', 300, 200)], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('preferences.profile.avatar_url', fn (?string $url) => str_starts_with((string) $url, '/settings/preferences/avatar'));
        $path = $this->customer->fresh()->avatar_path;
        Storage::disk('r2')->assertExists($path);
        $this->assertSame([200, 200], array_slice(getimagesizefromstring(Storage::disk('r2')->get($path)), 0, 2));
        $this->assertSame([], Storage::disk('local')->allFiles('avatars'));
        $this->get('/settings/preferences/avatar')->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->get('/projects')->assertInertia(fn (Assert $page) => $page->where('auth.user.avatar_url', fn (?string $url) => $url !== null));
        $adminUrl = '/admin/users/'.$this->customer->id.'/avatar';
        $this->get($adminUrl)->assertRedirect();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin, 'admin')->get('/admin/users')->assertInertia(fn (Assert $page) => $page
            ->where('accounts.users.0.avatar_url', fn (?string $url) => str_starts_with((string) $url, $adminUrl.'?v=')));
        $this->get($adminUrl)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->get('/admin/users/'.$admin->id.'/avatar')->assertNotFound();
        $this->actingAs($this->customer);

        $this->deleteJson('/settings/preferences/photo')->assertOk()->assertJsonPath('preferences.profile.avatar_url', null);
        Storage::disk('r2')->assertMissing($path);
    }

    public function test_devices_list_and_sign_out_only_touch_this_customers_sessions(): void
    {
        config(['session.driver' => 'database']);
        $other = User::factory()->create();
        DB::table('sessions')->insert([
            ['id' => 'current-session', 'user_id' => $this->customer->id, 'ip_address' => '203.0.113.10', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0 Safari/537.36', 'payload' => '', 'last_activity' => now()->timestamp],
            ['id' => 'phone-session', 'user_id' => $this->customer->id, 'ip_address' => '198.51.100.7', 'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/604.1', 'payload' => '', 'last_activity' => now()->subHour()->timestamp],
            ['id' => 'foreign-session', 'user_id' => $other->id, 'ip_address' => '192.0.2.1', 'user_agent' => 'Firefox/120.0', 'payload' => '', 'last_activity' => now()->timestamp],
        ]);
        $service = app(CustomerProfile::class);
        $devices = $service->devices($this->customer, 'current-session');
        $this->assertCount(2, $devices);
        $this->assertTrue($devices[0]['current']);
        $this->assertSame(['Chrome', 'Windows', '203.0.xx.xx'], [$devices[0]['browser'], $devices[0]['os'], $devices[0]['ip']]);
        $this->assertSame(['Safari', 'iOS', 'mobile'], [$devices[1]['browser'], $devices[1]['os'], $devices[1]['kind']]);
        $this->assertStringNotContainsString('phone-session', json_encode($devices));

        $service->signOutDevice($this->customer, $devices[1]['key'], 'current-session');
        $this->assertDatabaseMissing('sessions', ['id' => 'phone-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'foreign-session']);

        DB::table('sessions')->insert(['id' => 'tablet-session', 'user_id' => $this->customer->id, 'ip_address' => '198.51.100.8', 'user_agent' => 'Android', 'payload' => '', 'last_activity' => now()->timestamp]);
        $this->actingAs($this->customer)->postJson('/settings/preferences/devices/sign-out-others', ['password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $service->signOutOtherDevices($this->customer->fresh(), 'current-session');
        $this->assertSame(['current-session'], DB::table('sessions')->where('user_id', $this->customer->id)->pluck('id')->all());
        $this->assertDatabaseHas('sessions', ['id' => 'current-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'foreign-session']);
    }
}
