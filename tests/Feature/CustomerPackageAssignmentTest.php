<?php

namespace Tests\Feature;

use App\Models\CustomerPackageAssignment;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;
use App\Services\CustomerAccounts;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CustomerPackageAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($this->admin, 'admin');
    }

    private function publishedPackage(array $attributes = []): Package
    {
        $package = Package::factory()->trial()->create(array_replace(['status' => 'active', 'published_version' => 1], $attributes));
        PackageVersion::create(['package_id' => $package->id, 'number' => 1, 'definition' => $package->definition, 'actor_id' => $this->admin->id]);

        return $package;
    }

    private function payload(Package $package, array $extra = []): array
    {
        return array_replace(['validity_mode' => 'package', 'starts_on' => now()->toDateString(), 'ends_on' => now()->addDays(30)->toDateString(), 'name' => 'Customer', 'email' => 'customer@example.com', 'password' => 'PrivatePass!2026', 'password_confirmation' => 'PrivatePass!2026', 'preferred_locale' => 'en', 'note' => '', 'status' => 'active', 'send_login' => false, 'request_id' => (string) Str::uuid(), 'package_id' => $package->id, 'package_version' => $package->published_version], $extra);
    }

    private function editPayload(User $user, Package $package, array $extra = []): array
    {
        return array_replace([
            'name' => $user->name, 'email' => $user->email, 'preferred_locale' => $user->preferred_locale ?? 'en', 'note' => $user->account_note ?? '',
            'revision' => $user->fresh()->account_revision, 'request_id' => (string) Str::uuid(), 'package_id' => $package->id, 'package_version' => $package->published_version,
            'validity_mode' => 'package', 'starts_on' => now()->toDateString(), 'ends_on' => now()->addDays(30)->toDateString(),
        ], $extra);
    }

    public function test_create_requires_an_existing_published_active_package_and_version(): void
    {
        $package = $this->publishedPackage();
        $data = $this->payload($package);
        unset($data['package_id']);
        $this->postJson('/admin/users', $data)->assertUnprocessable()->assertJsonValidationErrors('package_id');
        $this->postJson('/admin/users', $this->payload($package, ['package_id' => 999999]))->assertUnprocessable()->assertJsonValidationErrors('package_id');
        $this->postJson('/admin/users', $this->payload($package, ['package_version' => null]))->assertUnprocessable()->assertJsonValidationErrors('package_version');
        foreach (['draft', 'inactive', 'archived'] as $status) {
            $package->update(['status' => $status]);
            $this->postJson('/admin/users', $this->payload($package))->assertUnprocessable()->assertJsonValidationErrors('package_id');
        }
        $package->update(['status' => 'active', 'published_version' => 0]);
        $this->postJson('/admin/users', $this->payload($package, ['package_version' => 1]))->assertUnprocessable()->assertJsonValidationErrors('package_id');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('customer_package_assignments', 0);
        $this->assertDatabaseCount('customer_account_events', 0);
    }

    public function test_assignment_captures_version_dates_and_actor_and_is_idempotent(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 11)->setTime(10, 0));
        $package = $this->publishedPackage();
        $data = $this->payload($package);
        $response = $this->postJson('/admin/users', $data)->assertCreated()
            ->assertJsonPath('customer.package.package_id', $package->id)
            ->assertJsonPath('customer.package.version', 1)
            ->assertJsonPath('customer.package.validity_days', 30)
            ->assertJsonPath('customer.package.expired', false)
            ->assertJsonMissingPath('customer.package.note');
        $assignment = CustomerPackageAssignment::sole();
        $this->assertSame($this->admin->id, $assignment->assigned_by);
        $this->assertSame($package->definition, $assignment->snapshot['definition']);
        $this->assertTrue($assignment->starts_at->equalTo(now()->startOfDay()));
        $this->assertTrue($assignment->ends_at->equalTo(now()->startOfDay()->addDays(30)));
        $this->assertDatabaseHas('customer_account_events', ['user_id' => $response->json('customer.id'), 'type' => 'created']);

        $package->update(['status' => 'inactive']);
        $this->postJson('/admin/users', $data)->assertOk()->assertJsonPath('replayed', true);
        $other = $this->publishedPackage();
        $this->postJson('/admin/users', array_replace($data, ['package_id' => $other->id]))->assertConflict();
        $this->assertDatabaseCount('customer_package_assignments', 1);
    }

    public function test_changed_package_is_rejected_before_creating_a_customer(): void
    {
        $package = $this->publishedPackage();
        $data = $this->payload($package);
        $package->update(['published_version' => 2]);
        PackageVersion::create(['package_id' => $package->id, 'number' => 2, 'definition' => $package->definition, 'actor_id' => $this->admin->id]);
        $this->postJson('/admin/users', $data)->assertConflict();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('customer_package_assignments', 0);
    }

    public function test_assignment_rechecks_availability_inside_the_transaction(): void
    {
        $package = $this->publishedPackage();
        $data = $this->payload($package);
        $package->update(['status' => 'inactive']);
        try {
            app(CustomerAccounts::class)->create($this->admin, $data);
            $this->fail('An unavailable package must not be assigned.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('package_id', $exception->errors());
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('customer_package_assignments', 0);
    }

    public function test_latest_published_access_applies_even_if_catalog_is_archived_without_rewriting_purchase_history(): void
    {
        $package = $this->publishedPackage();
        $id = $this->postJson('/admin/users', $this->payload($package))->assertCreated()->json('customer.id');
        $original = CustomerPackageAssignment::sole()->getAttributes();
        $package->update(['name' => 'Renamed Package', 'status' => 'archived', 'published_version' => 2, 'definition' => array_replace($package->definition, ['validity_days' => 90, 'storage_gib' => 50])]);
        PackageVersion::create(['package_id' => $package->id, 'number' => 2, 'definition' => $package->definition, 'actor_id' => $this->admin->id]);
        $this->getJson('/admin/users/'.$id)->assertOk()
            ->assertJsonPath('customer.package.version', 2)
            ->assertJsonPath('customer.package.validity_days', 90)
            ->assertJsonPath('customer.package.storage_gib', 50);
        $this->patchJson('/admin/users/'.$id, ['name' => 'New Customer Name', 'email' => 'customer@example.com', 'preferred_locale' => 'my', 'note' => '', 'revision' => 1, 'request_id' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('customer.package.version', 2);
        $this->assertSame($original, CustomerPackageAssignment::sole()->getAttributes());
        $this->patchJson('/admin/users/'.$id, ['name' => 'New Customer Name', 'email' => 'customer@example.com', 'preferred_locale' => 'my', 'note' => '', 'revision' => 2, 'request_id' => (string) Str::uuid(), 'package_id' => $package->id, 'package_version' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors(['validity_mode', 'starts_on', 'ends_on']);
    }

    public function test_accounts_list_filters_and_catalog_counts_use_real_assignments(): void
    {
        $first = $this->publishedPackage();
        $second = $this->publishedPackage();
        $legacy = User::factory()->create(['is_admin' => false]);
        $firstId = $this->postJson('/admin/users', $this->payload($first))->assertCreated()->json('customer.id');
        $this->postJson('/admin/users', $this->payload($second, ['email' => 'second@example.com']))->assertCreated();
        CustomerPackageAssignment::where('user_id', $firstId)->update(['ends_at' => now()->subSecond()]);
        $this->getJson('/admin/users?package_id='.$first->id)->assertOk()->assertJsonCount(1, 'users')
            ->assertJsonPath('users.0.id', $firstId)->assertJsonPath('users.0.package.expired', true)
            ->assertJsonPath('summary.expired', 1)->assertJsonPath('packageReady', true);
        $this->getJson('/admin/users?status=expired')->assertOk()->assertJsonCount(1, 'users')->assertJsonPath('users.0.id', $firstId);
        $this->getJson('/admin/packages')->assertOk()->assertJsonPath('summary.assigned', 2);
        $this->getJson('/admin/packages/'.$first->id)->assertOk()->assertJsonPath('package.assigned_users', 1);
        $this->getJson('/admin/users/'.$legacy->id)->assertOk()->assertJsonPath('customer.package', null);
        $this->assertSame('active', $legacy->fresh()->account_status);
    }

    public function test_package_refresh_is_guarded_returns_current_allowances_and_is_not_cached(): void
    {
        $package = $this->publishedPackage();
        $this->getJson('/admin/users/package-options')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('packages.0.id', $package->id)
            ->assertJsonPath('packages.0.storage_gib', 1)
            ->assertJsonPath('packages.0.included_minutes', 60)
            ->assertJsonPath('packages.0.video_limit', 10)
            ->assertJsonPath('packages.0.concurrency', 1)
            ->assertJsonMissingPath('packages.0.note')
            ->assertJsonStructure(['today', 'refreshed_at']);
        $package->update(['status' => 'inactive']);
        $this->getJson('/admin/users/package-options')->assertOk()->assertJsonCount(0, 'packages');
        auth('admin')->logout();
        $this->getJson('/admin/users/package-options')->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'web')->getJson('/admin/users/package-options')->assertUnauthorized();
    }

    public function test_custom_dates_are_saved_without_changing_package_allowances(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 11)->setTime(10, 0));
        $package = $this->publishedPackage();
        $data = $this->payload($package, ['validity_mode' => 'custom', 'starts_on' => '2026-11-15', 'ends_on' => '2026-11-25']);
        $response = $this->postJson('/admin/users', $data)->assertCreated()
            ->assertJsonPath('customer.package.validity_mode', 'custom')
            ->assertJsonPath('customer.package.period_days', 10)
            ->assertJsonPath('customer.package.validity_days', 30)
            ->assertJsonPath('customer.package.included_minutes', 60);
        $assignment = CustomerPackageAssignment::sole();
        $this->assertSame('2026-11-15', $assignment->starts_at->toDateString());
        $this->assertSame('2026-11-25', $assignment->ends_at->toDateString());
        $this->assertSame($package->definition, $assignment->snapshot['definition']);
        $this->postJson('/admin/users', $data)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson('/admin/users', array_replace($data, ['ends_on' => '2026-11-26']))->assertConflict();
        $this->assertDatabaseCount('customer_package_assignments', 1);
        $this->getJson('/admin/users/'.$response->json('customer.id'))->assertOk()->assertJsonPath('customer.package.period_days', 10);
    }

    public function test_calendar_dates_validate_missing_invalid_reversed_and_excessive_periods(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 11)->setTime(10, 0));
        $package = $this->publishedPackage();
        foreach ([
            ['extra' => ['starts_on' => null], 'field' => 'starts_on'],
            ['extra' => ['ends_on' => null], 'field' => 'ends_on'],
            ['extra' => ['validity_mode' => 'other'], 'field' => 'validity_mode'],
            ['extra' => ['starts_on' => '2026-02-30'], 'field' => 'starts_on'],
            ['extra' => ['starts_on' => '2026-11-10'], 'field' => 'starts_on'],
            ['extra' => ['ends_on' => '2026-11-11'], 'field' => 'ends_on'],
            ['extra' => ['ends_on' => '2026-11-10'], 'field' => 'ends_on'],
            ['extra' => ['ends_on' => '2026-12-12'], 'field' => 'ends_on'],
            ['extra' => ['validity_mode' => 'custom', 'ends_on' => now()->addDays(3651)->toDateString()], 'field' => 'ends_on'],
            ['extra' => ['starts_on' => now()->addDays(3651)->toDateString(), 'ends_on' => now()->addDays(3681)->toDateString()], 'field' => 'starts_on'],
        ] as $case) {
            $this->postJson('/admin/users', $this->payload($package, $case['extra']))->assertUnprocessable()->assertJsonValidationErrors($case['field']);
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('customer_package_assignments', 0);
    }

    public function test_package_dates_use_the_configured_timezone_and_handle_leap_days(): void
    {
        config(['app.timezone' => 'Asia/Bangkok']);
        $this->travelTo(CarbonImmutable::parse('2028-02-27T20:00:00Z'));
        $package = $this->publishedPackage();
        $this->getJson('/admin/users/package-options')->assertOk()->assertJsonPath('today', '2028-02-28');
        $this->postJson('/admin/users', $this->payload($package, ['starts_on' => '2028-02-28', 'ends_on' => '2028-03-29']))->assertCreated()
            ->assertJsonPath('customer.package.period_days', 30);
        $assignment = CustomerPackageAssignment::sole();
        $this->assertSame('2028-02-28 00:00', $assignment->starts_at->format('Y-m-d H:i'));
        $this->assertSame('2028-03-29 00:00', $assignment->ends_at->format('Y-m-d H:i'));
        $this->assertSame(30, (int) $assignment->starts_at->diffInDays($assignment->ends_at));
    }

    public function test_partial_date_corrections_require_package_identity_and_version(): void
    {
        $package = $this->publishedPackage();
        $id = $this->postJson('/admin/users', $this->payload($package))->assertCreated()->json('customer.id');
        $before = CustomerPackageAssignment::sole()->getAttributes();
        $this->patchJson('/admin/users/'.$id, [
            'name' => 'Customer', 'email' => 'customer@example.com', 'preferred_locale' => 'en', 'note' => '', 'revision' => 1, 'request_id' => (string) Str::uuid(),
            'validity_mode' => 'custom', 'starts_on' => now()->toDateString(), 'ends_on' => now()->addDays(90)->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['package_id', 'package_version']);
        $this->assertSame($before, CustomerPackageAssignment::sole()->getAttributes());
    }

    public function test_admin_can_correct_package_and_dates_with_history_and_idempotent_retries(): void
    {
        $first = $this->publishedPackage();
        $second = $this->publishedPackage();
        $id = $this->postJson('/admin/users', $this->payload($first))->assertCreated()->json('customer.id');
        $user = User::findOrFail($id);
        $original = CustomerPackageAssignment::sole();
        $oldAttributes = $original->getAttributes();
        $data = $this->editPayload($user, $second, ['validity_mode' => 'custom', 'ends_on' => now()->addDays(45)->toDateString()]);
        $this->patchJson('/admin/users/'.$id, $data)->assertOk()
            ->assertJsonPath('customer.package.package_id', $second->id)
            ->assertJsonPath('customer.package.period_days', 45)
            ->assertJsonPath('customer.package.validity_mode', 'custom');
        $this->assertDatabaseCount('customer_package_assignments', 2);
        $this->assertSame($oldAttributes, $original->fresh()->getAttributes());
        $this->patchJson('/admin/users/'.$id, $data)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('customer_package_assignments', 2);
        $this->patchJson('/admin/users/'.$id, array_replace($data, ['request_id' => (string) Str::uuid()]))->assertConflict();
        $this->getJson('/admin/packages/'.$first->id)->assertOk()->assertJsonPath('package.assigned_users', 0);
        $this->getJson('/admin/packages/'.$second->id)->assertOk()->assertJsonPath('package.assigned_users', 1);
        $this->getJson('/admin/packages')->assertOk()->assertJsonPath('summary.assigned', 1);
        $this->getJson('/admin/users/'.$id)->assertOk()
            ->assertJsonPath('activity.0.metadata.package_change.before.assignment_id', $original->id)
            ->assertJsonPath('activity.0.metadata.package_change.after.package_id', $second->id);
        $this->getJson('/admin/users?package_id='.$first->id)->assertOk()->assertJsonCount(0, 'users');
        $this->getJson('/admin/users?package_id='.$second->id)->assertOk()->assertJsonCount(1, 'users');
    }

    public function test_date_correction_keeps_latest_live_access_of_an_inactive_package(): void
    {
        $package = $this->publishedPackage();
        $id = $this->postJson('/admin/users', $this->payload($package))->assertCreated()->json('customer.id');
        $user = User::findOrFail($id);
        $old = CustomerPackageAssignment::sole();
        $package->update(['status' => 'archived', 'published_version' => 2, 'definition' => array_replace($package->definition, ['storage_gib' => 50])]);
        PackageVersion::create(['package_id' => $package->id, 'number' => 2, 'definition' => $package->definition, 'actor_id' => $this->admin->id]);
        $effectiveBefore = $old->effectivePackage();
        $data = $this->editPayload($user, $package, ['package_version' => 2, 'validity_mode' => 'custom', 'ends_on' => now()->addDays(14)->toDateString()]);
        $this->patchJson('/admin/users/'.$id, $data)->assertOk()
            ->assertJsonPath('customer.package.version', 2)
            ->assertJsonPath('customer.package.storage_gib', 50)
            ->assertJsonPath('customer.package.period_days', 14);
        $latest = $user->fresh()->currentPackageAssignment;
        $this->assertSame($effectiveBefore['snapshot']['definition'], $latest->snapshot['definition']);
        $this->assertSame($effectiveBefore['version_id'], $latest->package_version_id);
        $this->assertDatabaseCount('customer_package_assignments', 2);
    }

    public function test_unchanged_assignment_edit_preserves_history_and_original_times(): void
    {
        $package = $this->publishedPackage();
        $id = $this->postJson('/admin/users', $this->payload($package))->assertCreated()->json('customer.id');
        $user = User::findOrFail($id);
        $data = $this->editPayload($user, $package, ['name' => 'Corrected Name']);
        $original = CustomerPackageAssignment::sole();
        $original->update(['starts_at' => $original->starts_at->setTime(10, 0), 'ends_at' => $original->ends_at->setTime(10, 0)]);
        $before = $original->fresh()->getAttributes();
        $this->patchJson('/admin/users/'.$id, $data)->assertOk()->assertJsonPath('customer.name', 'Corrected Name');
        $this->assertDatabaseCount('customer_package_assignments', 1);
        $this->assertSame($before, $original->fresh()->getAttributes());
    }

    public function test_invalid_package_date_edit_rolls_back_profile_changes_and_assignment(): void
    {
        $package = $this->publishedPackage();
        $other = $this->publishedPackage(['status' => 'inactive']);
        $id = $this->postJson('/admin/users', $this->payload($package))->assertCreated()->json('customer.id');
        $user = User::findOrFail($id);
        $this->patchJson('/admin/users/'.$id, $this->editPayload($user, $other, ['name' => 'Must Not Save']))
            ->assertUnprocessable()->assertJsonValidationErrors('package_id');
        $this->patchJson('/admin/users/'.$id, $this->editPayload($user, $package, ['name' => 'Must Not Save', 'ends_on' => now()->subDay()->toDateString()]))
            ->assertUnprocessable()->assertJsonValidationErrors('ends_on');
        $this->assertSame('Customer', $user->fresh()->name);
        $this->assertSame(1, $user->fresh()->account_revision);
        $this->assertDatabaseCount('customer_package_assignments', 1);
    }

    public function test_legacy_customer_can_receive_a_first_package_during_edit(): void
    {
        $package = $this->publishedPackage();
        $user = User::factory()->create(['is_admin' => false, 'account_status' => 'active']);
        $this->patchJson('/admin/users/'.$user->id, $this->editPayload($user, $package))
            ->assertOk()->assertJsonPath('customer.package.package_id', $package->id);
        $this->assertDatabaseCount('customer_package_assignments', 1);
        $this->assertSame('active', $user->fresh()->account_status);
    }

    public function test_status_and_remaining_days_follow_current_dates_with_suspension_precedence(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 11)->setTime(10, 0));
        $package = $this->publishedPackage();
        $firstId = $this->postJson('/admin/users', $this->payload($package))->assertCreated()
            ->assertJsonPath('customer.display_status', 'active')->assertJsonPath('customer.package.days_left', 30)->json('customer.id');
        $first = User::findOrFail($firstId);
        $this->patchJson('/admin/users/'.$firstId, $this->editPayload($first, $package, ['validity_mode' => 'custom', 'starts_on' => '2026-10-01', 'ends_on' => '2026-11-01']))
            ->assertOk()->assertJsonPath('customer.display_status', 'expired')->assertJsonPath('customer.package.days_left', 0);
        $scheduledId = $this->postJson('/admin/users', $this->payload($package, ['email' => 'future@example.com', 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-31']))->assertCreated()
            ->assertJsonPath('customer.display_status', 'scheduled')->json('customer.id');
        $this->getJson('/admin/users?status=expired')->assertOk()->assertJsonCount(1, 'users')->assertJsonPath('summary.active', 0)->assertJsonPath('summary.expired', 1);
        $this->getJson('/admin/users?status=scheduled')->assertOk()->assertJsonCount(1, 'users')->assertJsonPath('users.0.id', $scheduledId);
        $first->forceFill(['account_status' => 'suspended'])->save();
        $this->getJson('/admin/users/'.$firstId)->assertOk()->assertJsonPath('customer.display_status', 'suspended');
        $this->getJson('/admin/users?status=expired')->assertOk()->assertJsonCount(0, 'users')->assertJsonPath('summary.expired', 0);
    }

    public function test_package_choices_only_expose_active_published_options_without_internal_notes(): void
    {
        $active = $this->publishedPackage();
        $this->publishedPackage(['status' => 'inactive']);
        $this->publishedPackage(['status' => 'archived']);
        Package::factory()->create(['status' => 'draft']);
        $this->getJson('/admin/users')->assertOk()->assertJsonCount(1, 'packages')
            ->assertJsonPath('packages.0.id', $active->id)
            ->assertJsonPath('packages.0.version', 1)
            ->assertJsonMissingPath('packages.0.note')
            ->assertJsonMissingPath('packages.0.definition')
            ->assertJsonMissingPath('packages.0.price_minor');
    }

    public function test_new_accounts_reject_paid_packages_before_creating_any_customer_or_assignment(): void
    {
        $paid = Package::factory()->create(['status' => 'active', 'published_version' => 1]);
        PackageVersion::create(['package_id' => $paid->id, 'number' => 1, 'definition' => $paid->definition, 'actor_id' => $this->admin->id]);
        $this->postJson('/admin/users', $this->payload($paid))->assertUnprocessable()->assertJsonValidationErrors('package_id');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('customer_package_assignments', 0);
        $this->assertDatabaseCount('customer_account_events', 0);
    }

    public function test_zero_priced_paid_package_still_cannot_bypass_the_free_trial_creation_flow(): void
    {
        $paid = Package::factory()->create(['status' => 'active', 'published_version' => 1]);
        $definition = [...$paid->definition, 'type' => 'paid', 'price_minor' => 0];
        PackageVersion::create(['package_id' => $paid->id, 'number' => 1, 'definition' => $definition, 'actor_id' => $this->admin->id]);
        $this->postJson('/admin/users', $this->payload($paid))->assertUnprocessable()->assertJsonValidationErrors('package_id');
        $free = $this->publishedPackage();
        $this->postJson('/admin/users', $this->payload($free))->assertCreated()->assertJsonPath('customer.package.type', 'trial');
    }
}
