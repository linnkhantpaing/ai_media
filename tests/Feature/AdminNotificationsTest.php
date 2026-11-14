<?php

namespace Tests\Feature;

use App\Models\AdminNotice;
use App\Models\AuditEvent;
use App\Models\CustomerPackageAssignment;
use App\Models\MediaProject;
use App\Models\MediaTask;
use App\Models\MediaWorker;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\PaymentRefund;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\AdminNotifications;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AdminNotifications $notices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-11-13 10:00:00', 'UTC'));
        Cache::flush();
        Mail::fake();
        Notification::fake();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->notices = app(AdminNotifications::class);
        $this->actingAs($this->admin, 'admin');
    }

    private function publish(string $key, array $extra = [], mixed $when = null): AdminNotice
    {
        $this->notices->publish($key, array_replace([
            'category' => 'jobs', 'kind' => 'job_failed', 'priority' => 'critical',
            'target_type' => 'workers', 'target_id' => null, 'reference' => 'Worker Health',
            'context' => [],
        ], $extra), $when);

        return AdminNotice::where('dedupe_key', $key)->firstOrFail();
    }

    private function assignment(User $customer, CarbonImmutable $start, CarbonImmutable $end): CustomerPackageAssignment
    {
        $package = Package::factory()->create(['status' => 'active', 'published_version' => 1]);
        $version = PackageVersion::create(['package_id' => $package->id, 'number' => 1, 'definition' => $package->definition]);

        return CustomerPackageAssignment::create([
            'user_id' => $customer->id, 'package_id' => $package->id, 'package_version_id' => $version->id,
            'source' => 'account_creation', 'starts_at' => $start, 'ends_at' => $end,
            'snapshot' => ['name' => $package->name, 'version' => 1, 'definition' => $version->definition],
        ]);
    }

    public function test_customer_guard_cannot_read_or_mutate_admin_notifications(): void
    {
        $notice = $this->publish('permission');
        auth('admin')->logout();
        $customer = User::factory()->create(['is_admin' => false]);
        $this->actingAs($customer, 'web');
        $this->getJson('/admin/notifications')->assertUnauthorized();
        $this->getJson('/admin/notifications/feed')->assertUnauthorized();
        $this->getJson('/admin/notifications/'.$notice->id)->assertUnauthorized();
        $this->postJson('/admin/notifications/action', ['action' => 'read', 'ids' => [$notice->id]])->assertUnauthorized();
        $this->assertDatabaseCount('admin_notice_states', 0);
    }

    public function test_read_and_archive_are_per_admin_and_do_not_resolve_an_issue(): void
    {
        $notice = $this->publish('private-state');
        $this->getJson('/admin/notifications/feed')->assertOk()->assertJsonPath('summary.unread', 1);
        $this->getJson('/admin/notifications/'.$notice->id)->assertOk()->assertJsonPath('unread', true);
        $this->assertDatabaseCount('admin_notice_states', 0);
        $this->postJson('/admin/notifications/action', ['action' => 'read', 'ids' => [$notice->id]])
            ->assertOk()->assertJsonPath('summary.unread', 0)->assertJsonPath('summary.attention', 1);
        $this->postJson('/admin/notifications/action', ['action' => 'archive', 'ids' => [$notice->id]])->assertOk();
        $this->getJson('/admin/notifications?archived=1')->assertOk()->assertJsonPath('pagination.total', 1);
        $this->assertSame('attention', $notice->fresh()->issue);

        $other = User::factory()->create(['is_admin' => true]);
        $this->actingAs($other, 'admin');
        $this->getJson('/admin/notifications/feed')->assertOk()->assertJsonPath('summary.unread', 1);
        $this->getJson('/admin/notifications?archived=1')->assertJsonPath('pagination.total', 0);
        $this->actingAs($this->admin, 'admin');
        $this->postJson('/admin/notifications/action', ['action' => 'restore', 'ids' => [$notice->id]])->assertOk();
        $this->getJson('/admin/notifications/feed')->assertJsonPath('summary.unread', 0);
    }

    public function test_repeated_sync_deduplicates_but_a_new_failure_reopens_as_unread(): void
    {
        $task = MediaTask::factory()->create(['status' => 'failed', 'finished_at' => now()]);
        $notice = AdminNotice::where('dedupe_key', 'job:'.$task->id)->firstOrFail();
        $this->notices->change($this->admin, 'read', [$notice->id]);
        $this->notices->change($this->admin, 'archive', [$notice->id]);
        $this->notices->synchronize(true);
        $this->notices->synchronize(true);
        $this->assertSame(1, $notice->fresh()->revision);
        $this->assertDatabaseCount('admin_notices', 1);
        $this->assertSame(0, $this->notices->summary($this->admin)['unread']);

        $task->update(['status' => 'completed']);
        $this->assertSame('resolved', $notice->fresh()->issue);
        $task->update(['status' => 'failed']);
        $this->assertSame(2, $notice->fresh()->revision);
        $this->getJson('/admin/notifications/feed')->assertJsonPath('summary.unread', 1)->assertJsonPath('summary.critical', 1);
        $this->getJson('/admin/notifications?archived=1')->assertJsonPath('pagination.total', 0);
    }

    public function test_payment_notifications_are_safe_and_reading_never_approves_or_sends_mail(): void
    {
        $payment = PaymentTransaction::factory()->create([
            'transfer' => ['reference' => 'PRIVATE-TRANSFER', 'sender' => 'Private Sender'],
            'internal_note' => 'PRIVATE-NOTE',
        ]);
        $notice = AdminNotice::where('dedupe_key', 'payment-review:'.$payment->id)->firstOrFail();
        $response = $this->getJson('/admin/notifications/'.$notice->id)->assertOk()
            ->assertJsonPath('current.status', 'pending_review');
        $this->assertStringNotContainsString('PRIVATE-', $response->getContent());
        $this->assertStringNotContainsString('receiving_snapshot', $response->getContent());
        $this->assertStringContainsString('/admin/payments?', $response->json('related_url'));
        $this->postJson('/admin/notifications/action', ['action' => 'read', 'ids' => [$notice->id]])->assertOk();
        $this->postJson('/admin/notifications/action', ['action' => 'archive', 'ids' => [$notice->id]])->assertOk();
        $this->assertSame('pending_review', $payment->fresh()->status);
        $this->assertDatabaseCount('customer_package_assignments', 0);
        Mail::assertNothingSent();
        Notification::assertNothingSent();

        $payment->update(['status' => 'approved', 'approved_at' => now()]);
        $this->assertSame('resolved', $notice->fresh()->issue);
        $this->assertDatabaseHas('admin_notices', ['dedupe_key' => 'payment-approved:'.$payment->id, 'issue' => 'resolved', 'priority' => 'info']);
        $this->notices->synchronize(true);
        $this->assertDatabaseCount('admin_notices', 2);
    }

    public function test_model_rollback_does_not_leave_a_payment_notification(): void
    {
        DB::beginTransaction();
        $payment = PaymentTransaction::factory()->create();
        $id = $payment->id;
        $this->assertDatabaseHas('admin_notices', ['dedupe_key' => 'payment-review:'.$id]);
        DB::rollBack();
        $this->assertDatabaseMissing('admin_notices', ['dedupe_key' => 'payment-review:'.$id]);
    }

    public function test_standby_is_normal_but_a_waiting_queue_without_workers_needs_attention(): void
    {
        $this->notices->synchronize(true);
        $this->assertDatabaseCount('admin_notices', 0);
        $job = DB::table('jobs')->insertGetId([
            'queue' => config('operations.queues')[0], 'payload' => '{}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => now()->subMinutes(3)->timestamp, 'created_at' => now()->subMinutes(3)->timestamp,
        ]);
        $this->notices->synchronize(true);
        $this->assertDatabaseHas('admin_notices', ['dedupe_key' => 'worker:availability', 'issue' => 'attention', 'priority' => 'critical']);
        MediaWorker::factory()->create();
        $this->notices->synchronize(true);
        $this->assertDatabaseHas('admin_notices', ['dedupe_key' => 'worker:availability', 'issue' => 'resolved']);
        DB::table('jobs')->where('id', $job)->delete();
        MediaWorker::query()->update(['stopped_at' => now()]);
        $this->notices->synchronize(true);
        $this->assertDatabaseCount('admin_notices', 1);
    }

    public function test_next_period_coverage_and_replacement_remove_false_expiry_attention(): void
    {
        $customer = User::factory()->create(['is_admin' => false]);
        $current = $this->assignment($customer, CarbonImmutable::now()->subDays(20), CarbonImmutable::now()->addDays(3));
        $this->notices->synchronize(true);
        $notice = AdminNotice::where('dedupe_key', 'subscription:'.$current->id)->firstOrFail();
        $this->assertSame('attention', $notice->issue);
        $next = $this->assignment($customer, $current->ends_at, $current->ends_at->addDays(30));
        $this->notices->synchronize(true);
        $this->assertSame('info', $notice->fresh()->issue);
        $this->assertSame($next->starts_at->toIso8601String(), $notice->fresh()->context['scheduled_start']);
        $this->travelTo($next->starts_at->addMinute());
        $this->notices->synchronize(true);
        $this->assertSame('resolved', $notice->fresh()->issue);
        $this->assertSame(0, $this->notices->summary($this->admin)['attention']);
    }

    public function test_future_period_with_a_gap_does_not_hide_expired_access(): void
    {
        $customer = User::factory()->create(['is_admin' => false]);
        $expired = $this->assignment($customer, CarbonImmutable::now()->subDays(31), CarbonImmutable::now()->subDay());
        $this->assignment($customer, CarbonImmutable::now()->addDays(2), CarbonImmutable::now()->addDays(32));
        $this->notices->synchronize(true);
        $this->assertDatabaseHas('admin_notices', ['dedupe_key' => 'subscription:'.$expired->id, 'kind' => 'subscription_expired', 'issue' => 'attention']);
        $customer->forceFill(['account_status' => 'archived'])->save();
        $this->notices->synchronize(true);
        $this->assertSame('resolved', AdminNotice::where('dedupe_key', 'subscription:'.$expired->id)->firstOrFail()->issue);
    }

    public function test_filters_pagination_and_mark_all_preserve_archive_state(): void
    {
        for ($i = 1; $i <= 23; $i++) {
            $this->publish('page:'.$i, ['reference' => 'Reference '.$i], now()->subMinutes($i));
        }
        $old = $this->publish('old', ['category' => 'payments', 'kind' => 'payment_review', 'priority' => 'warning'], now()->subDays(20));
        $this->notices->change($this->admin, 'archive', [$old->id]);
        $this->getJson('/admin/notifications?page=3')->assertOk()->assertJsonPath('pagination.page', 3)->assertJsonPath('pagination.from', 21)->assertJsonCount(3, 'items');
        $this->getJson('/admin/notifications?page=100')->assertJsonPath('pagination.page', 3);
        $this->getJson('/admin/notifications?category=payments')->assertJsonCount(0, 'items');
        $this->getJson('/admin/notifications?q=Reference%2023')->assertJsonCount(1, 'items');
        $this->getJson('/admin/notifications?dates=custom&from=2026-11-13&to=2026-11-13')->assertJsonPath('pagination.total', 23);
        $this->postJson('/admin/notifications/action', ['action' => 'read_all'])->assertOk()->assertJsonPath('summary.unread', 0);
        $this->getJson('/admin/notifications?archived=1&read=unread&dates=all')->assertJsonCount(1, 'items');
        $this->assertDatabaseHas('admin_notice_states', ['notice_id' => $old->id, 'read_revision' => 0, 'archived_revision' => 1]);
    }

    public function test_invalid_filters_and_bulk_actions_do_not_mutate_state(): void
    {
        $notice = $this->publish('validation');
        $this->getJson('/admin/notifications?dates=custom&from=2026-11-14&to=2026-11-12')->assertUnprocessable();
        $this->postJson('/admin/notifications/action', ['action' => 'approve', 'ids' => [$notice->id]])->assertUnprocessable();
        $this->postJson('/admin/notifications/action', ['action' => 'read', 'ids' => [$notice->id, $notice->id]])->assertUnprocessable();
        $this->postJson('/admin/notifications/action', ['action' => 'read', 'ids' => [$notice->id, 999999]])->assertUnprocessable();
        $this->getJson('/admin/notifications/999999')->assertNotFound();
        $this->assertDatabaseCount('admin_notice_states', 0);
    }

    public function test_replaying_latest_connection_failure_does_not_reopen_a_read_notification(): void
    {
        $event = AuditEvent::create([
            'source' => 'general', 'source_id' => 'smtp-1', 'category' => 'settings',
            'action' => 'general.smtp_connection_failed', 'result' => 'failed',
            'actor_kind' => 'system', 'actor_name' => 'System', 'actor_kind' => 'system', 'actor_name' => 'System', 'target_type' => 'general', 'target_label' => 'General Settings',
            'details' => [], 'occurred_at' => now(), 'recorded_at' => now(), 'historical' => false,
        ]);
        $this->notices->audit($event);
        $notice = AdminNotice::where('dedupe_key', 'connection:smtp:connection')->firstOrFail();
        $this->notices->change($this->admin, 'read', [$notice->id]);
        $this->notices->synchronize(true);
        $this->notices->synchronize(true);
        $this->assertSame(1, $notice->fresh()->revision);
        $this->assertSame(0, $this->notices->summary($this->admin)['unread']);
        $recovery = AuditEvent::create([
            'source' => 'general', 'source_id' => 'smtp-2', 'category' => 'settings',
            'action' => 'general.smtp_email_succeeded', 'result' => 'success',
            'actor_kind' => 'system', 'actor_name' => 'System', 'target_type' => 'general', 'target_label' => 'General Settings',
            'details' => [], 'occurred_at' => now(), 'recorded_at' => now(), 'historical' => false,
        ]);
        $this->notices->audit($recovery);
        $this->notices->synchronize(true);
        $this->assertSame('resolved', $notice->fresh()->issue);
        $this->assertSame(1, $notice->fresh()->revision);
    }

    public function test_storage_alert_uses_verified_bytes_and_latest_published_allowance(): void
    {
        $customer = User::factory()->create(['is_admin' => false]);
        $assignment = $this->assignment($customer, CarbonImmutable::now()->subDays(2), CarbonImmutable::now()->addDays(28));
        $quota = (int) $assignment->effectiveSnapshot()['definition']['storage_bytes'];
        $project = MediaProject::factory()->create(['user_id' => $customer->id]);
        DB::table('media_files')->insert([
            'media_project_id' => $project->id, 'disk' => 'local', 'path' => 'notifications-test/input.mp4',
            'bytes' => (int) ceil($quota * .95), 'state' => 'active', 'is_source' => true,
            'verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->notices->synchronize(true);
        $notice = AdminNotice::where('dedupe_key', 'storage:'.$customer->id)->firstOrFail();
        $this->assertSame('attention', $notice->issue);
        $definition = $assignment->effectiveSnapshot()['definition'];
        $definition['storage_bytes'] = $quota * 2;
        PackageVersion::create(['package_id' => $assignment->package_id, 'number' => 2, 'definition' => $definition]);
        Package::whereKey($assignment->package_id)->update(['published_version' => 2]);
        $this->notices->synchronize(true);
        $this->assertSame('resolved', $notice->fresh()->issue);
        DB::table('media_files')->update(['bytes' => $quota * 2]);
        $this->notices->synchronize(true);
        $this->assertSame('attention', $notice->fresh()->issue);
        $assignment->update(['closed_kind' => 'ended', 'closed_at' => now()]);
        $this->notices->synchronize(true);
        $this->assertSame('resolved', $notice->fresh()->issue);
    }

    public function test_refund_state_changes_generate_and_resolve_the_same_safe_notice(): void
    {
        $payment = PaymentTransaction::factory()->create(['status' => 'approved', 'approved_at' => now()]);
        $refund = PaymentRefund::create([
            'payment_transaction_id' => $payment->id, 'amount_minor' => 200, 'status' => 'requested',
            'access_action' => 'keep', 'details' => ['account' => 'PRIVATE-REFUND'], 'reason' => 'PRIVATE-REASON',
            'created_by' => $this->admin->id,
        ]);
        $notice = AdminNotice::where('dedupe_key', 'refund:'.$refund->id)->firstOrFail();
        $response = $this->getJson('/admin/notifications/'.$notice->id)->assertOk()->assertJsonPath('context.amount_minor', 200);
        $this->assertStringNotContainsString('PRIVATE-', $response->getContent());
        $refund->update(['status' => 'completed']);
        $this->assertSame('resolved', $notice->fresh()->issue);
    }

    public function test_bell_feed_shows_only_unread_notices_before_applying_the_five_item_limit(): void
    {
        $unread = [];
        for ($i = 0; $i < 7; $i++) {
            $unread[] = $this->publish('unread-bell:'.$i, [], now()->subMinutes(20 + $i));
        }
        $this->notices->change($this->admin, 'archive', [$unread[6]->id]);
        for ($i = 0; $i < 6; $i++) {
            $read = $this->publish('read-bell:'.$i, [], now()->subMinutes($i));
            $this->notices->change($this->admin, 'read', [$read->id]);
        }

        $feed = $this->getJson('/admin/notifications/feed')->assertOk()
            ->assertJsonPath('summary.unread', 6)->assertJsonCount(5, 'items')
            ->assertJsonPath('items.0.id', $unread[0]->id);
        $this->assertSame(array_map(fn ($notice) => $notice->id, array_slice($unread, 0, 5)), array_column($feed->json('items'), 'id'));
        foreach ($feed->json('items') as $item) {
            $this->assertTrue($item['unread']);
            $this->assertFalse($item['archived']);
        }

        $this->postJson('/admin/notifications/action', ['action' => 'read', 'ids' => [$unread[0]->id]])
            ->assertOk()->assertJsonPath('summary.unread', 5)->assertJsonCount(5, 'items')
            ->assertJsonPath('items.0.id', $unread[1]->id);
        $this->getJson('/admin/notifications?read=read&dates=all')->assertOk()->assertJsonPath('pagination.total', 7);
        $this->postJson('/admin/notifications/action', ['action' => 'read_all'])
            ->assertOk()->assertJsonPath('summary.unread', 0)->assertJsonCount(0, 'items');
        $this->getJson('/admin/notifications/feed')->assertOk()->assertJsonCount(0, 'items');
    }
}
