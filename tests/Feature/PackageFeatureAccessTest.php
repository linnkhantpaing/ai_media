<?php

namespace Tests\Feature;

use App\Models\CustomerPackageAssignment;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\PaymentTransaction;
use App\Models\UsageRecord;
use App\Models\User;
use App\Services\CustomerAccounts;
use App\Services\Media\MediaBackground;
use App\Services\UsageLedger;
use App\Support\MediaSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PackageFeatureAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private CustomerPackageAssignment $assignment;

    private Package $package;

    private MediaProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->customer = User::factory()->create(['managed_account' => true]);
        $this->package = Package::factory()->create(['name' => 'Pro', 'status' => 'active', 'published_version' => 3]);
        $old = $this->package->definition;
        foreach (['dub', 'audio', 'voice_preview', 'keep_background'] as $key) {
            $old['features'][$key] = false;
        }
        $new = $old;
        foreach (array_keys($new['features']) as $key) {
            $new['features'][$key] = true;
        }
        $this->package->update(['definition' => $new]);
        $v2 = PackageVersion::create(['package_id' => $this->package->id, 'number' => 2, 'definition' => $old, 'actor_id' => $this->admin->id]);
        PackageVersion::create(['package_id' => $this->package->id, 'number' => 3, 'definition' => $new, 'actor_id' => $this->admin->id]);
        $start = now()->subDay()->setTime(9, 38, 53);
        $this->assignment = CustomerPackageAssignment::create(['user_id' => $this->customer->id, 'package_id' => $this->package->id, 'package_version_id' => $v2->id, 'assigned_by' => $this->admin->id, 'source' => 'account_creation',
            'snapshot' => ['name' => 'Pro', 'code' => $this->package->code, 'version' => 2, 'type' => 'paid', 'definition' => $old], 'starts_at' => $start, 'ends_at' => $start->copy()->addDays(30)]);
        $this->project = MediaProject::factory()->create(['user_id' => $this->customer->id]);
        $this->actingAs($this->customer, 'web');
        $this->mock(MediaBackground::class, fn ($m) => $m->shouldReceive('available')->andReturn(true));
    }

    private function settings(array $changes = []): array
    {
        return ['title' => 'Recap project', 'settings' => array_replace(MediaSettings::DEFAULTS, $changes)];
    }

    public function test_current_customer_automatically_uses_latest_features_while_purchased_snapshot_is_preserved(): void
    {
        $response = $this->patchJson('/media/projects/'.$this->project->id, $this->settings(['mode' => 'dub']));
        $response->assertOk();
        $this->assertSame(3, $this->assignment->effectiveSnapshot()['version']);
        $this->assertSame('dub', $this->project->fresh()->settings['mode']);
        $this->assertFalse($this->assignment->fresh()->snapshot['definition']['features']['dub']);
        $this->assertTrue($this->package->fresh()->definition['features']['dub']);
        $this->assertDatabaseCount('media_runs', 0);
    }

    public function test_supported_settings_save_and_audio_and_background_restrictions_are_field_specific(): void
    {
        $version = PackageVersion::where('package_id', $this->package->id)->where('number', 3)->firstOrFail();
        $definition = $version->definition;
        foreach (['dub', 'audio', 'keep_background'] as $key) {
            $definition['features'][$key] = false;
        }
        $version->update(['definition' => $definition]);
        $this->patchJson('/media/projects/'.$this->project->id, $this->settings())->assertOk();
        $this->patchJson('/media/projects/'.$this->project->id, $this->settings(['mode' => 'dub', 'output' => 'mp3', 'keep_background' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors(['settings.mode', 'settings.output', 'settings.keep_background']);
    }

    public function test_explicit_feature_version_correction_keeps_exact_period_and_used_allowance_then_dubbing_can_save(): void
    {
        $start = $this->assignment->starts_at;
        $end = $this->assignment->ends_at;
        UsageRecord::factory()->create(['user_id' => $this->customer->id, 'assignment_id' => $this->assignment->id, 'allowance_start' => $start, 'charged_seconds' => 60, 'charged_videos' => 1]);
        $oldDefinition = $this->assignment->snapshot['definition'];
        app(CustomerAccounts::class)->mutate($this->admin, $this->customer->id, [
            'revision' => $this->customer->account_revision, 'request_id' => (string) Str::uuid(), 'name' => $this->customer->name, 'email' => $this->customer->email, 'preferred_locale' => 'en', 'note' => '',
            'package_id' => $this->package->id, 'package_version' => 3, 'validity_mode' => 'package', 'starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString(), 'reason' => 'Apply published Pro feature update',
        ], 'edit');
        $current = $this->customer->fresh()->currentPackageAssignment;
        $this->assertSame(3, $current->effectiveSnapshot()['version']);
        $this->assertTrue($current->starts_at->equalTo($start));
        $this->assertTrue($current->ends_at->equalTo($end));
        $this->assertTrue(($current->allowance_starts_at ?? $current->starts_at)->equalTo($start));
        $this->assertSame(array_diff_key($oldDefinition, ['features' => true]), array_diff_key($current->snapshot['definition'], ['features' => true]));
        $this->assertSame(60, app(UsageLedger::class)->balances($this->customer->id, ($current->allowance_starts_at ?? $current->starts_at))['seconds']);
        $this->patchJson('/media/projects/'.$this->project->id, $this->settings(['mode' => 'dub', 'keep_background' => true]))->assertOk();
        $this->assertTrue($this->project->fresh()->settings['keep_background']);
        $run = MediaRun::factory()->create(['media_project_id' => $this->project->id, 'settings' => $this->project->fresh()->settings]);
        $record = app(UsageLedger::class)->reserve($run);
        $this->assertSame(3, $record->package_snapshot['version']);
        $this->assertSame('reserved', $record->status);
        $this->assertDatabaseHas('customer_account_events', ['user_id' => $this->customer->id, 'type' => 'edit']);
    }

    public function test_another_customers_project_cannot_be_updated_or_disclose_package_features(): void
    {
        $other = MediaProject::factory()->create();
        $this->patchJson('/media/projects/'.$other->id, $this->settings())->assertNotFound();
        $this->assertSame('Example recap', $other->fresh()->title);
    }

    public function test_feature_only_upgrade_also_fixes_scheduled_renewal_without_changing_dates_payment_links_or_usage(): void
    {
        $future = CustomerPackageAssignment::create([
            ...$this->assignment->toArray(), 'id' => null, 'source' => 'payment_renewal',
            'starts_at' => $this->assignment->ends_at, 'ends_at' => $this->assignment->ends_at->addDays(30),
            'allowance_starts_at' => $this->assignment->ends_at,
        ]);
        $payment = PaymentTransaction::factory()->create(['user_id' => $this->customer->id, 'subscription_id' => $future->id, 'status' => 'approved', 'package_version_id' => $future->package_version_id, 'package_snapshot' => $future->snapshot]);
        $beforePayment = $payment->fresh()->getAttributes();
        $beforeCurrent = $this->assignment->getAttributes();
        $beforeFuture = $future->getAttributes();
        UsageRecord::factory()->create(['user_id' => $this->customer->id, 'allowance_start' => $this->assignment->starts_at, 'charged_seconds' => 120]);
        $requestId = (string) Str::uuid();
        $revision = $this->customer->account_revision;
        $service = app(CustomerAccounts::class);
        $result = $service->upgradePackageFeatures($this->admin, $this->customer->id, $this->package->id, 3, $revision, 'Apply the published feature upgrade', $requestId);
        $this->assertSame(2, $result['updated_periods']);
        foreach ([$this->assignment, $future] as $period) {
            $period->refresh();
            $this->assertSame(3, $period->snapshot['version']);
            $this->assertTrue($period->snapshot['definition']['features']['dub']);
            $before = $period->id === $future->id ? $beforeFuture : $beforeCurrent;
            foreach (['starts_at', 'ends_at', 'allowance_starts_at', 'source', 'supersedes_id'] as $field) {
                $this->assertSame($before[$field] ?? null, $period->getAttributes()[$field] ?? null);
            }
        }
        $this->assertSame($beforePayment, $payment->fresh()->getAttributes());
        $this->assertSame(120, app(UsageLedger::class)->balances($this->customer->id, $this->assignment->starts_at)['seconds']);
        $this->assertSame(2, CustomerPackageAssignment::where('user_id', $this->customer->id)->count());
        $again = $service->upgradePackageFeatures($this->admin, $this->customer->id, $this->package->id, 3, $revision, 'Apply the published feature upgrade', $requestId);
        $this->assertTrue($again['replayed']);
        $this->assertDatabaseCount('customer_account_events', 1);
    }

    public function test_feature_upgrade_refuses_financial_changes_or_removing_an_existing_feature(): void
    {
        $version = PackageVersion::where('package_id', $this->package->id)->where('number', 3)->firstOrFail();
        $definition = $version->definition;
        $version->update(['definition' => [...$definition, 'storage_bytes' => 99]]);
        try {
            app(CustomerAccounts::class)->upgradePackageFeatures($this->admin, $this->customer->id, $this->package->id, 3, $this->customer->account_revision, 'Upgrade features', (string) Str::uuid());
            $this->fail('Changed allowance terms must require a normal assignment review.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('package_version', $error->errors());
        }
        $definition['features']['local'] = false;
        $version->update(['definition' => $definition]);
        try {
            app(CustomerAccounts::class)->upgradePackageFeatures($this->admin, $this->customer->id, $this->package->id, 3, $this->customer->account_revision, 'Upgrade features', (string) Str::uuid());
            $this->fail('Existing features must not be removed by this operation.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('package_version', $error->errors());
        }
        $this->assertSame(2, $this->assignment->fresh()->snapshot['version']);
        $this->assertDatabaseCount('customer_account_events', 0);
    }
}
