<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;
use App\Services\Media\StorageSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PackageCatalogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::factory()->create(['is_admin' => true]);
        config(['media.max_upload_bytes' => 2147483648, 'media.max_duration_seconds' => 600, 'media.storage_limit_bytes' => 4294967296]);
    }

    private function payload(array $extra = []): array
    {
        return array_replace(['name' => 'Pro', 'code' => 'PKG-PRO', 'type' => 'paid', 'status' => 'active', 'currency' => 'USD', 'price' => '9.99', 'validity_days' => 30, 'included_minutes' => 60, 'video_limit' => 100, 'storage_gib' => 2, 'max_video_seconds' => 300, 'max_upload_mib' => 500, 'concurrency' => 1, 'queue_limit' => 3, 'retention_days' => 7, 'description' => 'A package for creators', 'note' => 'Internal note', 'features' => ['local' => true, 'youtube' => true, 'subs' => true, 'dub' => true, 'mp4' => true, 'srt' => true, 'audio' => true, 'script_edit' => true, 'voice_preview' => true, 'video_preview' => true, 'subtitle_style' => true, 'cover_subtitles' => true, 'layout' => true, 'keep_background' => false], 'request_id' => (string) Str::uuid()], $extra);
    }

    private function createPackage(array $extra = []): Package
    {
        $response = $this->actingAs($this->admin, 'admin')->postJson('/admin/packages', $this->payload($extra))->assertCreated();

        return Package::findOrFail($response->json('package.id'));
    }

    private function action(Package $package, string $action, array $extra = []): TestResponse
    {
        return $this->actingAs($this->admin, 'admin')->postJson('/admin/packages/'.$package->id.'/action', array_replace(['action' => $action, 'revision' => $package->fresh()->revision, 'reason' => 'Administrator requested this change', 'request_id' => (string) Str::uuid()], $extra));
    }

    public function test_catalog_requires_admin_guard_and_does_not_expose_it_to_customers(): void
    {
        $this->get('/admin/packages')->assertRedirect('/admin/login');
        $this->getJson('/admin/packages')->assertUnauthorized();
        $customer = User::factory()->create();
        $this->actingAs($customer, 'web')->postJson('/admin/packages', $this->payload())->assertUnauthorized();
        $this->actingAs($customer, 'admin')->getJson('/admin/packages')->assertForbidden();
        $this->actingAs($this->admin, 'admin')->get('/admin/packages')->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/packages')->where('adminLinks.packages', '/admin/packages')->where('catalog.summary.total', 0)->where('catalog.summary.assigned', 0)->where('catalog.capabilities.assignment_ready', true)->has('catalog.packages', 0));
    }

    public function test_active_package_is_exactly_priced_versioned_and_does_not_change_account_access(): void
    {
        $customer = User::factory()->create(['account_status' => 'active'])->refresh();
        $userBefore = $customer->getAttributes();
        $package = $this->createPackage(['price' => '0.10', 'name' => ' Pro ', 'code' => ' pkg-pro ']);
        $this->assertSame('PKG-PRO', $package->code);
        $this->assertSame('Pro', $package->name);
        $this->assertSame(10, $package->definition['price_minor']);
        $this->assertSame(3600, $package->definition['included_seconds']);
        $this->assertSame(1, $package->published_version);
        $this->assertDatabaseCount('package_versions', 1);
        $this->assertDatabaseCount('package_events', 1);
        $this->assertSame($userBefore, $customer->fresh()->getAttributes());
        $this->actingAs($customer, 'web')->get('/projects/new')->assertOk();
    }

    public function test_incomplete_draft_can_be_saved_but_not_published(): void
    {
        $response = $this->actingAs($this->admin, 'admin')->postJson('/admin/packages', ['name' => 'Future Plan', 'code' => 'PKG-FUTURE', 'type' => 'paid', 'status' => 'draft', 'request_id' => (string) Str::uuid()])->assertCreated()->assertJsonPath('package.status', 'draft');
        $package = Package::findOrFail($response->json('package.id'));
        $this->assertSame(0, $package->published_version);
        $this->assertDatabaseCount('package_versions', 0);
        $this->action($package, 'publish')->assertUnprocessable()->assertJsonValidationErrors(['currency', 'included_minutes', 'features.local']);
        $this->assertSame('draft', $package->fresh()->status);
        $this->assertDatabaseCount('package_versions', 0);
    }

    public function test_free_trial_has_zero_price_and_paid_packages_require_positive_price(): void
    {
        $this->actingAs($this->admin, 'admin')->postJson('/admin/packages', $this->payload(['type' => 'trial', 'price' => '9.99']))->assertUnprocessable()->assertJsonValidationErrors('price');
        $this->postJson('/admin/packages', $this->payload(['price' => '0']))->assertUnprocessable()->assertJsonValidationErrors('price');
        $package = $this->createPackage(['name' => 'Trial', 'code' => 'PKG-TRIAL', 'type' => 'trial', 'price' => '0']);
        $this->assertSame(0, $package->definition['price_minor']);
        $this->assertSame('trial', $package->type);
    }

    public function test_publish_rejects_overpromised_global_limits_and_incompatible_features(): void
    {
        $this->actingAs($this->admin, 'admin')->postJson('/admin/packages', $this->payload(['storage_gib' => 50, 'max_video_seconds' => 1200, 'max_upload_mib' => 4096]))->assertUnprocessable()->assertJsonValidationErrors('max_upload_mib');
        $this->postJson('/admin/packages', $this->payload(['storage_gib' => 50, 'max_video_seconds' => 1200]))->assertUnprocessable()->assertJsonValidationErrors('max_video_seconds')->assertJsonMissingValidationErrors('storage_gib');
        $this->postJson('/admin/packages', $this->payload(['features' => ['local' => true, 'subs' => true, 'dub' => true, 'srt' => true, 'mp4' => false, 'audio' => false]]))->assertUnprocessable()->assertJsonValidationErrors('features.mp4');
        $this->postJson('/admin/packages', $this->payload(['included_minutes' => 1]))->assertUnprocessable()->assertJsonValidationErrors('included_minutes');
        $this->postJson('/admin/packages', $this->payload(['features' => ['local' => false, 'youtube' => false, 'subs' => true, 'mp4' => true]]))->assertUnprocessable()->assertJsonValidationErrors('features.local');
        $this->assertDatabaseCount('packages', 0);
    }

    public function test_package_storage_quota_is_independent_of_the_system_budget(): void
    {
        $package = $this->createPackage(['storage_gib' => 5]);
        $this->assertSame(5368709120, $package->definition['storage_bytes']);

        $this->patchJson('/admin/packages/'.$package->id, $this->payload([
            'storage_gib' => 50,
            'revision' => $package->revision,
            'confirm_version' => true,
            'reason' => 'Increase the Pro customer quota',
        ]))->assertOk()->assertJsonPath('package.definition.storage_bytes', 53687091200)
            ->assertJsonPath('package.published_version', 2);

        $this->assertSame(5368709120, PackageVersion::where('package_id', $package->id)->where('number', 1)->firstOrFail()->definition['storage_bytes']);
        $this->action($package, 'deactivate')->assertOk();
        $this->action($package, 'activate')->assertOk()->assertJsonPath('packages.0.status', 'active');
        $this->assertSame(4294967296, app(StorageSettings::class)->limits()['budget']);
    }

    public function test_draft_storage_above_system_budget_can_publish_but_must_fit_an_upload(): void
    {
        $package = $this->createPackage(['status' => 'draft', 'storage_gib' => 100]);
        $this->action($package, 'publish')->assertOk()
            ->assertJsonPath('packages.0.definition.storage_bytes', 107374182400)
            ->assertJsonPath('packages.0.published_version', 1);

        $this->postJson('/admin/packages', $this->payload([
            'name' => 'Too Small',
            'code' => 'PKG-SMALL',
            'storage_gib' => 0.1,
            'max_upload_mib' => 500,
        ]))->assertUnprocessable()->assertJsonValidationErrors('storage_gib');
        $this->postJson('/admin/packages', $this->payload([
            'name' => 'Invalid Storage',
            'code' => 'PKG-INVALID',
            'storage_gib' => -1,
        ]))->assertUnprocessable()->assertJsonValidationErrors('storage_gib');
    }

    public function test_invalid_numeric_values_unknown_features_and_metadata_are_rejected(): void
    {
        $this->actingAs($this->admin, 'admin')->postJson('/admin/packages', $this->payload(['price' => '1e2', 'concurrency' => 0, 'validity_days' => -1, 'published_version' => 9]))->assertUnprocessable()->assertJsonValidationErrors(['price', 'concurrency', 'validity_days', 'published_version']);
        $this->postJson('/admin/packages', $this->payload(['features' => ['unimplemented_custom_voice' => true]]))->assertUnprocessable()->assertJsonValidationErrors('features');
        $this->postJson('/admin/packages', $this->payload(['request_id' => ['bad']]))->assertUnprocessable()->assertJsonValidationErrors('request_id');
    }

    public function test_repeated_creates_are_idempotent_and_mismatched_reuse_conflicts(): void
    {
        $data = $this->payload();
        $this->actingAs($this->admin, 'admin')->postJson('/admin/packages', $data)->assertCreated();
        $this->postJson('/admin/packages', $data)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson('/admin/packages', array_replace($data, ['description' => 'Changed']))->assertConflict();
        $this->assertDatabaseCount('packages', 1);
        $this->assertDatabaseCount('package_versions', 1);
        $this->assertDatabaseCount('package_operations', 1);
    }

    public function test_different_admin_cannot_reuse_another_admin_operation_identity(): void
    {
        $data = $this->payload();
        $this->actingAs($this->admin, 'admin')->postJson('/admin/packages', $data)->assertCreated();
        $other = User::factory()->create(['is_admin' => true]);
        $this->actingAs($other, 'admin')->postJson('/admin/packages', $this->payload(['name' => 'Another', 'code' => 'PKG-OTHER', 'request_id' => $data['request_id']]))->assertConflict();
        $this->assertDatabaseCount('packages', 1);
    }

    public function test_published_edits_require_confirmation_and_preserve_old_versions(): void
    {
        $package = $this->createPackage();
        $old = PackageVersion::first()->definition;
        $payload = $this->payload(['revision' => 1, 'included_minutes' => 120]);
        $this->patchJson('/admin/packages/'.$package->id, $payload)->assertUnprocessable()->assertJsonValidationErrors('confirm_version');
        $payload['confirm_version'] = true;
        $this->patchJson('/admin/packages/'.$package->id, $payload)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $payload['reason'] = 'Increase allowance for new assignments';
        $this->patchJson('/admin/packages/'.$package->id, $payload)->assertOk()->assertJsonPath('package.published_version', 2);
        $this->assertSame($old, PackageVersion::where('number', 1)->first()->definition);
        $this->assertSame(120, PackageVersion::where('number', 2)->first()->definition['included_minutes']);
        $this->patchJson('/admin/packages/'.$package->id, $payload)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('package_versions', 2);
    }

    public function test_unchanged_published_save_does_not_create_an_extra_version(): void
    {
        $package = $this->createPackage();
        $this->patchJson('/admin/packages/'.$package->id, $this->payload(['revision' => 1]))->assertOk()->assertJsonPath('unchanged', true)->assertJsonPath('package.published_version', 1);
        $this->assertDatabaseCount('package_versions', 1);
        $this->assertSame(1, $package->fresh()->revision);
    }

    public function test_stale_edits_and_published_code_changes_cannot_overwrite_history(): void
    {
        $package = $this->createPackage();
        $this->action($package, 'deactivate')->assertOk();
        $this->patchJson('/admin/packages/'.$package->id, $this->payload(['status' => 'inactive', 'revision' => 1, 'confirm_version' => true, 'reason' => 'stale']))->assertConflict();
        $this->patchJson('/admin/packages/'.$package->id, $this->payload(['status' => 'inactive', 'revision' => 2, 'code' => 'CHANGED', 'confirm_version' => true, 'reason' => 'change']))->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertSame('PKG-PRO', $package->fresh()->code);
        $this->assertDatabaseCount('package_versions', 1);
    }

    public function test_lifecycle_preserves_history_and_restore_requires_explicit_activation(): void
    {
        $package = $this->createPackage(['status' => 'draft']);
        $this->action($package, 'publish')->assertOk();
        $this->assertSame(1, $package->fresh()->published_version);
        $this->action($package, 'deactivate')->assertOk();
        $this->action($package, 'archive')->assertOk();
        $this->action($package, 'restore')->assertOk()->assertJsonPath('packages.0.status', 'inactive');
        $this->action($package, 'activate')->assertOk();
        $this->assertDatabaseCount('package_versions', 1);
        $this->assertDatabaseHas('packages', ['id' => $package->id]);
        $this->deleteJson('/admin/packages/'.$package->id)->assertMethodNotAllowed();
    }

    public function test_archived_packages_are_read_only_and_remain_unique(): void
    {
        $package = $this->createPackage();
        $this->action($package, 'archive')->assertOk();
        $this->patchJson('/admin/packages/'.$package->id, $this->payload(['revision' => $package->fresh()->revision]))->assertConflict();
        $this->postJson('/admin/packages', $this->payload())->assertUnprocessable()->assertJsonValidationErrors(['name', 'code']);
        $this->getJson('/admin/packages')->assertJsonCount(0, 'packages');
        $this->getJson('/admin/packages?status=archived')->assertJsonCount(1, 'packages');
    }

    public function test_draft_copy_has_new_identity_without_copying_versions_or_assignments(): void
    {
        $source = $this->createPackage();
        $copy = $this->createPackage(['name' => 'Pro Copy', 'code' => 'PKG-PRO-COPY', 'status' => 'draft', 'source_id' => $source->id]);
        $this->assertSame(0, $copy->published_version);
        $this->assertSame(1, $source->fresh()->published_version);
        $this->assertDatabaseCount('package_versions', 1);
        $this->assertDatabaseHas('package_events', ['package_id' => $copy->id, 'type' => 'created']);
    }

    public function test_bulk_actions_are_atomic_on_conflicts(): void
    {
        $first = $this->createPackage();
        $second = $this->createPackage(['name' => 'Second', 'code' => 'PKG-SECOND', 'status' => 'draft']);
        $this->postJson('/admin/packages/bulk', ['action' => 'deactivate', 'request_id' => (string) Str::uuid(), 'reason' => 'Bulk test', 'items' => [['id' => $first->id, 'revision' => 1], ['id' => $second->id, 'revision' => 1]]])->assertConflict();
        $this->assertSame('active', $first->fresh()->status);
        $this->assertSame('draft', $second->fresh()->status);
        $this->assertDatabaseCount('package_events', 2);
    }

    public function test_bulk_archive_is_idempotent_and_cannot_repeat_side_effects(): void
    {
        $first = $this->createPackage();
        $second = $this->createPackage(['name' => 'Second', 'code' => 'PKG-SECOND']);
        $payload = ['action' => 'archive', 'request_id' => (string) Str::uuid(), 'reason' => 'Retire packages', 'items' => [['id' => $first->id, 'revision' => 1], ['id' => $second->id, 'revision' => 1]]];
        $this->postJson('/admin/packages/bulk', $payload)->assertOk()->assertJsonCount(2, 'packages');
        $this->postJson('/admin/packages/bulk', $payload)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('package_events', 4);
        $this->assertSame(2, $first->fresh()->revision);
    }

    public function test_filters_pagination_details_and_counts_are_real(): void
    {
        Package::factory()->count(12)->create();
        $package = $this->createPackage();
        $this->getJson('/admin/packages')->assertOk()->assertJsonPath('summary.total', 13)->assertJsonPath('summary.active', 1)->assertJsonPath('summary.draft', 12)->assertJsonPath('summary.assigned', 0)->assertJsonPath('pagination.pages', 2);
        $this->getJson('/admin/packages?q=PKG-PRO')->assertJsonCount(1, 'packages')->assertJsonPath('packages.0.id', $package->id);
        $this->getJson('/admin/packages?status=active')->assertJsonCount(1, 'packages');
        $this->getJson('/admin/packages?page=2')->assertJsonCount(3, 'packages');
        $this->getJson('/admin/packages/'.$package->id)->assertOk()->assertJsonCount(1, 'versions')->assertJsonPath('versions.0.actor', $this->admin->name)->assertJsonPath('assignment_ready', true)->assertJsonMissingPath('versions.0.password');
        $this->getJson('/admin/packages?sort=not-supported')->assertUnprocessable();
    }

    public function test_missing_records_wrong_status_and_csrf_are_enforced(): void
    {
        $this->actingAs($this->admin, 'admin')->getJson('/admin/packages/9999')->assertNotFound();
        $package = $this->createPackage();
        $this->action($package, 'publish')->assertConflict();
        $this->app['env'] = 'local';
        $this->postJson('/admin/packages', $this->payload(['name' => 'Second', 'code' => 'PKG-SECOND']))->assertStatus(419);
    }

    public function test_unpublished_drafts_cannot_enter_inactive_without_a_published_version(): void
    {
        $package = $this->createPackage(['status' => 'draft']);
        $this->patchJson('/admin/packages/'.$package->id, $this->payload(['status' => 'inactive', 'revision' => 1]))->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('draft', $package->fresh()->status);
        $package->update(['status' => 'inactive']);
        $this->action($package, 'activate')->assertOk()->assertJsonPath('packages.0.published_version', 1);
        $this->assertDatabaseCount('package_versions', 1);
    }

    public function test_zero_search_is_not_treated_as_an_empty_filter(): void
    {
        $this->createPackage(['name' => 'Zero 0', 'code' => 'PKG-ZERO']);
        $this->createPackage(['name' => 'Another', 'code' => 'PKG-ANOTHER']);
        $this->getJson('/admin/packages?q=0')->assertOk()->assertJsonCount(1, 'packages')->assertJsonPath('filters.q', '0');
    }

    public function test_upload_sizes_come_from_validated_presets_and_current_system_ceiling(): void
    {
        $this->actingAs($this->admin, 'admin')->getJson('/admin/packages')->assertOk()->assertJsonFragment(['upload_size_options_mib' => [1, 10, 25, 50, 100, 250, 500, 1024, 2048]]);
        $this->postJson('/admin/packages', $this->payload(['max_upload_mib' => 123]))->assertUnprocessable()->assertJsonValidationErrors('max_upload_mib');
        config(['media.max_upload_bytes' => 37 * 1048576]);
        $this->getJson('/admin/packages')->assertJsonFragment(['upload_size_options_mib' => [1, 10, 25, 37]]);
        $this->postJson('/admin/packages', $this->payload(['max_upload_mib' => 50]))->assertUnprocessable()->assertJsonValidationErrors('max_upload_mib');
        $this->postJson('/admin/packages', $this->payload(['max_upload_mib' => 37]))->assertCreated()->assertJsonPath('package.definition.max_upload_bytes', 37 * 1048576);
    }

    public function test_existing_legacy_upload_size_can_be_kept_but_not_used_for_a_new_package(): void
    {
        $package = $this->createPackage(['status' => 'draft']);
        $definition = $package->definition;
        $definition['max_upload_mib'] = 17;
        $definition['max_upload_bytes'] = 17 * 1048576;
        $package->update(['definition' => $definition]);
        $this->patchJson('/admin/packages/'.$package->id, $this->payload(['status' => 'draft', 'revision' => 1, 'max_upload_mib' => 17]))->assertOk()->assertJsonPath('package.definition.max_upload_mib', 17);
        $this->postJson('/admin/packages', $this->payload(['name' => 'New Plan', 'code' => 'PKG-NEW', 'max_upload_mib' => 17]))->assertUnprocessable()->assertJsonValidationErrors('max_upload_mib');
    }
}
