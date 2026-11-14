<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\PaymentRefund;
use App\Models\PaymentTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RevenueReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-11-13 10:00:00', 'UTC'));
        Mail::fake();
        Notification::fake();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->customer = User::factory()->create(['is_admin' => false]);
        $this->actingAs($this->admin, 'admin');
    }

    private function payment(int $amount = 10000, string $currency = 'MMK', string $at = '2026-11-12 10:00:00', array $extra = []): PaymentTransaction
    {
        $method = PaymentMethod::factory()->create(['currency' => $currency]);

        return PaymentTransaction::factory()->create(array_replace([
            'user_id' => $this->customer->id, 'currency' => $currency, 'received_minor' => $amount, 'expected_minor' => $amount,
            'status' => 'approved', 'approved_at' => CarbonImmutable::parse($at, 'UTC'), 'payment_method_id' => $method->id,
        ], $extra));
    }

    private function refund(PaymentTransaction $payment, int $amount, string $at = '2026-11-12 11:00:00', string $status = 'completed'): PaymentRefund
    {
        return PaymentRefund::create(['payment_transaction_id' => $payment->id, 'amount_minor' => $amount, 'status' => $status,
            'completed_at' => $status === 'completed' ? CarbonImmutable::parse($at, 'UTC') : null,
            'access_action' => 'keep', 'details' => ['destination' => 'PRIVATE-DESTINATION'], 'reason' => 'PRIVATE-REASON', 'created_by' => $this->admin->id]);
    }

    public function test_admin_guard_is_required_for_reports_details_and_exports(): void
    {
        $payment = $this->payment();
        auth('admin')->logout();
        $this->actingAs($this->customer, 'web');
        foreach (['/admin/reports/revenue', '/admin/reports/revenue/export', '/admin/reports/revenue/payment/'.$payment->id] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
    }

    public function test_currencies_remain_separate_and_only_completed_financial_events_count(): void
    {
        $mmk = $this->payment(1000000);
        $thb = $this->payment(29900, 'THB');
        $this->payment(999, 'USD');
        $this->payment(500000, 'MMK', extra: ['status' => 'pending_review', 'approved_at' => null]);
        $this->refund($mmk, 100000);
        $this->refund($thb, 9900);
        $this->refund($mmk, 5000, status: 'requested');
        $this->refund($mmk, 7000, status: 'rejected');
        $response = $this->getJson('/admin/reports/revenue')->assertOk();
        $response->assertJsonPath('summary.amounts.MMK.gross', 1000000)->assertJsonPath('summary.amounts.MMK.refunds', 100000)
            ->assertJsonPath('summary.amounts.MMK.net', 900000)->assertJsonPath('summary.amounts.THB.net', 20000)
            ->assertJsonPath('summary.amounts.USD.net', 999)->assertJsonPath('summary.counts.payments', 3)
            ->assertJsonPath('summary.counts.refunds', 2)->assertJsonPath('summary.counts.customers', 1)->assertJsonPath('pagination.total', 5);
        $this->assertArrayNotHasKey('grand_total', $response->json('summary'));
        $this->getJson('/admin/reports/revenue?currency=USD')->assertJsonPath('summary.counts.payments', 1)->assertJsonPath('pagination.total', 1);
    }

    public function test_refunds_use_completion_time_and_may_make_a_period_negative_without_duplicate_gross(): void
    {
        $old = $this->payment(10000, at: '2026-10-01 10:00:00');
        $this->refund($old, 2000);
        $this->refund($old, 3000);
        $this->refund($old, 1000, '2026-10-02 10:00:00');
        $this->getJson('/admin/reports/revenue?dates=30')->assertOk()
            ->assertJsonPath('summary.amounts.MMK.gross', 0)->assertJsonPath('summary.amounts.MMK.refunds', 5000)
            ->assertJsonPath('summary.amounts.MMK.net', -5000)->assertJsonPath('summary.counts.payments', 0)
            ->assertJsonPath('summary.counts.refunds', 2)->assertJsonPath('pagination.total', 2);
        $this->getJson('/admin/reports/revenue?dates=all')->assertJsonPath('summary.amounts.MMK.gross', 10000)
            ->assertJsonPath('summary.amounts.MMK.net', 4000)->assertJsonPath('summary.counts.payments', 1);
    }

    public function test_date_boundaries_and_chart_buckets_follow_platform_timezone(): void
    {
        $this->payment(1000, at: '2026-11-11 16:59:59');
        $this->payment(2000, at: '2026-11-11 17:00:00');
        $this->payment(3000, at: '2026-11-12 16:59:59');
        $this->payment(4000, at: '2026-11-12 17:00:00');
        $this->getJson('/admin/reports/revenue?dates=custom&from=2026-11-12&to=2026-11-12&group=day')->assertOk()
            ->assertJsonPath('range.timezone', 'Asia/Bangkok')->assertJsonPath('summary.amounts.MMK.gross', 5000)
            ->assertJsonCount(1, 'trend.points')->assertJsonPath('trend.points.0.date', '2026-11-12')
            ->assertJsonPath('trend.points.0.values.MMK.net', 5000);
    }

    public function test_filters_do_not_rewrite_history_and_activity_filters_do_not_change_cards(): void
    {
        $payment = $this->payment(12345, extra: ['purpose' => 'renewal']);
        $this->refund($payment, 345);
        $snapshotName = $payment->package_snapshot['name'];
        $package = Package::findOrFail($payment->package_snapshot['id']);
        $package->update(['name' => 'Changed current name', 'definition' => [...$package->definition, 'price_minor' => 999999]]);
        PaymentMethod::whereKey($payment->payment_method_id)->update(['status' => 'archived']);
        $query = '/admin/reports/revenue?package='.$payment->package_snapshot['id'].'&method='.$payment->payment_method_id.'&purpose=renewal&type=refund';
        $response = $this->getJson($query)->assertOk()->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('summary.amounts.MMK.gross', 12345)->assertJsonPath('summary.amounts.MMK.net', 12000)
            ->assertJsonPath('items.0.package.name', $snapshotName);
        $this->assertSame(1, $response->json('packages.0.payments'));
        $this->getJson('/admin/reports/revenue?purpose=new')->assertJsonPath('summary.counts.payments', 0);
        $this->getJson('/admin/reports/revenue?q=nobody-matches')->assertJsonPath('pagination.total', 0)->assertJsonPath('summary.counts.payments', 1);
    }

    public function test_detail_is_safe_read_only_and_uses_lifetime_payment_totals(): void
    {
        $payment = $this->payment(10000, extra: ['internal_note' => 'PRIVATE-NOTE']);
        $refund = $this->refund($payment, 1500);
        $before = $payment->fresh()->getRawOriginal();
        $detail = $this->getJson('/admin/reports/revenue/refund/'.$refund->id)->assertOk()
            ->assertJsonPath('lifetime.gross', 10000)->assertJsonPath('lifetime.refunds', 1500)->assertJsonPath('lifetime.net', 8500);
        $this->assertStringNotContainsString('PRIVATE-', $detail->getContent());
        $this->assertStringNotContainsString('receiving_cipher', $detail->getContent());
        $this->assertStringNotContainsString('receiving_snapshot', $detail->getContent());
        $this->assertStringContainsString('/admin/payments?', $detail->json('related_url'));
        $this->assertSame($before, $payment->fresh()->getRawOriginal());
        $this->getJson('/admin/reports/revenue/payment/999999')->assertNotFound();
        $pending = $this->payment(extra: ['status' => 'pending_review', 'approved_at' => null]);
        $this->getJson('/admin/reports/revenue/payment/'.$pending->id)->assertNotFound();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_pagination_clamps_and_export_includes_all_matching_rows_safely(): void
    {
        $this->customer->update(['name' => '=SUM(1+1)']);
        $first = $this->payment();
        for ($i = 0; $i < 11; $i++) {
            PaymentTransaction::factory()->create([
                'user_id' => $this->customer->id, 'package_version_id' => $first->package_version_id,
                'package_snapshot' => $first->package_snapshot, 'status' => 'approved', 'approved_at' => now(),
            ]);
        }
        $this->getJson('/admin/reports/revenue?page=2')->assertOk()->assertJsonPath('pagination.from', 11)->assertJsonCount(2, 'items');
        $this->getJson('/admin/reports/revenue?page=100')->assertJsonPath('pagination.page', 2);
        $export = $this->get('/admin/reports/revenue/export')->assertOk()->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($export))));
        $this->assertCount(13, $lines);
        $this->assertStringContainsString("'=SUM(1+1)", $export);
        $this->assertStringNotContainsString('PRIVATE-', $export);
        $this->assertStringNotContainsString('receiving_snapshot', $export);
    }

    public function test_filters_validate_and_empty_data_remains_zero(): void
    {
        $this->getJson('/admin/reports/revenue')->assertOk()->assertJsonPath('summary.counts.payments', 0)->assertJsonPath('summary.amounts.MMK.net', 0)->assertJsonCount(0, 'items');
        foreach (['currency=EUR', 'sort=amount', 'per_page=999', 'dates=custom&from=2026-11-14&to=2026-11-12', 'dates=custom'] as $query) {
            $this->getJson('/admin/reports/revenue?'.$query)->assertUnprocessable();
        }
        $this->getJson('/admin/reports/revenue?currency=MMK&sort=amount')->assertOk();
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_calendar_presets_use_local_month_and_midnight_boundaries(): void
    {
        $this->payment(1000, at: '2026-10-31 16:59:59');
        $this->payment(2000, at: '2026-10-31 17:00:00');
        $this->payment(3000, at: '2026-11-12 16:59:59');
        $this->payment(4000, at: '2026-11-12 17:00:00');
        $this->getJson('/admin/reports/revenue?dates=last_month')->assertOk()
            ->assertJsonPath('summary.amounts.MMK.gross', 1000)->assertJsonPath('range.from', '2026-10-01')->assertJsonPath('range.to', '2026-10-31');
        $this->getJson('/admin/reports/revenue?dates=month')->assertOk()
            ->assertJsonPath('summary.amounts.MMK.gross', 9000)->assertJsonPath('range.from', '2026-11-01')->assertJsonPath('range.to', '2026-11-13');
        $this->getJson('/admin/reports/revenue?dates=1')->assertOk()->assertJsonPath('summary.amounts.MMK.gross', 4000);
        $this->getJson('/admin/reports/revenue?dates=7')->assertOk()->assertJsonPath('summary.amounts.MMK.gross', 7000);
    }

    public function test_transaction_labels_use_receiving_snapshot_without_exposing_private_details(): void
    {
        $payment = $this->payment(extra: ['receiving_snapshot' => ['receiving' => ['name' => 'Historical receiving method', 'type' => 'bank', 'details' => ['identifier' => 'PRIVATE-ACCOUNT']]]]);
        PaymentMethod::whereKey($payment->payment_method_id)->update(['name' => 'Renamed current method']);
        $response = $this->getJson('/admin/reports/revenue')->assertOk()->assertJsonPath('items.0.method.name', 'Historical receiving method');
        $this->assertStringNotContainsString('PRIVATE-ACCOUNT', $response->getContent());
        $this->getJson('/admin/reports/revenue/payment/'.$payment->id)->assertOk()->assertJsonPath('method.name', 'Historical receiving method');
    }
}
