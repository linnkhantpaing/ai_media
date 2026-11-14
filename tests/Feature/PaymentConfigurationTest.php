<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\PaymentConfigurationEvent;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodVersion;
use App\Models\User;
use App\Services\PaymentConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $base = '/admin/settings/payments';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($this->admin, 'admin');
    }

    private function revision(): int
    {
        return app(PaymentConfiguration::class)->settings()['revision'];
    }

    private function payload(array $extra = []): array
    {
        return array_replace(['name' => 'Primary receiving account', 'type' => 'bank', 'currency' => 'MMK', 'provider' => 'Example Bank', 'internal_label' => '', 'status' => 'active', 'sort_order' => 0, 'details' => ['holder' => 'Test Business', 'identifier' => '00123456789', 'branch' => '', 'routing' => ''], 'instructions' => ['en' => 'Pay the exact amount and keep the receipt.', 'my' => '', 'th' => ''], 'make_default' => true, 'enable_currency' => true, 'remove_qr' => false, 'config_revision' => $this->revision(), 'request_id' => (string) Str::uuid()], $extra);
    }

    private function createMethod(array $extra = []): int
    {
        return $this->postJson($this->base.'/methods', $this->payload($extra))->assertCreated()->json('id');
    }

    private function action(int $id, string $action, array $extra = []): TestResponse
    {
        return $this->postJson($this->base.'/methods/'.$id.'/action', ['action' => $action, 'revision' => PaymentMethod::findOrFail($id)->revision, 'config_revision' => $this->revision(), 'request_id' => (string) Str::uuid(), 'reason' => 'Reviewed by administrator', ...$extra]);
    }

    private function qr(string $name = 'qr.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jD1sAAAAASUVORK5CYII='));
    }

    public function test_empty_configuration_does_not_seed_receiving_accounts_or_enable_currencies(): void
    {
        $this->getJson($this->base)->assertOk()->assertJsonCount(0, 'methods')->assertJsonPath('settings.revision', 0)->assertJsonPath('settings.currencies.MMK.enabled', false)->assertJsonPath('settings.currencies.THB.enabled', false)->assertJsonPath('settings.currencies.USD.enabled', false)->assertJsonPath('settings.rules.evidence_required', true);
        $this->assertDatabaseCount('payment_methods', 0);
        $this->assertDatabaseCount('payment_settings', 0);
    }

    public function test_each_currency_has_independent_receiving_methods_and_defaults(): void
    {
        foreach (['MMK', 'THB', 'USD'] as $currency) {
            $id = $this->createMethod(['currency' => $currency, 'name' => $currency.' receiving account']);
            $this->assertSame($id, app(PaymentConfiguration::class)->settings()['currencies'][$currency]['default_id']);
        }
        $this->getJson($this->base)->assertOk()->assertJsonCount(3, 'methods')->assertJsonPath('summary.MMK.active', 1)->assertJsonPath('summary.THB.active', 1)->assertJsonPath('summary.USD.active', 1);
        $this->getJson($this->base.'?currency=THB')->assertOk()->assertJsonCount(1, 'methods')->assertJsonPath('methods.0.currency', 'THB');
    }

    public function test_sensitive_receiving_details_are_encrypted_and_masked_in_lists(): void
    {
        $id = $this->createMethod();
        $raw = DB::table('payment_methods')->where('id', $id)->value('details');
        $this->assertStringNotContainsString('00123456789', $raw);
        $this->assertStringNotContainsString('Test Business', $raw);
        $this->getJson($this->base)->assertJsonMissingPath('methods.0.details')->assertJsonPath('methods.0.masked_identifier', '*******6789');
        $this->getJson($this->base.'/methods/'.$id)->assertJsonPath('method.details.identifier', '00123456789');
    }

    public function test_create_is_idempotent_and_conflicting_replay_does_not_duplicate_methods(): void
    {
        $data = $this->payload();
        $this->postJson($this->base.'/methods', $data)->assertCreated();
        $this->postJson($this->base.'/methods', $data)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson($this->base.'/methods', array_replace($data, ['name' => 'Changed request']))->assertConflict();
        $this->assertDatabaseCount('payment_methods', 1);
        $this->assertDatabaseCount('payment_method_versions', 1);
    }

    public function test_invalid_currency_missing_receiving_details_and_disabled_currency_are_rejected(): void
    {
        $this->postJson($this->base.'/methods', $this->payload(['currency' => 'EUR']))->assertUnprocessable();
        $this->postJson($this->base.'/methods', $this->payload(['details' => ['holder' => '', 'identifier' => '', 'branch' => '', 'routing' => '']]))->assertUnprocessable();
        $this->postJson($this->base.'/methods', $this->payload(['enable_currency' => false]))->assertUnprocessable();
        $this->postJson($this->base.'/methods', $this->payload(['make_default' => false]))->assertUnprocessable();
        $this->assertDatabaseCount('payment_methods', 0);
    }

    public function test_replacing_a_default_requires_reason_and_updates_only_its_currency(): void
    {
        $first = $this->createMethod();
        $usd = $this->createMethod(['currency' => 'USD']);
        $second = $this->createMethod(['name' => 'Backup bank', 'make_default' => false]);
        $this->action($second, 'default', ['reason' => ''])->assertUnprocessable();
        $this->action($second, 'default')->assertOk();
        $settings = app(PaymentConfiguration::class)->settings();
        $this->assertSame($second, $settings['currencies']['MMK']['default_id']);
        $this->assertSame($usd, $settings['currencies']['USD']['default_id']);
        $this->assertSame('active', PaymentMethod::find($first)->status);
    }

    public function test_default_method_cannot_be_deactivated_without_replacement_or_disabling_currency(): void
    {
        $first = $this->createMethod();
        $backup = $this->createMethod(['make_default' => false]);
        $this->action($first, 'deactivate')->assertUnprocessable()->assertJsonValidationErrors('replacement_id');
        $this->action($first, 'deactivate', ['replacement_id' => $backup])->assertOk();
        $this->assertSame($backup, app(PaymentConfiguration::class)->settings()['currencies']['MMK']['default_id']);
        $this->assertSame('inactive', PaymentMethod::find($first)->status);
    }

    public function test_disabling_currency_deactivates_active_methods_and_preserves_versions(): void
    {
        $first = $this->createMethod();
        $backup = $this->createMethod(['make_default' => false]);
        $this->action($first, 'archive', ['disable_currency' => true])->assertOk();
        $this->assertFalse(app(PaymentConfiguration::class)->settings()['currencies']['MMK']['enabled']);
        $this->assertSame('archived', PaymentMethod::find($first)->status);
        $this->assertSame('inactive', PaymentMethod::find($backup)->status);
        $this->assertSame('active', PaymentMethodVersion::where('payment_method_id', $first)->where('number', 1)->sole()->definition['status']);
        $this->action($first, 'restore')->assertOk();
        $this->assertSame('inactive', PaymentMethod::find($first)->status);
    }

    public function test_receiving_account_edits_require_reason_and_keep_old_snapshot_unchanged(): void
    {
        $id = $this->createMethod();
        $captured = app(PaymentConfiguration::class)->capture('MMK');
        $data = $this->payload(['revision' => 1, 'details' => ['holder' => 'New Account', 'identifier' => '00987654321', 'branch' => '', 'routing' => '']]);
        $this->patchJson($this->base.'/methods/'.$id, $data)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->patchJson($this->base.'/methods/'.$id, [...$data, 'reason' => 'Receiving account changed'])->assertOk();
        $this->assertSame('00123456789', $captured['receiving']['details']['identifier']);
        $this->assertSame('00123456789', PaymentMethodVersion::where('number', 1)->sole()->definition['details']['identifier']);
        $this->assertSame('00987654321', app(PaymentConfiguration::class)->capture('MMK')['receiving']['details']['identifier']);
        $this->getJson($this->base.'/methods/'.$id)->assertOk()->assertJsonPath('history.0.metadata.reason', 'Receiving account changed');
    }

    public function test_stale_edits_are_rejected_before_changing_configuration(): void
    {
        $id = $this->createMethod();
        $this->patchJson($this->base.'/methods/'.$id, $this->payload(['revision' => 1, 'config_revision' => 0]))->assertConflict();
        $this->patchJson($this->base.'/methods/'.$id, $this->payload(['revision' => 99]))->assertConflict();
        $this->assertSame(1, PaymentMethod::find($id)->revision);
    }

    public function test_qr_upload_is_private_and_historical_images_remain_available_after_replacement(): void
    {
        $id = $this->post($this->base.'/methods', $this->payload(['qr_file' => $this->qr()]), ['Accept' => 'application/json'])->assertCreated()->json('id');
        $oldPath = PaymentMethod::find($id)->qr_path;
        $this->get($this->base.'/methods/'.$id.'/qr/1')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $data = $this->payload(['revision' => 1, 'reason' => 'Replace QR', 'qr_file' => $this->qr()]);
        $this->post($this->base.'/methods/'.$id, [...$data, '_method' => 'PATCH'], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('local')->assertExists($oldPath);
        $this->get($this->base.'/methods/'.$id.'/qr/1')->assertOk();
        $this->get($this->base.'/methods/'.$id.'/qr/2')->assertOk();
        auth('admin')->logout();
        $this->getJson($this->base.'/methods/'.$id.'/qr/1')->assertUnauthorized();
    }

    public function test_disguised_files_and_oversized_images_are_rejected(): void
    {
        $file = UploadedFile::fake()->createWithContent('fake.png', '<?php echo "not an image";');
        $this->post($this->base.'/methods', $this->payload(['qr_file' => $file]), ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('qr_file');
        $this->post($this->base.'/methods', $this->payload(['qr_file' => UploadedFile::fake()->create('large.png', 5121, 'image/png')]), ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('payment_methods', 0);
    }

    public function test_currency_setup_requires_matching_method_and_can_activate_an_inactive_default(): void
    {
        $id = $this->createMethod(['status' => 'inactive', 'enable_currency' => false, 'make_default' => false]);
        $currencies = app(PaymentConfiguration::class)->settings()['currencies'];
        $currencies['THB'] = ['enabled' => true, 'default_id' => $id];
        $this->putJson($this->base.'/currencies', ['currencies' => $currencies, 'config_revision' => $this->revision(), 'request_id' => (string) Str::uuid(), 'reason' => 'Enable payments'])->assertUnprocessable();
        $currencies['THB'] = ['enabled' => false, 'default_id' => null];
        $currencies['MMK'] = ['enabled' => true, 'default_id' => $id];
        $this->putJson($this->base.'/currencies', ['currencies' => $currencies, 'config_revision' => $this->revision(), 'request_id' => (string) Str::uuid(), 'reason' => 'Enable payments'])->assertOk();
        $this->assertSame('active', PaymentMethod::find($id)->status);
    }

    public function test_rules_are_independent_and_do_not_rewrite_captured_payment_rules(): void
    {
        $id = $this->createMethod();
        $captured = app(PaymentConfiguration::class)->capture('MMK');
        $rules = app(PaymentConfiguration::class)->settings()['rules'];
        $rules['expiry_hours'] = 6;
        $rules['reference_required'] = true;
        $this->putJson($this->base.'/rules', ['rules' => $rules, 'config_revision' => $this->revision(), 'request_id' => (string) Str::uuid()])->assertOk();
        $this->assertSame(24, $captured['rules']['expiry_hours']);
        $this->assertSame(6, app(PaymentConfiguration::class)->capture('MMK')['rules']['expiry_hours']);
        $this->assertSame(1, PaymentMethod::find($id)->revision);
    }

    public function test_wrong_currency_cannot_be_used_and_package_prices_are_not_converted(): void
    {
        $mmk = $this->createMethod();
        $usd = $this->createMethod(['currency' => 'USD']);
        $package = Package::factory()->create();
        $version = PackageVersion::create(['package_id' => $package->id, 'number' => 1, 'definition' => [...$package->definition, 'currency' => 'MMK'], 'actor_id' => $this->admin->id]);
        $this->assertSame(['MMK'], app(PaymentConfiguration::class)->availableCurrencies($version));
        $this->expectException(ValidationException::class);
        app(PaymentConfiguration::class)->capture('MMK', $usd);
    }

    public function test_customer_guard_and_untrusted_identity_cannot_manage_methods(): void
    {
        $id = $this->createMethod();
        auth('admin')->logout();
        $customer = User::factory()->create(['is_admin' => false]);
        $this->actingAs($customer, 'web')->getJson($this->base)->assertUnauthorized();
        $this->getJson($this->base.'/methods/'.$id)->assertUnauthorized();
        $this->postJson($this->base.'/methods', $this->payload())->assertUnauthorized();
        $this->actingAs($customer, 'admin')->getJson($this->base)->assertForbidden();
    }

    public function test_mixed_guards_record_the_admin_actor_and_changes_require_csrf(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $id = $this->createMethod();
        $this->assertSame($this->admin->id, PaymentConfigurationEvent::sole()->actor_id);
        $this->app->instance('env', 'production');
        $this->action($id, 'deactivate', ['disable_currency' => true])->assertStatus(419);
    }

    public function test_admin_page_uses_payment_configuration_and_navigation_is_connected(): void
    {
        $this->get($this->base)->assertOk()->assertInertia(fn ($page) => $page
            ->component('admin/payment-settings')
            ->where('adminLinks.paymentSettings', $this->base)
            ->where('payments.endpoints.store', $this->base.'/methods')
            ->where('payments.settings.revision', 0));
    }

    public function test_browser_multipart_json_fields_and_method_override_work_with_qr(): void
    {
        $data = $this->payload(['qr_file' => $this->qr()]);
        foreach (['details', 'instructions'] as $field) {
            $data[$field] = json_encode($data[$field], JSON_THROW_ON_ERROR);
        }
        foreach (['make_default', 'enable_currency', 'remove_qr'] as $field) {
            $data[$field] = $data[$field] ? '1' : '0';
        }
        $id = $this->post($this->base.'/methods', $data, ['Accept' => 'application/json'])->assertCreated()->json('id');
        $this->getJson($this->base.'/methods/'.$id)->assertOk()->assertJsonPath('method.details.identifier', '00123456789');
        $data['name'] = 'Updated name from browser';
        $data['revision'] = '1';
        $data['config_revision'] = (string) $this->revision();
        $data['request_id'] = (string) Str::uuid();
        $data['reason'] = 'Reviewed replacement image';
        $this->post($this->base.'/methods/'.$id, [...$data, '_method' => 'PATCH'], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(2, PaymentMethod::findOrFail($id)->revision);
    }

    public function test_list_search_filters_and_pagination_preserve_currency_summary(): void
    {
        $this->createMethod(['name' => 'Myanmar account']);
        $this->createMethod(['name' => 'Thai account', 'currency' => 'THB']);
        $id = $this->createMethod(['name' => 'USD wallet', 'currency' => 'USD', 'type' => 'wallet']);
        $this->getJson($this->base.'?q=wallet&currency=USD&type=wallet&status=active&per_page=10')
            ->assertOk()->assertJsonCount(1, 'methods')->assertJsonPath('methods.0.id', $id)
            ->assertJsonPath('summary.MMK.total', 1)->assertJsonPath('summary.THB.total', 1)
            ->assertJsonPath('pagination.total', 1);
        $this->getJson($this->base.'?currency=EUR')->assertUnprocessable();
    }

    public function test_unsetting_current_default_requires_reason_even_when_replacement_is_valid(): void
    {
        $first = $this->createMethod();
        $backup = $this->createMethod(['make_default' => false]);
        $payload = $this->payload(['revision' => 1, 'make_default' => false, 'replacement_id' => $backup]);
        $this->patchJson($this->base.'/methods/'.$first, $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->patchJson($this->base.'/methods/'.$first, [...$payload, 'reason' => 'Use backup receiving account'])->assertOk();
        $this->assertSame($backup, app(PaymentConfiguration::class)->settings()['currencies']['MMK']['default_id']);
    }

    public function test_currency_default_changes_appear_in_both_receiving_account_histories(): void
    {
        $first = $this->createMethod();
        $backup = $this->createMethod(['make_default' => false]);
        $currencies = app(PaymentConfiguration::class)->settings()['currencies'];
        $currencies['MMK']['default_id'] = $backup;
        $this->putJson($this->base.'/currencies', ['currencies' => $currencies, 'config_revision' => $this->revision(), 'request_id' => (string) Str::uuid(), 'reason' => 'Switch default for Myanmar'])->assertOk();
        foreach ([$first, $backup] as $id) {
            $this->getJson($this->base.'/methods/'.$id)
                ->assertOk()->assertJsonPath('history.0.action', 'currencies')
                ->assertJsonPath('history.0.metadata.reason', 'Switch default for Myanmar');
        }
    }
}
