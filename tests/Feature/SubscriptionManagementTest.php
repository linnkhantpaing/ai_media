<?php

namespace Tests\Feature;

use App\Models\CustomerAccountEvent;
use App\Models\CustomerPackageAssignment;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SubscriptionManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private Package $package;

    private CustomerPackageAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00', 'UTC'));
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->customer = User::factory()->create(['is_admin' => false, 'managed_account' => true]);
        $this->package = Package::factory()->create(['status' => 'active', 'published_version' => 1]);
        $version = PackageVersion::create(['package_id' => $this->package->id, 'number' => 1, 'definition' => $this->package->definition, 'actor_id' => $this->admin->id]);
        $this->assignment = CustomerPackageAssignment::create([
            'user_id' => $this->customer->id, 'package_id' => $this->package->id, 'package_version_id' => $version->id, 'assigned_by' => $this->admin->id,
            'snapshot' => ['name' => $this->package->name, 'code' => $this->package->code, 'type' => 'paid', 'version' => 1, 'definition' => $version->definition],
            'starts_at' => '2026-11-12 00:00:00', 'ends_at' => '2026-12-12 00:00:00', 'source' => 'account_creation',
        ]);
        $this->actingAs($this->admin, 'admin');
    }

    private function payload(array $extra = []): array
    {
        return array_replace([
            'action' => 'renew', 'revision' => $this->customer->fresh()->account_revision, 'request_id' => (string) Str::uuid(), 'reason' => 'Customer requested renewal',
            'package_id' => $this->package->id, 'package_version' => 1, 'timing' => 'after', 'validity_mode' => 'package', 'starts_on' => '2026-12-12', 'ends_on' => '2027-01-11',
        ], $extra);
    }

    private function send(array $data, ?int $id = null): TestResponse
    {
        return $this->postJson('/admin/subscriptions/'.($id ?? $this->assignment->id).'/action', $data);
    }

    private function simple(string $action, array $extra = []): array
    {
        return ['action' => $action, 'revision' => $this->customer->fresh()->account_revision, 'request_id' => (string) Str::uuid(), 'reason' => 'Admin reviewed this change', ...$extra];
    }

    public function test_admin_page_reuses_customer_assignments_and_exposes_safe_routes(): void
    {
        $this->get('/admin/subscriptions')->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/subscriptions')->has('subscriptions.items', 1)->where('adminLinks.subscriptions', '/admin/subscriptions'));
        $this->getJson('/admin/subscriptions')->assertOk()->assertJsonPath('items.0.id', $this->assignment->id)->assertJsonPath('summary.active', 1)->assertJsonPath('summary.upcoming', 0)->assertJsonPath('items.0.usage.videos', 0);
        $this->getJson('/admin/subscriptions/'.$this->assignment->id)->assertOk()->assertJsonPath('subscription.package.version', 1)->assertJsonPath('storage_bytes', 0)->assertJsonMissingPath('subscription.package.definition.note');
    }

    public function test_web_customer_cannot_read_or_mutate_admin_subscriptions(): void
    {
        auth('admin')->logout();
        $this->actingAs($this->customer, 'web');
        $this->getJson('/admin/subscriptions')->assertUnauthorized();
        $this->getJson('/admin/subscriptions/'.$this->assignment->id)->assertUnauthorized();
        $this->send($this->payload())->assertUnauthorized();
        $this->assertDatabaseCount('customer_package_assignments', 1);
    }

    public function test_non_admin_guard_identity_is_rejected(): void
    {
        $this->actingAs($this->customer, 'admin')->getJson('/admin/subscriptions')->assertForbidden();
        $this->send($this->payload())->assertForbidden();
    }

    public function test_renewal_keeps_current_access_until_boundary_without_a_scheduler(): void
    {
        $original = $this->assignment->fresh()->getAttributes();
        $response = $this->send($this->payload())->assertOk();
        $nextId = $response->json('id');
        $this->assertSame($this->assignment->id, $this->customer->fresh()->currentPackageAssignment->id);
        $this->assertSame($original, $this->assignment->fresh()->getAttributes());
        $this->getJson('/admin/subscriptions')->assertJsonPath('summary.active', 1)->assertJsonPath('summary.upcoming', 1);
        $this->travelTo(CarbonImmutable::parse('2026-12-12 00:00:00', 'UTC'));
        $this->assertSame($nextId, $this->customer->fresh()->currentPackageAssignment->id);
        $this->assertSame($nextId, CustomerPackageAssignment::current()->sole()->id);
    }

    public function test_duplicate_request_is_replayed_and_changed_replay_is_rejected(): void
    {
        $payload = $this->payload();
        $this->send($payload)->assertOk()->assertJsonPath('replayed', false);
        $this->send($payload)->assertOk()->assertJsonPath('replayed', true);
        $this->send(array_replace($payload, ['reason' => 'Different']))->assertConflict();
        $this->assertDatabaseCount('customer_package_assignments', 2);
        $this->assertDatabaseCount('customer_account_events', 1);
    }

    public function test_stale_revision_and_changed_published_version_are_rejected(): void
    {
        $this->send($this->payload(['revision' => 99]))->assertConflict();
        $this->send($this->payload(['package_version' => 2]))->assertConflict();
        $this->assertDatabaseCount('customer_package_assignments', 1);
    }

    public function test_disabled_package_reason_and_bad_dates_validate_server_side(): void
    {
        $this->send($this->payload(['reason' => ' ']))->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->send($this->payload(['ends_on' => '2026-12-11']))->assertUnprocessable()->assertJsonValidationErrors('ends_on');
        $this->send($this->payload(['ends_on' => '2026-02-30']))->assertUnprocessable()->assertJsonValidationErrors('ends_on');
        $this->send($this->payload(['ends_on' => '2027-01-12']))->assertUnprocessable()->assertJsonValidationErrors('ends_on');
        $this->send($this->payload(['timing' => 'custom', 'starts_on' => '2026-11-11']))->assertUnprocessable()->assertJsonValidationErrors('starts_on');
        $this->package->update(['status' => 'inactive']);
        $this->send($this->payload())->assertUnprocessable()->assertJsonValidationErrors('package_id');
    }

    public function test_scheduled_renewal_requires_explicit_replacement_and_cancellation_preserves_current(): void
    {
        $first = $this->send($this->payload())->assertOk()->json('id');
        $this->send($this->payload())->assertUnprocessable()->assertJsonValidationErrors('replace_upcoming');
        $second = $this->send($this->payload(['replace_upcoming' => true]))->assertOk()->json('id');
        $this->assertSame('cancelled', CustomerPackageAssignment::find($first)->status());
        $this->send($this->simple('cancel'), $second)->assertOk();
        $this->assertSame($this->assignment->id, $this->customer->fresh()->currentPackageAssignment->id);
        $this->assertSame('active', $this->assignment->fresh()->status());
    }

    public function test_extension_keeps_allowance_origin_and_old_terms_and_rejects_overlap(): void
    {
        $id = $this->send($this->simple('extend', ['ends_on' => '2026-12-20']))->assertOk()->json('id');
        $replacement = CustomerPackageAssignment::find($id);
        $this->assertSame($this->assignment->snapshot['definition'], $replacement->snapshot['definition']);
        $this->assertTrue($replacement->allowance_starts_at->equalTo($this->assignment->starts_at));
        $this->assertSame('2026-12-12', $this->assignment->fresh()->ends_at->toDateString());
        $this->assertSame('2026-12-20', $replacement->ends_at->toDateString());
        $this->send($this->simple('extend', ['ends_on' => '2026-12-21']))->assertConflict();
        $this->assignment = $replacement;
        $this->send($this->payload(['starts_on' => '2026-12-20', 'ends_on' => '2027-01-19']))->assertOk();
        $this->send($this->simple('extend', ['ends_on' => '2026-12-21']))->assertUnprocessable()->assertJsonValidationErrors('ends_on');
    }

    public function test_immediate_change_preserves_usage_origin_and_published_snapshot(): void
    {
        $id = $this->send($this->payload(['action' => 'change', 'timing' => 'now', 'starts_on' => '2026-11-12', 'ends_on' => '2026-12-12']))->assertOk()->json('id');
        $replacement = CustomerPackageAssignment::find($id);
        $this->assertTrue($replacement->allowance_starts_at->equalTo($this->assignment->starts_at));
        $this->assertSame('package_change', $replacement->source);
        $this->assertSame($this->package->definition, $replacement->snapshot['definition']);
        $this->assertSame($id, $this->customer->fresh()->currentPackageAssignment->id);
        $this->assertSame('replaced', $this->assignment->fresh()->status());
    }

    public function test_renew_now_requires_explicit_confirmation_for_allocation_reset(): void
    {
        $payload = $this->payload(['timing' => 'now', 'starts_on' => '2026-11-12', 'ends_on' => '2026-12-12']);
        $this->send($payload)->assertUnprocessable()->assertJsonValidationErrors('starts_on');
        $id = $this->send(array_replace($payload, ['replace_current' => true]))->assertOk()->json('id');
        $this->assertSame('2026-11-12 10:00:00', CustomerPackageAssignment::find($id)->allowance_starts_at->format('Y-m-d H:i:s'));
    }

    public function test_end_access_does_not_suspend_account_or_delete_files(): void
    {
        $this->send($this->simple('end', ['confirm_end' => true]))->assertOk();
        $this->assertSame('ended', $this->assignment->fresh()->status());
        $this->assertSame('active', $this->customer->fresh()->account_status);
        $this->getJson('/admin/subscriptions?status=ended')->assertOk()->assertJsonCount(1, 'items');
    }

    public function test_suspended_account_keeps_subscription_status_separate(): void
    {
        $this->customer->forceFill(['account_status' => 'suspended'])->save();
        $this->getJson('/admin/subscriptions')->assertOk()->assertJsonPath('items.0.status', 'active')->assertJsonPath('items.0.customer.status', 'suspended');
    }

    public function test_summary_search_custom_range_and_expiry_boundaries(): void
    {
        $this->assignment->update(['ends_at' => '2026-11-14']);
        $this->getJson('/admin/subscriptions?status=expiring')->assertOk()->assertJsonPath('summary.expiring', 1)->assertJsonCount(1, 'items');
        $this->getJson('/admin/subscriptions?q=SUB-'.str_pad((string) $this->assignment->id, 6, '0', STR_PAD_LEFT))->assertOk()->assertJsonCount(1, 'items');
        $this->getJson('/admin/subscriptions?dates=custom&from=2026-11-13&to=2026-11-15')->assertOk()->assertJsonCount(1, 'items');
        $this->getJson('/admin/subscriptions?q=unmatched')->assertOk()->assertJsonCount(0, 'items');
        $this->travelTo(CarbonImmutable::parse('2026-11-14 00:00:00', 'UTC'));
        $this->getJson('/admin/subscriptions?status=expired')->assertOk()->assertJsonPath('summary.active', 0)->assertJsonCount(1, 'items');
    }

    public function test_legacy_corrections_resolve_without_rewriting_prior_rows(): void
    {
        $this->assignment->update(['source' => 'legacy']);
        $original = $this->assignment->fresh()->getAttributes();
        $latest = $this->assignment->replicate();
        $latest->save();
        $this->assertSame($latest->id, $this->customer->fresh()->currentPackageAssignment->id);
        $this->getJson('/admin/subscriptions')->assertOk()->assertJsonCount(1, 'items');
        $this->assertSame($original, $this->assignment->fresh()->getAttributes());
    }

    public function test_history_keeps_admin_reason_and_before_after_and_has_no_billing_side_effect(): void
    {
        $this->send($this->payload())->assertOk();
        $this->actingAs($this->customer, 'web');
        $this->getJson('/admin/subscriptions/'.$this->assignment->id)->assertOk()->assertJsonPath('history.0.actor', $this->admin->name)->assertJsonPath('history.0.metadata.reason', 'Customer requested renewal')->assertJsonPath('history.0.metadata.before.id', $this->assignment->id)->assertJsonPath('next.source', 'renewal');
        $this->assertSame($this->admin->id, CustomerAccountEvent::sole()->actor_id);
    }

    public function test_state_changes_require_csrf(): void
    {
        $this->app->instance('env', 'production');
        $this->send($this->payload())->assertStatus(419);
        $this->assertDatabaseCount('customer_package_assignments', 1);
    }

    public function test_expired_and_ended_periods_block_new_work_but_keep_workspace_and_history_available(): void
    {
        $this->send($this->simple('end', ['confirm_end' => true]))->assertOk();
        $this->actingAs($this->customer->fresh(), 'web');
        $this->postJson('/media/projects', [])->assertForbidden()->assertJsonPath('code', 'subscription_unavailable');
        $this->postJson('/media/projects/youtube', [])->assertForbidden()->assertJsonPath('code', 'subscription_unavailable');
        $this->get('/')->assertOk();
        $this->get('/projects')->assertOk();
        $this->assignment->update(['closed_kind' => null, 'closed_at' => null, 'ends_at' => '2026-11-12 10:00:00']);
        $this->postJson('/media/projects', [])->assertForbidden()->assertJsonPath('code', 'subscription_unavailable');
    }

    public function test_future_cancellation_cannot_end_the_current_period_and_end_requires_confirmation(): void
    {
        $this->send($this->simple('cancel'))->assertConflict();
        $this->send($this->simple('end'))->assertUnprocessable()->assertJsonValidationErrors('confirm_end');
        $this->assertSame('active', $this->assignment->fresh()->status());
    }

    public function test_ended_status_is_consistent_in_user_accounts_and_reason_is_preserved(): void
    {
        $this->send($this->simple('end', ['confirm_end' => true]))->assertOk();
        $this->getJson('/admin/users?status=active')->assertOk()->assertJsonCount(0, 'users');
        $this->getJson('/admin/users?status=expired')->assertOk()->assertJsonCount(1, 'users')->assertJsonPath('users.0.display_status', 'expired');
        $this->getJson('/admin/subscriptions/'.$this->assignment->id)->assertOk()->assertJsonPath('history.0.metadata.reason', 'Admin reviewed this change');
    }

    public function test_renewal_access_boundary_is_automatic_and_accepts_new_work_after_start(): void
    {
        $this->send($this->payload())->assertOk();
        $this->actingAs($this->customer, 'web');
        $this->travelTo(CarbonImmutable::parse('2026-12-12 00:00:00', 'UTC'));
        $this->postJson('/media/projects', [])->assertUnprocessable()->assertJsonValidationErrors('filename');
    }

    public function test_expired_historical_period_cannot_replenish_access_over_an_existing_active_period(): void
    {
        $this->send($this->payload())->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-12-13 00:00:00', 'UTC'));
        $this->send($this->payload(['timing' => 'now', 'starts_on' => '2026-12-13', 'ends_on' => '2027-01-12', 'replace_current' => true]))->assertUnprocessable()->assertJsonValidationErrors('starts_on');
        $this->assertDatabaseCount('customer_package_assignments', 2);
    }

    public function test_scheduled_package_change_keeps_current_snapshot_and_inactive_catalog_changes_do_not_rewrite_it(): void
    {
        $this->send($this->payload(['action' => 'change']))->assertOk();
        $this->package->update(['status' => 'archived', 'name' => 'Archived product']);
        $this->assertSame($this->assignment->id, $this->customer->fresh()->currentPackageAssignment->id);
        $this->travelTo(CarbonImmutable::parse('2026-12-12 00:00:00', 'UTC'));
        $current = $this->customer->fresh()->currentPackageAssignment;
        $this->assertSame('package_change', $current->source);
        $this->assertNotSame('Archived product', $current->snapshot['name']);
        $this->assertTrue($current->allowance_starts_at->equalTo($current->starts_at));
    }

    public function test_main_list_keeps_one_customer_row_and_exposes_the_scheduled_period(): void
    {
        $nextId = $this->send($this->payload())->assertOk()->json('id');
        $this->getJson('/admin/subscriptions')->assertOk()->assertJsonCount(1, 'items')
            ->assertJsonPath('pagination.total', 1)->assertJsonPath('items.0.id', $this->assignment->id)
            ->assertJsonPath('items.0.next_period.id', $nextId)->assertJsonPath('summary.upcoming', 1);
        $this->getJson('/admin/subscriptions?status=upcoming')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $nextId)->assertJsonPath('items.0.next_period', null);
        $this->travelTo(CarbonImmutable::parse('2026-12-12 00:00:00', 'UTC'));
        $this->getJson('/admin/subscriptions')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $nextId)->assertJsonPath('summary.expired', 0)->assertJsonPath('summary.active', 1);
        $this->getJson('/admin/subscriptions?status=expired')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson('/admin/subscriptions/'.$nextId)->assertOk()->assertJsonCount(2, 'periods')->assertJsonCount(1, 'history');
        $this->assertDatabaseCount('customer_package_assignments', 2);
    }

    public function test_many_monthly_renewals_do_not_accumulate_rows_or_expired_customer_counts(): void
    {
        $initialId = $this->assignment->id;
        for ($month = 0; $month < 6; $month++) {
            $current = $this->customer->fresh()->currentPackageAssignment;
            $nextId = $this->send($this->payload([
                'starts_on' => $current->ends_at->toDateString(),
                'ends_on' => $current->ends_at->addDays(30)->toDateString(),
            ]), $current->id)->assertOk()->json('id');
            $this->travelTo($current->ends_at);
            $this->getJson('/admin/subscriptions')->assertOk()->assertJsonCount(1, 'items')
                ->assertJsonPath('items.0.id', $nextId)->assertJsonPath('pagination.total', 1)->assertJsonPath('summary.expired', 0);
        }
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('customer_package_assignments', 7);
        $this->getJson('/admin/subscriptions/'.$nextId)->assertOk()->assertJsonCount(7, 'periods')->assertJsonCount(6, 'history');
        $this->getJson('/admin/subscriptions?q=SUB-'.str_pad((string) $initialId, 6, '0', STR_PAD_LEFT))->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $nextId);
    }

    public function test_cancelled_future_period_stays_in_history_without_duplicating_customer(): void
    {
        $nextId = $this->send($this->payload())->assertOk()->json('id');
        $this->send($this->simple('cancel'), $nextId)->assertOk();
        $this->getJson('/admin/subscriptions')->assertOk()->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.next_period', null)->assertJsonPath('summary.upcoming', 0)->assertJsonPath('items.0.status', 'active');
        $this->getJson('/admin/subscriptions/'.$this->assignment->id)->assertOk()->assertJsonCount(2, 'periods')->assertJsonCount(2, 'history')->assertJsonPath('periods.0.status', 'cancelled');
    }

    public function test_pagination_counts_customers_instead_of_period_history(): void
    {
        for ($index = 0; $index < 12; $index++) {
            $user = User::factory()->create(['is_admin' => false]);
            foreach ([['2026-10-01', '2026-10-31'], ['2026-11-01', '2026-12-01']] as [$start, $end]) {
                $period = $this->assignment->replicate();
                $period->forceFill(['user_id' => $user->id, 'source' => 'renewal', 'starts_at' => $start, 'ends_at' => $end])->save();
            }
        }
        $response = $this->getJson('/admin/subscriptions?per_page=10')->assertOk()->assertJsonCount(10, 'items')->assertJsonPath('pagination.total', 13)->assertJsonPath('pagination.pages', 2);
        $this->assertCount(10, array_unique(array_column(array_column($response->json('items'), 'customer'), 'id')));
        $this->getJson('/admin/subscriptions?per_page=10&page=2')->assertOk()->assertJsonCount(3, 'items');
    }
}
