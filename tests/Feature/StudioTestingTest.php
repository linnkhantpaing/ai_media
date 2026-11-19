<?php

namespace Tests\Feature;

use App\Exceptions\MediaFailure;
use App\Models\CustomerPackageAssignment;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;
use App\Services\Media\MediaStorage;
use App\Services\Media\StorageSettings;
use App\Services\UsageLedger;
use App\Support\StudioTesting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class StudioTestingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        config(['filesystems.media_disk' => 'local', 'media.studio_testing' => true]);
    }

    public function test_bypass_cannot_enable_outside_local_and_can_be_switched_off(): void
    {
        $this->assertFalse(StudioTesting::enabled());
        $this->app->instance('env', 'production');
        $this->assertFalse(StudioTesting::enabled());
        $this->app->instance('env', 'local');
        $this->assertTrue(StudioTesting::enabled());
        config(['media.studio_testing' => false]);
        $this->assertFalse(StudioTesting::enabled());
    }

    public function test_local_studio_exposes_unrestricted_features_and_long_duration_without_overwriting_policy(): void
    {
        $this->app->instance('env', 'local');
        $policy = app(StorageSettings::class)->policy();
        $user = $this->restrictedCustomer();
        $this->actingAs($user)->get('/projects/new')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('testingMode', true)->where('features', null)->where('limits.duration', 86400)
            ->where('limits.bytes', $policy['max_bytes']));
        $this->assertSame($policy, app(StorageSettings::class)->policy());
    }

    public function test_local_customer_can_exceed_package_limits_and_usage_stays_idempotent(): void
    {
        $this->app->instance('env', 'local');
        $user = $this->restrictedCustomer();
        $project = MediaProject::factory()->create(['user_id' => $user->id, 'metadata' => ['duration' => 7200, 'audio' => true]]);
        $run = MediaRun::factory()->create(['media_project_id' => $project->id]);
        $ledger = app(UsageLedger::class);
        $this->assertSame([], $ledger->featureErrors($project, ['mode' => 'dub', 'output' => 'mp3']));
        $this->assertNull($ledger->quote($project));
        $ledger->checkUpload($user->id, 200000000, 'youtube');
        $ledger->checkStorage($project, 2000000000);
        $record = $ledger->reserve($run);
        $this->assertSame(7200, $record->reserved_seconds);
        $this->assertTrue($ledger->claim($run));
        $ledger->settle($run);
        $ledger->settle($run);
        $this->assertSame(7200, $record->fresh()->charged_seconds);
        $this->assertSame(0, $record->fresh()->reserved_seconds);
    }

    public function test_testing_mode_does_not_bypass_physical_storage_budget(): void
    {
        $this->app->instance('env', 'local');
        config(['media.storage_limit_bytes' => 1]);
        $this->expectException(MediaFailure::class);
        app(MediaStorage::class)->reserve(2);
    }

    public function test_normal_package_checks_return_when_the_temporary_mode_is_disabled(): void
    {
        $this->app->instance('env', 'local');
        config(['media.studio_testing' => false]);
        $user = $this->restrictedCustomer();
        $project = MediaProject::factory()->create(['user_id' => $user->id, 'metadata' => ['duration' => 7200, 'audio' => true]]);
        $this->assertNotEmpty(app(UsageLedger::class)->featureErrors($project, ['mode' => 'dub', 'output' => 'mp3']));
        $this->actingAs($user)->get('/projects/new')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('testingMode', false)->where('features.dub', false)->where('limits.duration', 300));
        $this->expectException(ValidationException::class);
        app(UsageLedger::class)->reserve(MediaRun::factory()->create(['media_project_id' => $project->id]));
    }

    public function test_local_bypass_does_not_allow_suspended_accounts_to_process(): void
    {
        $this->app->instance('env', 'local');
        $user = $this->restrictedCustomer();
        $user->forceFill(['account_status' => 'suspended'])->save();
        $project = MediaProject::factory()->create(['user_id' => $user->id, 'metadata' => ['duration' => 60, 'audio' => true]]);
        $this->expectException(ValidationException::class);
        app(UsageLedger::class)->reserve(MediaRun::factory()->create(['media_project_id' => $project->id]));
    }

    public function test_expired_subscription_is_ignored_only_in_local_testing_mode(): void
    {
        $this->app->instance('env', 'local');
        $user = $this->restrictedCustomer();
        CustomerPackageAssignment::where('user_id', $user->id)->update(['ends_at' => now()->subHour()]);
        $this->actingAs($user)->withSession(['_token' => 'studio-test-csrf'])->postJson('/media/projects', ['_token' => 'studio-test-csrf'])->assertUnprocessable();
        config(['media.studio_testing' => false]);
        $this->postJson('/media/projects', ['_token' => 'studio-test-csrf'])->assertForbidden()->assertJsonPath('code', 'subscription_unavailable');
    }

    private function restrictedCustomer(): User
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['managed_account' => true]);
        $package = Package::factory()->create(['status' => 'active', 'published_version' => 1]);
        $definition = $package->definition;
        $definition['features'] = array_fill_keys(array_keys($definition['features']), false);
        $version = PackageVersion::create(['package_id' => $package->id, 'number' => 1, 'definition' => $definition, 'actor_id' => $admin->id]);
        CustomerPackageAssignment::create(['user_id' => $user->id, 'package_id' => $package->id, 'package_version_id' => $version->id, 'assigned_by' => $admin->id, 'source' => 'account_creation',
            'snapshot' => ['id' => $package->id, 'name' => $package->name, 'code' => $package->code, 'type' => 'paid', 'version' => 1, 'definition' => $definition],
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(29), 'allowance_starts_at' => now()->subDay()]);

        return $user;
    }
}
