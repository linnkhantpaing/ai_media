<?php

namespace Tests\Feature;

use App\Models\CustomerPackageAssignment;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;
use App\Services\Media\MediaBackground;
use App\Services\PackageCatalog;
use App\Services\UsageLedger;
use App\Support\MediaSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PackageEntitlementMappingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private Package $package;

    private PackageVersion $version;

    private CustomerPackageAssignment $assignment;

    private MediaProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->customer = User::factory()->create(['managed_account' => true]);
        $this->package = Package::factory()->create(['name' => 'Starter', 'status' => 'active', 'published_version' => 1]);
        $this->version = PackageVersion::create(['package_id' => $this->package->id, 'number' => 1, 'definition' => $this->package->definition, 'actor_id' => $this->admin->id]);
        $this->assignment = CustomerPackageAssignment::create(['user_id' => $this->customer->id, 'package_id' => $this->package->id, 'package_version_id' => $this->version->id, 'assigned_by' => $this->admin->id, 'source' => 'payment_new',
            'snapshot' => ['name' => 'Starter', 'code' => $this->package->code, 'version' => 1, 'type' => 'paid', 'definition' => $this->package->definition], 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(29)]);
        $this->project = MediaProject::factory()->create(['user_id' => $this->customer->id, 'settings' => MediaSettings::normalize(MediaSettings::defaults())]);
        $this->project->update(['metadata' => [...($this->project->metadata ?? []), 'duration' => 120]]);
        $this->actingAs($this->customer, 'web');
        $this->mock(MediaBackground::class, fn ($mock) => $mock->shouldReceive('available')->andReturn(true));
    }

    /** @param  array<string, bool>  $features */
    private function features(array $features): void
    {
        $definition = $this->version->definition;
        $definition['features'] = [...$definition['features'], ...$features];
        $this->version->update(['definition' => $definition]);
    }

    private function save(array $changes): TestResponse
    {
        return $this->patchJson('/media/projects/'.$this->project->id, ['title' => 'Recap', 'settings' => [...$this->project->fresh()->settings, ...$changes]]);
    }

    public function test_definitions_without_a_movie_flag_inherit_it_from_narrated_dubbing(): void
    {
        $this->assertTrue(PackageCatalog::features(['features' => ['dub' => true]])['movie']);
        $this->assertFalse(PackageCatalog::features(['features' => ['dub' => false]])['movie']);
        $this->assertFalse(PackageCatalog::features(['features' => ['dub' => true, 'movie' => false]])['movie']);
        $this->assertSame(PackageCatalog::FEATURES, array_keys(PackageCatalog::features([])));
        $this->assertArrayNotHasKey('movie', $this->version->definition['features']);
        $this->save(['workflow' => 'movie', 'mode' => 'dub', 'target_seconds' => 30])->assertOk();
    }

    public function test_summarize_movie_and_translate_recap_are_separate_entitlements(): void
    {
        $this->features(['movie' => false, 'dub' => true]);
        $this->save(['workflow' => 'movie', 'mode' => 'dub', 'target_seconds' => 30])->assertUnprocessable()->assertJsonValidationErrors('settings.workflow');
        $this->save(['workflow' => 'translate', 'mode' => 'dub'])->assertOk();
        $this->features(['movie' => true, 'dub' => false]);
        $this->save(['workflow' => 'translate', 'mode' => 'dub'])->assertUnprocessable()->assertJsonValidationErrors('settings.mode');
        $this->save(['workflow' => 'movie', 'mode' => 'dub', 'target_seconds' => 30])->assertOk();
    }

    public function test_styling_cover_and_layout_changes_require_their_features_but_defaults_stay_allowed(): void
    {
        $this->features(['subtitle_style' => false, 'cover_subtitles' => false, 'layout' => false]);
        $this->save([])->assertOk();
        $this->save(['subtitle_color' => '#FF0000'])->assertUnprocessable()->assertJsonValidationErrors('settings.subtitle_font');
        $this->save(['cover_mode' => 'manual'])->assertUnprocessable()->assertJsonValidationErrors('settings.cover_mode');
        $this->save(['aspect' => '9:16'])->assertUnprocessable()->assertJsonValidationErrors('settings.aspect');
        $this->save(['cover_mode' => 'off', 'cover_top' => 0.5])->assertOk();
        $this->assertSame(MediaSettings::defaults()['aspect'], $this->project->fresh()->settings['aspect']);
        $this->features(['subtitle_style' => true, 'cover_subtitles' => true, 'layout' => true]);
        $this->save(['subtitle_color' => '#FF0000', 'cover_mode' => 'manual', 'aspect' => '9:16'])->assertOk();
    }

    public function test_values_already_saved_on_a_project_are_kept_after_a_plan_loses_the_feature(): void
    {
        $this->project->update(['settings' => [...$this->project->settings, 'aspect' => '9:16']]);
        $this->features(['layout' => false]);
        $this->save(['voice_volume' => 1.2])->assertOk();
        $this->save(['aspect' => '1:1'])->assertUnprocessable()->assertJsonValidationErrors('settings.aspect');
    }

    public function test_processing_reservation_enforces_the_same_mapping(): void
    {
        $this->features(['movie' => false]);
        $run = MediaRun::factory()->create(['media_project_id' => $this->project->id, 'settings' => MediaSettings::normalize([...$this->project->settings, 'workflow' => 'movie', 'mode' => 'dub'])]);
        try {
            app(UsageLedger::class)->reserve($run);
            $this->fail('A package without Summarize a Long Movie must not reserve a movie recap.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('settings.workflow', $error->errors());
        }
        $this->assertDatabaseCount('usage_records', 0);
    }

    public function test_source_types_are_checked_before_upload_or_import_starts(): void
    {
        Bus::fake();
        $this->features(['local' => false, 'youtube' => false]);
        $this->postJson('/media/projects', ['client_id' => (string) Str::uuid(), 'filename' => 'Chase.mp4', 'size' => 12, 'fingerprint' => str_repeat('a', 64)])
            ->assertForbidden()->assertJsonPath('code', 'feature_unavailable');
        $this->postJson('/media/projects/youtube', ['client_id' => (string) Str::uuid(), 'url' => 'https://www.youtube.com/watch?v=jNQXAC9IVRw'])
            ->assertForbidden()->assertJsonPath('code', 'feature_unavailable');
        $this->assertDatabaseCount('media_projects', 1);
        Bus::assertNothingDispatched();
    }

    public function test_studio_receives_normalized_features_plan_state_and_billing_link(): void
    {
        $this->get('/projects/new')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('features.movie', true)->where('features.dub', true)->where('planActive', true)->where('billingUrl', '/account/billing'));
        $this->assignment->update(['ends_at' => now()->subMinute()]);
        $this->customer->unsetRelation('currentPackageAssignment');
        $this->get('/projects/new')->assertOk()->assertInertia(fn (Assert $page) => $page->where('planActive', false));
        $this->postJson('/media/projects', [])->assertForbidden()
            ->assertJsonPath('code', 'subscription_unavailable')->assertJsonPath('billing_url', '/account/billing');
    }

    public function test_a_movie_only_package_can_be_published_and_admin_sees_the_movie_flag(): void
    {
        $this->actingAs($this->admin, 'admin');
        $definition = $this->package->definition;
        $definition['features'] = [...$definition['features'], 'movie' => true, 'dub' => false, 'subs' => false, 'voice_preview' => true];
        $catalog = app(PackageCatalog::class);
        $method = new \ReflectionMethod($catalog, 'validateForPublication');
        $method->invoke($catalog, [...$definition, 'included_seconds' => $definition['included_minutes'] * 60, 'storage_bytes' => (int) ($definition['storage_gib'] * 1073741824), 'max_upload_bytes' => $definition['max_upload_mib'] * 1048576]);
        $this->getJson('/admin/packages/'.$this->package->id)->assertOk()->assertJsonPath('package.definition.features.movie', true)->assertJsonPath('versions.0.definition.features.movie', true);
    }
}
