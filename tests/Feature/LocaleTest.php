<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guest_can_select_each_supported_locale_without_authentication(): void
    {
        foreach (['my', 'en', 'th'] as $locale) {
            $this->from('/login')->post('/locale', ['locale' => $locale])
                ->assertStatus(303)->assertRedirect('/login')->assertCookie('ui_locale', $locale);

            $this->withCookie('ui_locale', $locale)->get('/login')->assertOk()
                ->assertSee('<html lang="'.$locale.'">', false)
                ->assertInertia(fn (Assert $page) => $page->where('locale', $locale));
        }
    }

    public function test_unsupported_locale_is_rejected(): void
    {
        $this->post('/locale', ['locale' => 'fr'])->assertSessionHasErrors('locale');
    }

    public function test_authenticated_locale_is_saved_and_loaded_from_the_profile(): void
    {
        $owner = User::factory()->create(['preferred_locale' => 'my']);

        $this->actingAs($owner)->from('/')->post('/locale', ['locale' => 'th'])->assertStatus(303);

        $this->assertSame('th', $owner->fresh()->preferred_locale);
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->where('locale', 'th'));
    }

    public function test_validation_messages_follow_the_selected_ui_language(): void
    {
        $this->withCookie('ui_locale', 'th')->post('/login', ['email' => 'invalid', 'password' => ''])
            ->assertSessionHasErrors(['email' => 'กรุณากรอกอีเมลที่ถูกต้อง']);
    }

    public function test_language_switch_preserves_the_current_frame_without_a_referrer(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/locale', [
            'locale' => 'en', 'return_to' => '/projects?status=draft',
        ])->assertStatus(303)->assertRedirect('/projects?status=draft');

        $this->get('/projects?status=draft')->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'en')->where('frame', 'drafts'));
    }

    public function test_locale_redirect_rejects_external_and_unsafe_targets(): void
    {
        foreach (['https://example.com', '//example.com', '/\example.com', "/projects\r\nX-Injected: yes"] as $target) {
            $this->post('/locale', ['locale' => 'en', 'return_to' => $target])
                ->assertSessionHasErrors('return_to');
        }
    }
}
