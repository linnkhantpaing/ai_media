<?php

namespace Tests\Feature;

use App\Models\CustomerPackageAssignment;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\PaymentEvent;
use App\Models\PaymentReceipt;
use App\Models\PaymentRefund;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\PaymentConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PaymentTransactionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private Package $package;

    private int $method;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00', 'UTC'));
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->customer = User::factory()->create(['is_admin' => false, 'managed_account' => true]);
        $this->package = Package::factory()->create(['status' => 'active', 'published_version' => 1]);
        PackageVersion::create(['package_id' => $this->package->id, 'number' => 1, 'definition' => $this->package->definition, 'actor_id' => $this->admin->id]);
        $config = app(PaymentConfiguration::class);
        $this->method = $config->saveMethod($this->admin, ['name' => 'Example receiving', 'provider' => 'Example Provider', 'type' => 'bank', 'currency' => 'USD', 'status' => 'active', 'sort_order' => 0, 'details' => ['holder' => 'Test Business', 'identifier' => 'TEST-1234', 'branch' => '', 'routing' => ''], 'instructions' => ['en' => 'Transfer exact amount.', 'my' => '', 'th' => ''], 'make_default' => true, 'enable_currency' => true, 'remove_qr' => false, 'config_revision' => 0, 'request_id' => (string) Str::uuid()])['id'];
        $rules = $config->settings()['rules'];
        $rules['evidence_required'] = false;
        $config->saveRules($this->admin, ['rules' => $rules, 'config_revision' => 1, 'request_id' => (string) Str::uuid()]);
        $this->actingAs($this->admin, 'admin');
    }

    private function data(array $extra = []): array
    {
        return array_replace(['request_id' => (string) Str::uuid(), 'config_revision' => app(PaymentConfiguration::class)->settings()['revision'], 'user_id' => $this->customer->id, 'package_id' => $this->package->id, 'package_version' => 1, 'payment_method_id' => $this->method, 'purpose' => 'new', 'entry' => 'received', 'received' => '9.99', 'reference' => 'REF-123', 'sender' => 'Test Sender', 'transfer_date' => now()->subMinute()->toIso8601String(), 'internal_note' => 'Internal test note'], $extra);
    }

    private function replacementPackage(): Package
    {
        $package = Package::factory()->create(['status' => 'active', 'published_version' => 1]);
        PackageVersion::create(['package_id' => $package->id, 'number' => 1, 'definition' => $package->definition, 'actor_id' => $this->admin->id]);

        return $package;
    }

    private function createPayment(array $extra = []): int
    {
        return $this->postJson('/admin/payments', $this->data($extra))->assertCreated()->json('id');
    }

    private function act(int $id, string $action, array $extra = []): TestResponse
    {
        return $this->postJson('/admin/payments/'.$id.'/action', array_replace(['action' => $action, 'request_id' => (string) Str::uuid(), 'revision' => PaymentTransaction::findOrFail($id)->revision, 'reason' => 'Reviewed by Admin', 'customer_revision' => $this->customer->fresh()->account_revision, 'verified' => ['account' => true, 'amount' => true, 'reference' => true, 'outcome' => true]], $extra));
    }

    private function approve(int $id): void
    {
        $this->act($id, 'approve')->assertOk();
    }

    private function publishNextVersion(): void
    {
        $this->package->update(['published_version' => 2]);
        PackageVersion::create(['package_id' => $this->package->id, 'number' => 2, 'definition' => $this->package->definition, 'actor_id' => $this->admin->id]);
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('receipt.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jD1sAAAAASUVORK5CYII='));
    }

    public function test_recording_waits_for_approval_and_free_trial_cannot_create_a_paid_record(): void
    {
        $id = $this->createPayment();
        $this->assertDatabaseCount('customer_package_assignments', 0);
        $this->assertSame('pending_review', PaymentTransaction::find($id)->status);
        $this->package->update(['type' => 'trial']);
        $version = PackageVersion::where('package_id', $this->package->id)->first();
        $version->update(['definition' => [...$version->definition, 'type' => 'trial', 'price_minor' => 0]]);
        $this->postJson('/admin/payments', $this->data())->assertUnprocessable();
    }

    public function test_approval_and_subscription_are_atomic_and_replay_does_not_grant_twice(): void
    {
        $id = $this->createPayment();
        $payload = ['action' => 'approve', 'request_id' => (string) Str::uuid(), 'revision' => 1, 'customer_revision' => $this->customer->account_revision, 'verified' => ['account' => true, 'amount' => true, 'reference' => true, 'outcome' => true]];
        $this->postJson('/admin/payments/'.$id.'/action', $payload)->assertOk();
        $this->postJson('/admin/payments/'.$id.'/action', $payload)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('customer_package_assignments', 1);
        $p = PaymentTransaction::find($id);
        $this->assertSame('approved', $p->status);
        $this->assertNotNull($p->subscription_id);
        $this->act($id, 'approve')->assertUnprocessable();
    }

    public function test_checks_mismatch_stale_revision_and_duplicate_transfer_block_approval(): void
    {
        $bad = $this->createPayment(['received' => '8.00']);
        $this->act($bad, 'approve')->assertUnprocessable()->assertJsonValidationErrors('received');
        $id = $this->createPayment(['reference' => 'OTHER']);
        $this->act($id, 'approve', ['verified' => ['account' => false, 'amount' => true, 'reference' => true, 'outcome' => true]])->assertUnprocessable();
        $this->act($id, 'approve', ['revision' => 99])->assertConflict();
        $this->act($id, 'approve', ['customer_revision' => 999])->assertConflict();
        $this->approve($id);
        $other = User::factory()->create(['is_admin' => false]);
        $dup = $this->createPayment(['user_id' => $other->id, 'reference' => 'OTHER']);
        $this->act($dup, 'approve', ['customer_revision' => $other->account_revision])->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->assertDatabaseCount('customer_package_assignments', 1);
    }

    public function test_receiving_and_package_snapshots_preserve_original_terms(): void
    {
        $id = $this->createPayment();
        $config = app(PaymentConfiguration::class);
        $config->methodAction($this->admin, $this->method, ['action' => 'deactivate', 'revision' => 1, 'config_revision' => $config->settings()['revision'], 'request_id' => (string) Str::uuid(), 'reason' => 'Disable currency', 'disable_currency' => true]);
        $this->package->update(['published_version' => 2]);
        $this->getJson('/admin/payments/'.$id)->assertOk()->assertJsonPath('payment.expected_minor', 999)->assertJsonPath('payment.package.version', 1)->assertJsonPath('payment.receiving.details.identifier', 'TEST-1234');
        $this->approve($id);
        $this->assertSame(1, CustomerPackageAssignment::first()->snapshot['version']);
    }

    public function test_old_payment_quote_keeps_its_price_and_dates_but_approval_grants_latest_package_access(): void
    {
        $id = $this->createPayment();
        $before = PaymentTransaction::findOrFail($id);
        $quoted = $before->package_snapshot;
        $definition = $this->package->definition;
        $definition['validity_days'] = 90;
        $definition['price_minor'] = 1999;
        $definition['price'] = '19.99';
        $definition['features']['audio'] = true;
        $this->package->update(['published_version' => 2, 'definition' => $definition]);
        PackageVersion::create(['package_id' => $this->package->id, 'number' => 2, 'definition' => $definition, 'actor_id' => $this->admin->id]);

        $this->approve($id);
        $payment = PaymentTransaction::findOrFail($id);
        $assignment = CustomerPackageAssignment::findOrFail($payment->subscription_id);
        $this->assertSame($quoted, $payment->package_snapshot);
        $this->assertSame(999, $payment->expected_minor);
        $this->assertSame(999, $payment->received_minor);
        $this->assertSame(1, $assignment->snapshot['version']);
        $this->assertSame(2, $assignment->effectiveSnapshot()['version']);
        $this->assertTrue($assignment->effectiveSnapshot()['definition']['features']['audio']);
        $this->assertTrue($assignment->ends_at->equalTo($assignment->starts_at->addDays(30)));
        $this->getJson('/admin/users/'.$this->customer->id)->assertOk()->assertJsonPath('customer.package.version', 2);
    }

    public function test_renewal_starts_after_current_and_package_change_preserves_allowance_origin(): void
    {
        $id = $this->createPayment();
        $this->approve($id);
        $current = CustomerPackageAssignment::first();
        $renew = $this->createPayment(['purpose' => 'renewal', 'reference' => 'RENEW']);
        $this->approve($renew);
        $next = CustomerPackageAssignment::latest('id')->first();
        $this->assertTrue($next->starts_at->equalTo($current->ends_at));
        $this->postJson('/admin/payments', $this->data(['purpose' => 'renewal', 'reference' => 'THIRD']))->assertUnprocessable();
        $next->update(['closed_kind' => 'cancelled', 'closed_at' => now()]);
        $replacement = $this->replacementPackage();
        $change = $this->createPayment(['purpose' => 'change', 'package_id' => $replacement->id, 'package_version' => 1, 'reference' => 'CHANGE']);
        $this->approve($change);
        $changed = CustomerPackageAssignment::latest('id')->first();
        $this->assertSame('replaced', $current->fresh()->closed_kind);
        $this->assertTrue($changed->allowance_starts_at->equalTo($current->allowance_starts_at));
    }

    public function test_expiry_only_applies_to_unsubmitted_requests(): void
    {
        $request = $this->createPayment(['entry' => 'request', 'received' => null]);
        $submitted = $this->createPayment(['reference' => 'SUBMITTED']);
        $this->travel(25)->hours();
        $this->getJson('/admin/payments/'.$request)->assertOk()->assertJsonPath('payment.status', 'expired');
        $this->act($request, 'evidence', ['received' => '9.99'])->assertUnprocessable();
        $this->getJson('/admin/payments/'.$submitted)->assertOk()->assertJsonPath('payment.status', 'pending_review');
        $this->approve($submitted);
    }

    public function test_information_correction_and_rejection_retain_all_history(): void
    {
        $id = $this->createPayment();
        $this->act($id, 'information', ['reason' => ''])->assertUnprocessable();
        $this->act($id, 'information', ['reason' => 'Need clearer reference'])->assertOk();
        $this->act($id, 'evidence', ['received' => '9.99', 'reference' => 'CORRECTED'])->assertOk();
        $this->act($id, 'review')->assertOk();
        $this->act($id, 'reject', ['reason' => 'Transfer not received'])->assertOk();
        $this->assertDatabaseCount('customer_package_assignments', 0);
        $this->getJson('/admin/payments/'.$id)->assertOk()->assertJsonCount(5, 'history')->assertJsonPath('payment.status', 'rejected');
    }

    public function test_refunds_reserve_funds_and_cannot_exceed_original_amount(): void
    {
        $id = $this->createPayment();
        $this->approve($id);
        $this->act($id, 'refund', ['amount' => '6.00', 'destination' => 'Verified original sender', 'access_action' => 'keep'])->assertOk();
        $refund = PaymentRefund::first();
        $this->act($id, 'refund', ['amount' => '4.00', 'destination' => 'Original sender'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->act($id, 'refund_complete', ['refund_id' => $refund->id, 'confirmed' => false, 'reference' => 'RETURN', 'transfer_date' => now()->toIso8601String()])->assertUnprocessable();
        $this->act($id, 'refund_complete', ['refund_id' => $refund->id, 'confirmed' => true, 'reference' => 'RETURN', 'transfer_date' => now()->toIso8601String(), 'access_action' => 'keep'])->assertOk();
        $this->getJson('/admin/payments/'.$id)->assertJsonPath('payment.refunds_summary.completed', 600)->assertJsonPath('payment.refunds_summary.remaining', 399)->assertJsonPath('payment.refunds_summary.state', 'partial');
        $this->act($id, 'refund_complete', ['refund_id' => $refund->id, 'confirmed' => true, 'reference' => 'RETURN', 'transfer_date' => now()->toIso8601String()])->assertUnprocessable();
    }

    public function test_refund_access_change_only_affects_linked_subscription(): void
    {
        $id = $this->createPayment();
        $this->approve($id);
        $this->act($id, 'refund', ['amount' => '9.99', 'destination' => 'Verified sender'])->assertOk();
        $refund = PaymentRefund::first();
        $this->act($id, 'refund_complete', ['refund_id' => $refund->id, 'confirmed' => true, 'reference' => 'REFUND', 'transfer_date' => now()->toIso8601String(), 'access_action' => 'end'])->assertOk();
        $this->assertSame('ended', CustomerPackageAssignment::first()->closed_kind);
        $this->assertSame('approved', PaymentTransaction::find($id)->status);
    }

    public function test_rejected_refund_releases_reservation_and_never_creates_access(): void
    {
        $id = $this->createPayment();
        $this->act($id, 'refund', ['amount' => '1', 'destination' => 'Sender'])->assertUnprocessable();
        $this->approve($id);
        $this->act($id, 'refund', ['amount' => '9.99', 'destination' => 'Sender'])->assertOk();
        $refund = PaymentRefund::first();
        $this->act($id, 'refund_reject', ['refund_id' => $refund->id])->assertOk();
        $this->getJson('/admin/payments/'.$id)->assertJsonPath('payment.refunds_summary.remaining', 999)->assertJsonPath('payment.refunds_summary.reserved', 0);
    }

    public function test_receipts_are_private_scoped_and_duplicate_evidence_is_detected(): void
    {
        $config = app(PaymentConfiguration::class);
        $rules = $config->settings()['rules'];
        $rules['evidence_required'] = true;
        $config->saveRules($this->admin, ['rules' => $rules, 'config_revision' => $config->settings()['revision'], 'request_id' => (string) Str::uuid()]);
        $this->postJson('/admin/payments', $this->data())->assertUnprocessable()->assertJsonValidationErrors('receipt');
        $id = $this->post('/admin/payments', $this->data(['receipt' => $this->image()]), ['Accept' => 'application/json'])->assertCreated()->json('id');
        $receipt = PaymentReceipt::first();
        $this->get('/admin/payments/'.$id.'/receipts/'.$receipt->id)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $other = $this->createPayment(['receipt' => $this->image(), 'reference' => 'OTHER']);
        $this->get('/admin/payments/'.$other.'/receipts/'.$receipt->id)->assertNotFound();
        $this->approve($id);
        $this->act($other, 'approve')->assertUnprocessable();
        auth('admin')->logout();
        $this->getJson('/admin/payments/'.$id.'/receipts/'.$receipt->id)->assertUnauthorized();
    }

    public function test_configuration_revision_currency_and_suspended_customer_are_checked(): void
    {
        $this->postJson('/admin/payments', $this->data(['config_revision' => 0]))->assertConflict();
        $this->customer->forceFill(['account_status' => 'suspended'])->save();
        $this->postJson('/admin/payments', $this->data())->assertUnprocessable();
        $this->customer->forceFill(['account_status' => 'active'])->save();
        $this->package->update(['definition' => [...$this->package->definition, 'currency' => 'MMK']]);
        PackageVersion::first()->update(['definition' => $this->package->definition]);
        $this->postJson('/admin/payments', $this->data())->assertUnprocessable();
    }

    public function test_receiving_fields_are_encrypted_and_list_does_not_expose_receipts_or_transfer_data(): void
    {
        $id = $this->createPayment();
        $raw = DB::table('payment_transactions')->where('id', $id)->first();
        $this->assertStringNotContainsString('TEST-1234', $raw->receiving_snapshot);
        $this->assertStringNotContainsString('REF-123', $raw->transfer);
        $this->getJson('/admin/payments')->assertOk()->assertJsonMissingPath('items.0.transfer')->assertJsonMissingPath('items.0.receiving')->assertJsonPath('items.0.method.masked_identifier', '******1234');
        $this->getJson('/admin/payments?q=REF-123')->assertJsonCount(1, 'items');
        $this->getJson('/admin/payments?currency=THB')->assertJsonCount(0, 'items');
    }

    public function test_only_admin_can_access_records_and_changes_require_csrf(): void
    {
        $id = $this->createPayment();
        auth('admin')->logout();
        $this->actingAs($this->customer, 'web')->getJson('/admin/payments')->assertUnauthorized();
        $this->getJson('/admin/payments/'.$id)->assertUnauthorized();
        $this->patchJson('/admin/payments/'.$id, $this->data(['revision' => 1, 'reason' => 'Unauthorized edit']))->assertUnauthorized();
        $this->actingAs($this->customer, 'admin')->getJson('/admin/payments')->assertForbidden();
        $this->patchJson('/admin/payments/'.$id, $this->data(['revision' => 1, 'reason' => 'Unauthorized edit']))->assertForbidden();
        $this->actingAs($this->admin, 'admin');
        $this->app['env'] = 'local';
        $this->postJson('/admin/payments', $this->data())->assertStatus(419);
        $this->patchJson('/admin/payments/'.$id, $this->data(['revision' => 1, 'reason' => 'No CSRF token']))->assertStatus(419);
    }

    public function test_all_supported_currencies_keep_separate_approved_totals(): void
    {
        $configuration = app(PaymentConfiguration::class);
        foreach (['MMK' => 1000000, 'THB' => 29900, 'USD' => 999] as $currency => $minor) {
            $method = $this->method;
            if ($currency !== 'USD') {
                $method = $configuration->saveMethod($this->admin, [
                    'name' => 'Receiving '.$currency, 'provider' => 'Provider '.$currency, 'type' => 'bank', 'currency' => $currency, 'status' => 'active', 'sort_order' => 0,
                    'details' => ['holder' => 'Example', 'identifier' => 'TEST-'.$currency, 'branch' => '', 'routing' => ''],
                    'instructions' => ['en' => 'Transfer exact amount.', 'my' => '', 'th' => ''],
                    'make_default' => true, 'enable_currency' => true, 'remove_qr' => false,
                    'config_revision' => $configuration->settings()['revision'], 'request_id' => (string) Str::uuid(),
                ])['id'];
            }
            $customer = User::factory()->create(['is_admin' => false]);
            $package = Package::factory()->create(['status' => 'active', 'published_version' => 1]);
            $package->update(['definition' => [...$package->definition, 'currency' => $currency, 'price_minor' => $minor]]);
            PackageVersion::create(['package_id' => $package->id, 'number' => 1, 'definition' => $package->definition]);
            $id = $this->createPayment(['user_id' => $customer->id, 'package_id' => $package->id, 'payment_method_id' => $method, 'received' => number_format($minor / 100, 2, '.', ''), 'reference' => 'REF-'.$currency]);
            $this->act($id, 'approve', ['customer_revision' => $customer->account_revision])->assertOk();
        }
        $this->getJson('/admin/payments')->assertOk()->assertJsonPath('amounts.MMK.approved', 1000000)->assertJsonPath('amounts.THB.approved', 29900)->assertJsonPath('amounts.USD.approved', 999);
    }

    public function test_failed_approval_rolls_back_access_and_financial_status_together(): void
    {
        $id = $this->createPayment();
        PaymentEvent::creating(function (PaymentEvent $event): void {
            if ($event->action === 'approve') {
                throw new \RuntimeException('Simulated audit storage failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->act($id, 'approve');
            $this->fail('Expected approval storage failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated audit storage failure', $error->getMessage());
        } finally {
            PaymentEvent::flushEventListeners();
        }
        $this->assertDatabaseCount('customer_package_assignments', 0);
        $this->assertSame('pending_review', PaymentTransaction::findOrFail($id)->status);
        $this->assertSame(1, $this->customer->fresh()->account_revision);
    }

    public function test_failed_record_does_not_leave_private_receipt_files(): void
    {
        PaymentEvent::creating(function (): void {
            throw new \RuntimeException('Simulated record failure');
        });
        $this->withoutExceptionHandling();
        try {
            $this->post('/admin/payments', $this->data(['receipt' => $this->image()]), ['Accept' => 'application/json']);
            $this->fail('Expected record failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated record failure', $error->getMessage());
        } finally {
            PaymentEvent::flushEventListeners();
        }
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('payment-receipts'));
    }

    public function test_refund_never_ends_a_replacement_subscription(): void
    {
        $id = $this->createPayment();
        $this->approve($id);
        $replacement = $this->replacementPackage();
        $change = $this->createPayment(['purpose' => 'change', 'package_id' => $replacement->id, 'package_version' => 1, 'reference' => 'CHANGED']);
        $this->approve($change);
        $current = CustomerPackageAssignment::latest('id')->first();
        $this->act($id, 'refund', ['amount' => '9.99', 'destination' => 'Verified sender'])->assertOk();
        $refund = PaymentRefund::first();
        $this->act($id, 'refund_complete', ['refund_id' => $refund->id, 'confirmed' => true, 'reference' => 'RETURN', 'transfer_date' => now()->toIso8601String(), 'access_action' => 'end'])->assertUnprocessable()->assertJsonValidationErrors('access_action');
        $this->assertSame('active', $current->fresh()->status());
        $this->assertSame('requested', $refund->fresh()->status);
    }

    public function test_browser_multipart_confirmation_and_money_precision_are_validated(): void
    {
        $data = $this->data(['receipt' => $this->image()]);
        $response = $this->post('/admin/payments', $data, ['Accept' => 'application/json'])->assertCreated();
        $id = $response->json('id');
        $this->post('/admin/payments/'.$id.'/action', ['action' => 'approve', 'revision' => 1, 'request_id' => (string) Str::uuid(), 'customer_revision' => 1, 'verified' => json_encode(['account' => true, 'amount' => true, 'reference' => true, 'outcome' => true])], ['Accept' => 'application/json'])->assertOk();
        foreach (['-1', '1.001', '1e3', '9,99', '100000000000'] as $amount) {
            $this->postJson('/admin/payments', $this->data(['received' => $amount]))->assertUnprocessable();
        }
    }

    public function test_page_uses_the_admin_frame_and_form_routes(): void
    {
        $this->get('/admin/payments')->assertOk()->assertInertia(fn ($page) => $page->component('admin/payments')->where('adminLinks.payments', '/admin/payments')->where('transactions.endpoints.options', '/admin/payments/options'));
        $this->getJson('/admin/payments/options')->assertOk()->assertJsonPath('config_revision', 2);
    }

    public function test_partial_refund_reference_cannot_be_recorded_twice_and_filters_match_refund_state(): void
    {
        $id = $this->createPayment();
        $this->approve($id);
        $this->act($id, 'refund', ['amount' => '6.00', 'destination' => 'Verified sender'])->assertOk();
        $first = PaymentRefund::first();
        $this->getJson('/admin/payments?tab=refunds&refund_status=pending')->assertJsonPath('pagination.total', 1);
        $this->act($id, 'refund_complete', ['refund_id' => $first->id, 'confirmed' => true, 'reference' => 'RETURN-ONE', 'transfer_date' => now()->toIso8601String()])->assertOk();
        $this->getJson('/admin/payments?tab=refunds&refund_status=pending')->assertJsonPath('pagination.total', 0);
        $this->getJson('/admin/payments?tab=refunds&refund_status=completed')->assertJsonPath('pagination.total', 1);
        $this->act($id, 'refund', ['amount' => '3.99', 'destination' => 'Verified sender'])->assertOk();
        $second = PaymentRefund::latest('id')->first();
        $this->act($id, 'refund_complete', ['refund_id' => $second->id, 'confirmed' => true, 'reference' => 'return-one', 'transfer_date' => now()->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->assertSame('requested', $second->fresh()->status);
    }

    public function test_receipt_file_validation_and_normalized_reference_search(): void
    {
        $this->post('/admin/payments', $this->data(['receipt' => UploadedFile::fake()->createWithContent('receipt.png', '<?php echo 1;')]), ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('receipt');
        $this->post('/admin/payments', $this->data(['receipt' => UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf')]), ['Accept' => 'application/json'])->assertUnprocessable();
        $id = $this->createPayment(['reference' => ' AB 123 ']);
        $this->getJson('/admin/payments?q=ab123')->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $id);
    }

    public function test_waiting_sort_prioritizes_unresolved_transfers(): void
    {
        $approved = $this->createPayment();
        $this->approve($approved);
        $this->travel(1)->hours();
        $customer = User::factory()->create(['is_admin' => false]);
        $pending = $this->createPayment(['user_id' => $customer->id, 'reference' => 'WAITING']);
        $this->getJson('/admin/payments?sort=waiting')->assertOk()->assertJsonPath('items.0.id', $pending);
        $this->getJson('/admin/payments?sort=oldest')->assertOk()->assertJsonPath('items.0.id', $approved);
    }

    public function test_corrected_evidence_preserves_each_encrypted_submission_snapshot(): void
    {
        $id = $this->createPayment();
        $this->act($id, 'information', ['reason' => 'Correct the transfer reference'])->assertOk();
        $this->act($id, 'evidence', ['received' => '9.99', 'reference' => 'CORRECT-REF', 'sender' => 'Corrected sender'])->assertOk();
        $this->getJson('/admin/payments/'.$id)->assertOk()
            ->assertJsonPath('history.0.metadata.submission.reference', 'CORRECT-REF')
            ->assertJsonPath('history.2.metadata.submission.reference', 'REF-123');
        $events = DB::table('payment_events')->where('payment_transaction_id', $id)->pluck('metadata')->join('');
        $this->assertStringNotContainsString('REF-123', $events);
        $this->assertStringNotContainsString('CORRECT-REF', $events);
    }

    public function test_recording_purposes_follow_customer_access_and_selected_package(): void
    {
        $customerPath = 'customers.0.purposes.'.$this->package->id;
        $this->getJson('/admin/payments/options')->assertOk()->assertJsonPath($customerPath, ['new']);
        $this->postJson('/admin/payments', $this->data(['purpose' => 'renewal']))->assertUnprocessable()->assertJsonValidationErrors('purpose');
        $this->assertDatabaseCount('payment_transactions', 0);

        $id = $this->createPayment();
        $this->approve($id);
        $this->getJson('/admin/payments/options')->assertOk()->assertJsonPath($customerPath, ['renewal']);
        $this->postJson('/admin/payments', $this->data(['purpose' => 'new', 'reference' => 'WRONG-NEW']))->assertUnprocessable()->assertJsonValidationErrors('purpose');
        $this->postJson('/admin/payments', $this->data(['purpose' => 'change', 'reference' => 'WRONG-CHANGE']))->assertUnprocessable()->assertJsonValidationErrors('purpose');

        $other = Package::factory()->create(['status' => 'active', 'published_version' => 1]);
        PackageVersion::create(['package_id' => $other->id, 'number' => 1, 'definition' => $other->definition, 'actor_id' => $this->admin->id]);
        $this->getJson('/admin/payments/options')->assertOk()->assertJsonPath('customers.0.purposes.'.$other->id, ['change', 'renewal']);
        $change = $this->createPayment(['package_id' => $other->id, 'purpose' => 'change', 'reference' => 'OTHER-PACKAGE']);
        $this->assertSame('pending_review', PaymentTransaction::find($change)->status);
        $this->assertDatabaseCount('customer_package_assignments', 1);

        $this->publishNextVersion();
        $this->getJson('/admin/payments/options')->assertOk()->assertJsonPath($customerPath, ['renewal']);
    }

    public function test_scheduled_and_expired_periods_have_contextual_recording_purposes(): void
    {
        $id = $this->createPayment();
        $this->approve($id);
        $renewal = $this->createPayment(['purpose' => 'renewal', 'reference' => 'NEXT']);
        $this->approve($renewal);
        $this->getJson('/admin/payments/options')->assertOk()
            ->assertJsonPath('customers.0.scheduled', true)
            ->assertJsonPath('customers.0.purposes.'.$this->package->id, []);
        $this->postJson('/admin/payments', $this->data(['purpose' => 'renewal', 'reference' => 'EXTRA']))->assertUnprocessable()->assertJsonValidationErrors('purpose');

        $this->travel(61)->days();
        $this->getJson('/admin/payments/options')->assertOk()
            ->assertJsonPath('customers.0.scheduled', false)
            ->assertJsonPath('customers.0.purposes.'.$this->package->id, ['renewal']);
        $renewed = $this->createPayment(['purpose' => 'renewal', 'reference' => 'EXPIRED-RENEWAL']);
        $this->approve($renewed);
        $assignment = CustomerPackageAssignment::latest('id')->first();
        $this->assertTrue($assignment->starts_at->equalTo(now()));
    }

    public function test_pending_payment_purpose_can_be_corrected_without_granting_access_until_approval(): void
    {
        $first = $this->createPayment();
        $this->approve($first);
        $replacement = $this->replacementPackage();
        $id = $this->createPayment(['package_id' => $replacement->id, 'package_version' => 1, 'purpose' => 'renewal', 'reference' => 'UPGRADE']);
        $this->act($id, 'review')->assertOk();
        $revision = PaymentTransaction::find($id)->revision;
        $payload = $this->data(['revision' => $revision, 'package_id' => $replacement->id, 'package_version' => 1, 'purpose' => 'change', 'reference' => 'CORRECTED-REF', 'reason' => 'Start Pro immediately instead of scheduling it']);
        $this->patchJson('/admin/payments/'.$id, $payload)->assertOk()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('payment_transactions', 2);
        $this->assertDatabaseCount('customer_package_assignments', 1);
        $payment = PaymentTransaction::find($id);
        $this->assertSame('change', $payment->purpose);
        $this->assertSame('pending_review', $payment->status);
        $this->assertNull($payment->reviewed_by);
        $this->assertNull($payment->subscription_id);
        $this->assertSame($revision + 1, $payment->revision);
        $event = PaymentEvent::where('payment_transaction_id', $id)->where('action', 'edit')->firstOrFail();
        $this->assertSame($payload['reason'], $event->metadata['reason']);
        $this->assertStringNotContainsString('CORRECTED-REF', DB::table('payment_events')->where('id', $event->id)->value('metadata'));
        $details = $this->getJson('/admin/payments/'.$id)->assertOk();
        $corrections = collect($details->json('history.0.metadata.corrections'))->keyBy('field');
        $this->assertSame('renewal', $corrections['purpose']['before']);
        $this->assertSame('change', $corrections['purpose']['after']);
        $this->patchJson('/admin/payments/'.$id, $payload)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(1, PaymentEvent::where('payment_transaction_id', $id)->where('action', 'edit')->count());
        $this->approve($id);
        $this->assertDatabaseCount('customer_package_assignments', 2);
        $this->assertSame('replaced', CustomerPackageAssignment::first()->closed_kind);
    }

    public function test_payment_corrections_validate_state_revision_reason_and_customer(): void
    {
        $id = $this->createPayment();
        $payload = $this->data(['revision' => 1, 'reason' => 'Correct transfer details']);
        $this->patchJson('/admin/payments/'.$id, [...$payload, 'revision' => 999])->assertConflict();
        $this->patchJson('/admin/payments/'.$id, [...$payload, 'reason' => ' '])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $other = User::factory()->create(['is_admin' => false]);
        $this->patchJson('/admin/payments/'.$id, [...$payload, 'user_id' => $other->id])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->patchJson('/admin/payments/'.$id, [...$payload, 'entry' => 'request'])->assertUnprocessable()->assertJsonValidationErrors('entry');
        $this->assertSame(1, PaymentTransaction::find($id)->revision);
        $this->approve($id);
        $this->patchJson('/admin/payments/'.$id, [...$payload, 'revision' => 2])->assertUnprocessable()->assertJsonValidationErrors('action');
        $this->assertDatabaseCount('customer_package_assignments', 1);
        $this->getJson('/admin/payments/'.$id)->assertOk()->assertJsonMissingExact(['edit']);
    }

    public function test_correcting_package_and_transfer_preserves_receipts_and_saved_terms(): void
    {
        $id = $this->createPayment(['receipt' => $this->image()]);
        $this->publishNextVersion();
        $options = $this->getJson('/admin/payments/options?payment_id='.$id)->assertOk();
        $this->assertSame(1, collect($options->json('packages'))->firstWhere('id', $this->package->id)['version']);
        $this->assertSame($this->customer->id, $options->json('customers.0.id'));

        $payload = $this->data(['revision' => 1, 'reason' => 'Correct spelling', 'sender' => 'Corrected Sender']);
        $this->post('/admin/payments/'.$id, [...$payload, '_method' => 'PATCH'], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(1, PaymentTransaction::find($id)->package_snapshot['version']);
        $this->assertDatabaseCount('payment_receipts', 1);

        $other = Package::factory()->create(['status' => 'active', 'published_version' => 1]);
        PackageVersion::create(['package_id' => $other->id, 'number' => 1, 'definition' => $other->definition, 'actor_id' => $this->admin->id]);
        $this->post('/admin/payments/'.$id, $this->data(['_method' => 'PATCH', 'revision' => 2, 'reason' => 'Wrong package selected', 'package_id' => $other->id, 'receipt' => $this->image()]), ['Accept' => 'application/json'])->assertOk();
        $this->assertSame($other->id, PaymentTransaction::find($id)->package_snapshot['id']);
        $this->assertDatabaseCount('payment_receipts', 2);
        $this->assertDatabaseCount('customer_package_assignments', 0);
    }

    public function test_unpaid_request_can_be_corrected_into_a_received_transfer_and_closed_payments_cannot_be_edited(): void
    {
        $id = $this->createPayment(['entry' => 'request']);
        $this->getJson('/admin/payments/'.$id)->assertOk()->assertJsonPath('payment.actions.2', 'edit');
        $this->patchJson('/admin/payments/'.$id, $this->data(['revision' => 1, 'reason' => 'Payment has arrived']))->assertOk();
        $this->assertSame('pending_review', PaymentTransaction::find($id)->status);
        $this->assertNotNull(PaymentTransaction::find($id)->submitted_at);
        $this->act($id, 'reject')->assertOk();
        $this->patchJson('/admin/payments/'.$id, $this->data(['revision' => 3, 'reason' => 'Attempt to reopen']))->assertUnprocessable();
        $this->assertDatabaseCount('customer_package_assignments', 0);
    }

    public function test_currency_correction_requires_new_evidence_and_is_atomic_on_validation_failure(): void
    {
        $id = $this->createPayment(['receipt' => $this->image()]);
        $config = app(PaymentConfiguration::class);
        $method = $config->saveMethod($this->admin, ['name' => 'Kyat receiving', 'provider' => 'Example Myanmar', 'type' => 'bank', 'currency' => 'MMK', 'status' => 'active', 'sort_order' => 0, 'details' => ['holder' => 'Test Business', 'identifier' => 'KYAT-1234', 'branch' => '', 'routing' => ''], 'instructions' => ['en' => '', 'my' => '', 'th' => ''], 'make_default' => true, 'enable_currency' => true, 'remove_qr' => false, 'config_revision' => $config->settings()['revision'], 'request_id' => (string) Str::uuid()])['id'];
        $other = Package::factory()->create(['status' => 'active', 'published_version' => 1, 'definition' => [...$this->package->definition, 'currency' => 'MMK', 'price_minor' => 1000000]]);
        PackageVersion::create(['package_id' => $other->id, 'number' => 1, 'definition' => $other->definition, 'actor_id' => $this->admin->id]);
        $payload = $this->data(['revision' => 1, 'package_id' => $other->id, 'payment_method_id' => $method, 'received' => '10000', 'reason' => 'Correct currency and package']);
        $this->patchJson('/admin/payments/'.$id, $payload)->assertUnprocessable()->assertJsonValidationErrors('receipt');
        $this->assertSame('USD', PaymentTransaction::find($id)->currency);
        $this->assertSame(1, PaymentTransaction::find($id)->revision);
        $this->post('/admin/payments/'.$id, [...$payload, '_method' => 'PATCH', 'receipt' => $this->image()], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('MMK', PaymentTransaction::find($id)->currency);
        $this->assertDatabaseCount('payment_receipts', 2);
        $this->assertDatabaseCount('customer_package_assignments', 0);
    }

    public function test_new_payment_accepts_empty_edit_only_fields_from_the_browser(): void
    {
        foreach (['received', 'request'] as $entry) {
            foreach (['', null, '   '] as $value) {
                $response = $this->post('/admin/payments', $this->data(['entry' => $entry, 'reason' => $value, 'revision' => $value]), ['Accept' => 'application/json'])->assertCreated();
                $payment = PaymentTransaction::findOrFail($response->json('id'));
                $this->assertSame($entry === 'received' ? 'pending_review' : 'awaiting_payment', $payment->status);
                $this->assertSame(1, $payment->revision);
                $this->assertArrayNotHasKey('reason', PaymentEvent::where('payment_transaction_id', $payment->id)->firstOrFail()->metadata);
            }
        }
        $this->assertDatabaseCount('customer_package_assignments', 0);
    }

    public function test_replacing_duplicate_receipt_removes_old_evidence_from_approval_checks(): void
    {
        $approved = $this->createPayment(['receipt' => $this->image()]);
        $this->approve($approved);
        $other = User::factory()->create(['is_admin' => false]);
        $pending = $this->createPayment(['user_id' => $other->id, 'reference' => 'DIFFERENT-TRANSFER', 'receipt' => $this->image()]);
        $this->getJson('/admin/payments/'.$pending)->assertOk()->assertJsonFragment(['approved_duplicate']);
        $this->travel(1)->seconds();
        $original = $this->image();
        $replacement = UploadedFile::fake()->createWithContent('corrected.png', file_get_contents($original->getRealPath()).'corrected-transfer');
        $this->post('/admin/payments/'.$pending, $this->data(['_method' => 'PATCH', 'user_id' => $other->id, 'revision' => 1, 'reason' => 'Replace wrong receipt with the correct transfer proof', 'reference' => 'CORRECTED-TRANSFER', 'receipt' => $replacement]), ['Accept' => 'application/json'])->assertOk();
        $details = $this->getJson('/admin/payments/'.$pending)->assertOk();
        $this->assertNotContains('approved_duplicate', $details->json('warnings'));
        $this->assertNotContains('possible_duplicate', $details->json('warnings'));
        $this->assertSame(2, PaymentReceipt::where('payment_transaction_id', $pending)->count());
        $this->act($pending, 'approve', ['customer_revision' => $other->account_revision])->assertOk();
        $this->assertSame('approved', PaymentTransaction::find($pending)->status);
    }

    public function test_only_current_receipts_on_other_payments_reserve_duplicate_evidence(): void
    {
        $id = $this->createPayment(['receipt' => $this->image()]);
        $original = $this->image();
        $new = UploadedFile::fake()->createWithContent('current.png', file_get_contents($original->getRealPath()).'different-current');
        $this->post('/admin/payments/'.$id, $this->data(['_method' => 'PATCH', 'revision' => 1, 'reason' => 'Correct evidence', 'reference' => 'CURRENT-PROOF', 'receipt' => $new]), ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(1, PaymentReceipt::where('payment_transaction_id', $id)->where('is_current', true)->count());
        $this->assertSame(1, PaymentReceipt::where('payment_transaction_id', $id)->where('is_current', false)->count());
        $this->approve($id);

        $other = User::factory()->create(['is_admin' => false]);
        $retired = $this->createPayment(['user_id' => $other->id, 'reference' => 'RETIRED-ONLY', 'receipt' => $this->image()]);
        $this->assertNotContains('approved_duplicate', $this->getJson('/admin/payments/'.$retired)->assertOk()->json('warnings'));
        $this->act($retired, 'approve', ['customer_revision' => $other->account_revision])->assertOk();

        $third = User::factory()->create(['is_admin' => false]);
        $duplicate = UploadedFile::fake()->createWithContent('duplicate-current.png', file_get_contents($original->getRealPath()).'different-current');
        $active = $this->createPayment(['user_id' => $third->id, 'reference' => 'UNIQUE-REFERENCE', 'receipt' => $duplicate]);
        $this->getJson('/admin/payments/'.$active)->assertOk()->assertJsonFragment(['approved_duplicate']);
        $this->act($active, 'approve', ['customer_revision' => $third->account_revision])->assertUnprocessable()->assertJsonValidationErrors('reference');
    }

    public function test_legacy_receipt_backfill_keeps_newest_submission_current_and_preserves_all_files(): void
    {
        $id = $this->createPayment(['receipt' => $this->image()]);
        $first = PaymentReceipt::where('payment_transaction_id', $id)->firstOrFail();
        $this->travel(5)->seconds();
        $original = $this->image();
        $new = UploadedFile::fake()->createWithContent('newest.png', file_get_contents($original->getRealPath()).'latest');
        $this->post('/admin/payments/'.$id, $this->data(['_method' => 'PATCH', 'revision' => 1, 'reason' => 'New proof', 'receipt' => $new]), ['Accept' => 'application/json'])->assertOk();
        $current = PaymentReceipt::where('payment_transaction_id', $id)->where('is_current', true)->firstOrFail();
        $migration = require database_path('migrations/2026_11_12_065428_add_current_evidence_to_payment_receipts_table.php');
        $migration->down();
        $migration->up();
        $this->assertFalse($first->fresh()->is_current);
        $this->assertTrue($current->fresh()->is_current);
        $this->assertSame(2, PaymentReceipt::where('payment_transaction_id', $id)->count());
        $details = $this->getJson('/admin/payments/'.$id)->assertOk();
        $this->assertSame($current->id, collect($details->json('receipts'))->firstWhere('current', true)['id']);
    }

    public function test_end_access_keeps_customer_searchable_and_payment_history_and_allows_paid_reactivation(): void
    {
        $id = $this->createPayment();
        $this->approve($id);
        $assignment = CustomerPackageAssignment::firstOrFail();
        $this->getJson('/admin/subscriptions')->assertOk()->assertJsonPath('items.0.actions', ['end']);
        $this->getJson('/admin/subscriptions/'.$assignment->id)->assertOk()
            ->assertJsonPath('subscription.actions', ['end'])
            ->assertJsonPath('payments_url', route('admin.payments.index', ['q' => $this->customer->email], absolute: false));
        $this->postJson('/admin/subscriptions/'.$assignment->id.'/action', ['action' => 'end', 'revision' => $this->customer->fresh()->account_revision,
            'request_id' => (string) Str::uuid(), 'reason' => 'Stop current access', 'confirm_end' => true])->assertOk();
        $this->assertSame('ended', $assignment->fresh()->status());
        $this->assertSame('active', $this->customer->fresh()->account_status);
        $this->assertSame('approved', PaymentTransaction::find($id)->status);
        $this->getJson('/admin/payments?q='.rawurlencode($this->customer->email))->assertOk()->assertJsonPath('items.0.id', $id);
        $this->getJson('/admin/payments/options?q='.rawurlencode($this->customer->email))->assertOk()
            ->assertJsonPath('customers.0.id', $this->customer->id)
            ->assertJsonPath('customers.0.current.status', 'ended')
            ->assertJsonPath('customers.0.purposes.'.$this->package->id, ['renewal']);
        $this->getJson('/admin/subscriptions/'.$assignment->id)->assertOk()->assertJsonPath('subscription.actions', []);
        $newPayment = $this->createPayment(['purpose' => 'renewal', 'reference' => 'REACTIVATE']);
        $this->approve($newPayment);
        $newPeriod = CustomerPackageAssignment::latest('id')->firstOrFail();
        $this->assertSame('active', $newPeriod->status());
        $this->assertTrue($newPeriod->starts_at->equalTo(now()));
        $this->assertSame('ended', $assignment->fresh()->status());
        $this->assertDatabaseCount('payment_transactions', 2);
    }

    public function test_ending_current_access_preserves_scheduled_paid_period_and_blocks_overlapping_payment_until_cancelled(): void
    {
        $id = $this->createPayment();
        $this->approve($id);
        $assignment = CustomerPackageAssignment::firstOrFail();
        $renewal = $this->createPayment(['purpose' => 'renewal', 'reference' => 'PREPAID-NEXT']);
        $this->approve($renewal);
        $scheduled = CustomerPackageAssignment::latest('id')->firstOrFail();
        $this->postJson('/admin/subscriptions/'.$assignment->id.'/action', ['action' => 'end', 'revision' => $this->customer->fresh()->account_revision,
            'request_id' => (string) Str::uuid(), 'reason' => 'Stop current period only', 'confirm_end' => true])->assertOk();
        $this->assertSame('upcoming', $scheduled->fresh()->status());
        $this->assertSame('approved', PaymentTransaction::find($renewal)->status);
        $this->getJson('/admin/payments/options?q='.rawurlencode($this->customer->email))->assertOk()
            ->assertJsonPath('customers.0.id', $this->customer->id)
            ->assertJsonPath('customers.0.scheduled', true)
            ->assertJsonPath('customers.0.purposes.'.$this->package->id, []);
        $this->postJson('/admin/payments', $this->data(['purpose' => 'renewal', 'reference' => 'OVERLAP']))->assertUnprocessable()->assertJsonValidationErrors('purpose');
        $this->getJson('/admin/subscriptions/'.$scheduled->id)->assertOk()->assertJsonPath('subscription.actions', ['cancel']);
        $this->postJson('/admin/subscriptions/'.$scheduled->id.'/action', ['action' => 'cancel', 'revision' => $this->customer->fresh()->account_revision,
            'request_id' => (string) Str::uuid(), 'reason' => 'Cancel future schedule'])->assertOk();
        $this->getJson('/admin/payments/options?q='.rawurlencode($this->customer->email))->assertOk()->assertJsonPath('customers.0.scheduled', false);
        $this->createPayment(['purpose' => 'renewal', 'reference' => 'AFTER-CANCELLATION']);
        $this->assertSame('approved', PaymentTransaction::find($renewal)->status);
    }

    public function test_information_requests_reuse_support_thread_without_granting_access(): void
    {
        $id = $this->createPayment();
        $requestId = (string) Str::uuid();
        $revision = PaymentTransaction::findOrFail($id)->revision;
        $this->act($id, 'information', ['request_id' => $requestId, 'revision' => $revision, 'reason' => 'Please send a clearer receipt'])->assertOk();
        $thread = DB::table('support_conversations')->where('related_type', 'payment')->where('related_id', $id)->first();
        $this->assertNotNull($thread);
        $this->assertSame('waiting_customer', $thread->status);
        $this->assertDatabaseHas('support_messages', ['conversation_id' => $thread->id, 'body' => 'Please send a clearer receipt', 'kind' => 'reply']);
        $this->act($id, 'information', ['request_id' => $requestId, 'revision' => $revision, 'reason' => 'Please send a clearer receipt'])->assertOk();
        $this->assertDatabaseCount('support_conversations', 1);
        $this->assertDatabaseCount('support_messages', 1);
        $this->assertDatabaseCount('customer_package_assignments', 0);
        PaymentTransaction::whereKey($id)->update(['status' => 'pending_review']);
        $this->act($id, 'information', ['reason' => 'Please confirm the sender name'])->assertOk();
        $this->assertDatabaseCount('support_conversations', 1);
        $this->assertDatabaseCount('support_messages', 2);
        $this->assertSame('needs_information', PaymentTransaction::findOrFail($id)->status);
    }
}
