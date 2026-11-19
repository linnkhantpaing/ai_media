<?php

namespace Tests\Feature;

use App\Models\CustomerPackageAssignment;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\PaymentReceipt;
use App\Models\PaymentRefund;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\PaymentConfiguration;
use App\Services\Payments;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomerBillingTest extends TestCase
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
        $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'UTC'));
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->customer = User::factory()->create(['is_admin' => false, 'managed_account' => true]);
        $this->package = Package::factory()->create(['status' => 'active', 'published_version' => 1]);
        PackageVersion::create(['package_id' => $this->package->id, 'number' => 1, 'definition' => $this->package->definition, 'actor_id' => $this->admin->id]);
        $this->method = app(PaymentConfiguration::class)->saveMethod($this->admin, ['name' => 'Test Bank', 'provider' => 'Bank', 'type' => 'bank', 'currency' => 'USD', 'status' => 'active', 'sort_order' => 0,
            'details' => ['holder' => 'Business', 'identifier' => '12345678', 'branch' => '', 'routing' => ''], 'instructions' => ['en' => 'Transfer exact amount.', 'my' => '', 'th' => ''],
            'make_default' => true, 'enable_currency' => true, 'remove_qr' => false, 'config_revision' => 0, 'request_id' => (string) Str::uuid()])['id'];
        $this->actingAs($this->customer, 'web');
    }

    private function data(array $extra = []): array
    {
        return array_replace(['request_id' => (string) Str::uuid(), 'config_revision' => app(PaymentConfiguration::class)->settings()['revision'],
            'package_id' => $this->package->id, 'package_version' => 1, 'payment_method_id' => $this->method, 'received' => '9.99', 'reference' => 'REF-123', 'sender' => 'Customer',
            'transfer_date' => now()->subMinute()->toIso8601String(), 'receipt' => $this->image()], $extra);
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('receipt.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jD1sAAAAASUVORK5CYII='));
    }

    private function submit(): int
    {
        return $this->postJson('/account/billing-api', $this->data())->assertCreated()->json('id');
    }

    public function test_submitting_is_private_idempotent_and_does_not_grant_access(): void
    {
        $data = $this->data();
        $id = $this->postJson('/account/billing-api', $data)->assertCreated()->json('id');
        $this->postJson('/account/billing-api', $data)->assertCreated()->assertJsonPath('id', $id)->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertDatabaseCount('customer_package_assignments', 0);
        $payment = PaymentTransaction::findOrFail($id);
        $this->assertSame('pending_review', $payment->status);
        $this->assertSame(999, $payment->expected_minor);
        $this->assertSame('new', $payment->purpose);
        $this->get('/account/billing')->assertOk()->assertInertia(fn (Assert $page) => $page->component('account/billing')->has('payments.data', 1)->where('counts.review', 1));
        $this->postJson('/account/billing-api', $this->data())->assertUnprocessable()->assertJsonValidationErrors('payment');
    }

    public function test_customer_cannot_forge_owner_purpose_price_or_admin_fields(): void
    {
        foreach (['user_id' => $this->admin->id, 'purpose' => 'new', 'expected_minor' => 1, 'internal_note' => 'private', 'action' => 'approve'] as $key => $value) {
            $this->postJson('/account/billing-api', $this->data([$key => $value]))->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->postJson('/account/billing-api', $this->data(['receipt' => null]))->assertUnprocessable()->assertJsonValidationErrors('receipt');
    }

    public function test_details_files_and_mutation_are_scoped_to_the_customer(): void
    {
        $id = $this->submit();
        $payment = PaymentTransaction::findOrFail($id);
        $payment->update(['internal_note' => 'Never return this private note']);
        $receipt = $payment->receipts()->firstOrFail();
        $this->getJson('/account/billing-api/'.$id)->assertOk()->assertJsonMissing(['internal_note' => 'Never return this private note'])->assertDontSee('Never return this');
        $this->get('/account/billing-api/'.$id.'/receipts/'.$receipt->id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs(User::factory()->create(['is_admin' => false]), 'web');
        $this->getJson('/account/billing-api/'.$id)->assertNotFound();
        $this->get('/account/billing-api/'.$id.'/receipts/'.$receipt->id)->assertNotFound();
        $this->postJson('/account/billing-api/'.$id, $this->data(['revision' => $payment->revision]))->assertNotFound();
    }

    public function test_replacing_receipt_keeps_only_new_evidence_current_and_preserves_admin_note(): void
    {
        $id = $this->submit();
        $payment = PaymentTransaction::findOrFail($id);
        $payment->update(['internal_note' => 'Private memo']);
        $old = $payment->receipts()->firstOrFail();
        $this->postJson('/account/billing-api/'.$id, $this->data(['revision' => $payment->revision, 'reference' => 'Corrected']))->assertOk();
        $this->assertSame('Private memo', $payment->fresh()->internal_note);
        $this->assertFalse($old->fresh()->is_current);
        $this->assertSame(1, PaymentReceipt::where('payment_transaction_id', $id)->where('is_current', true)->count());
        $this->getJson('/account/billing-api/'.$id)->assertOk()->assertJsonCount(1, 'receipts')->assertJsonPath('transfer.reference', 'Corrected');
        $this->get('/account/billing-api/'.$id.'/receipts/'.$old->id)->assertNotFound();
    }

    public function test_needs_information_resubmits_same_record_but_under_review_and_approved_are_immutable(): void
    {
        $id = $this->submit();
        $payment = PaymentTransaction::findOrFail($id);
        app(Payments::class)->act($this->admin, $id, ['action' => 'information', 'reason' => 'Please clarify the reference.', 'request_id' => (string) Str::uuid(), 'revision' => $payment->revision]);
        $this->getJson('/account/billing-api/'.$id)->assertOk()->assertJsonPath('request', 'Please clarify the reference.');
        $payment->refresh();
        $this->postJson('/account/billing-api/'.$id, $this->data(['revision' => $payment->revision, 'receipt' => null]))->assertOk();
        $this->assertDatabaseCount('payment_transactions', 1);
        foreach (['under_review', 'approved'] as $status) {
            $payment->refresh()->update(['status' => $status]);
            $this->postJson('/account/billing-api/'.$id, $this->data(['revision' => $payment->revision]))->assertUnprocessable()->assertJsonValidationErrors('payment');
        }
    }

    public function test_admin_approval_alone_activates_submitted_payment(): void
    {
        $id = $this->submit();
        $payment = PaymentTransaction::findOrFail($id);
        app(Payments::class)->act($this->admin, $id, ['action' => 'approve', 'request_id' => (string) Str::uuid(), 'revision' => $payment->revision,
            'customer_revision' => $this->customer->fresh()->account_revision, 'verified' => ['account' => true, 'amount' => true, 'reference' => true, 'outcome' => true]]);
        $this->assertSame('approved', $payment->fresh()->status);
        $this->assertSame(1, CustomerPackageAssignment::where('user_id', $this->customer->id)->count());
        $this->getJson('/account/billing-api/'.$id)->assertOk()->assertJsonPath('editable', false);
    }

    public function test_options_reject_stale_configuration_or_package_and_do_not_expose_private_details(): void
    {
        $this->getJson('/account/billing-api/options')->assertOk()->assertJsonPath('plans.0.currency', 'USD')->assertJsonPath('plans.0.purpose', 'new')->assertDontSee('qr_path')->assertDontSee('internal_label');
        $this->postJson('/account/billing-api', $this->data(['config_revision' => 999]))->assertConflict();
        $this->postJson('/account/billing-api', $this->data(['package_version' => 999]))->assertConflict();
        $this->package->update(['status' => 'inactive']);
        $this->getJson('/account/billing-api/options')->assertOk()->assertJsonCount(0, 'plans');
        $this->postJson('/account/billing-api', $this->data())->assertUnprocessable()->assertJsonValidationErrors('package_id');
    }

    public function test_expired_account_can_pay_and_refund_history_is_customer_safe(): void
    {
        CustomerPackageAssignment::create(['user_id' => $this->customer->id, 'package_id' => $this->package->id, 'package_version_id' => PackageVersion::first()->id,
            'assigned_by' => $this->admin->id, 'snapshot' => ['name' => 'Previous', 'version' => 1, 'definition' => $this->package->definition],
            'starts_at' => now()->subDays(31), 'ends_at' => now()->subDay(), 'source' => 'account_creation']);
        $this->get('/account/billing')->assertOk();
        $id = $this->submit();
        $this->assertSame('renewal', PaymentTransaction::findOrFail($id)->purpose);
        PaymentRefund::create(['payment_transaction_id' => $id, 'amount_minor' => 100, 'status' => 'completed', 'access_action' => 'keep',
            'reason' => 'Internal refund note', 'details' => ['destination' => 'Private bank details'], 'created_by' => $this->admin->id, 'completed_at' => now()]);
        $this->getJson('/account/billing-api/'.$id)->assertOk()->assertJsonPath('refunds.0.amount_minor', 100)->assertDontSee('Internal refund note')->assertDontSee('Private bank details');
        $this->get('/account/billing?tab=refunds')->assertOk()->assertInertia(fn (Assert $p) => $p->has('payments.data', 1));
    }
}
