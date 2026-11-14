<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FrameNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_every_administrator_frame_destination_renders_behind_authentication(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $destinations = [
            '/projects' => 'allProjects',
            '/projects?status=draft' => 'drafts',
            '/projects?status=awaiting_review' => 'awaitingReview',
            '/projects?status=processing' => 'processing',
            '/projects?status=completed' => 'completed',
            '/projects?status=needs_attention' => 'needsAttention',
            '/operations/jobs' => 'jobMonitor',
            '/operations/workers' => 'workerHealth',
            '/settings/preferences' => 'preferences',
        ];

        foreach ($destinations as $url => $frame) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->get('/projects/new')->assertRedirect('/login');
        $this->actingAs($admin);
        $this->get('/projects/new')->assertOk()->assertInertia(fn (Assert $page) => $page->component('projects/create'));
        foreach (['setup', 'script', 'preview', 'processing', 'result'] as $step) {
            $this->get('/projects/new/'.$step)->assertRedirect('/projects/new');
        }
        foreach ($destinations as $url => $frame) {
            $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component(str_starts_with($url, '/projects') ? 'projects/index' : (str_starts_with($url, '/operations') ? ($frame === 'jobMonitor' ? 'operations/jobs' : 'operations/workers') : ($frame === 'apiKeysModels' ? 'settings/providers' : ($frame === 'projectDefaults' ? 'settings/defaults' : ($frame === 'storageRetention' ? 'settings/storage' : 'frame')))))->where('frame', $frame));
        }
    }

    public function test_admin_navigation_shares_only_safe_account_information_and_real_links(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/')->assertInertia(fn (Assert $page) => $page
            ->component('workspace')
            ->where('auth.user.is_admin', true)
            ->missing('auth.user.password')->missing('auth.user.remember_token')
            ->where('frameLinks.dashboard', '/')
            ->where('frameLinks.drafts', '/projects?status=draft')
            ->where('frameLinks.jobMonitor', '/operations/jobs')
            ->missing('frameLinks.apiKeysModels')->missing('frameLinks.storageRetention')->missing('frameLinks.projectDefaults')
            ->where('frameLinks.scriptReview', '/projects/new/script')
        );
    }

    public function test_members_cannot_access_or_discover_administrative_destinations(): void
    {
        $member = User::factory()->create(['is_admin' => false]);
        $this->actingAs($member);

        foreach (['/operations/jobs', '/operations/workers'] as $path) {
            $this->get($path)->assertForbidden();
        }
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->missing('frameLinks.jobMonitor')->missing('frameLinks.workerHealth')
            ->missing('frameLinks.apiKeysModels')->missing('frameLinks.projectDefaults')
            ->missing('frameLinks.storageRetention')
            ->where('frameLinks.preferences', '/settings/preferences')
        );
        $this->get('/settings/preferences')->assertOk();
        $this->get('/projects/new')->assertOk();
    }

    public function test_unknown_project_filters_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/projects?status=invalid')->assertNotFound();
        $this->get('/projects?status[]=draft')->assertNotFound();
    }
}
