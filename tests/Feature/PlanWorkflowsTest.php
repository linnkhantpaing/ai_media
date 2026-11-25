<?php

namespace Tests\Feature;

use App\Models\CustomerPackageAssignment;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\PaymentRefund;
use App\Models\PaymentTransaction;
use App\Models\UsageCredit;
use App\Models\UsageRecord;
use App\Models\User;
use App\Services\CustomerPlan;
use App\Services\Media\MediaRenderer;
use App\Services\PaymentConfiguration;
use App\Services\Payments;
use App\Services\UsageLedger;
use App\Support\MediaSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Per-workflow plan control: Admin sets each workflow to included / locked / hidden with
 * its own minutes and limits, customers can buy minute top-ups, and free plans can carry
 * a watermark. Everything is enforced on the server, not only in the Studio UI.
 */
class PlanWorkflowsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        config(['media.studio_testing' => false]);
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->customer = User::factory()->create(['is_admin' => false, 'managed_account' => true]);
    }

    private function packagePayload(array $extra = []): array
    {
        return array_replace_recursive(['name' => 'Free', 'code' => 'PKG-FREE', 'type' => 'trial', 'status' => 'active', 'request_id' => (string) Str::uuid(),
            'currency' => 'USD', 'price' => '0', 'validity_days' => 7, 'video_limit' => null, 'storage_gib' => 1, 'max_video_seconds' => 600, 'max_upload_mib' => 100,
            'concurrency' => 1, 'queue_limit' => 3, 'retention_days' => 3, 'description' => '', 'note' => '', 'watermark' => true,
            'features' => ['local' => true, 'youtube' => true, 'mp4' => true, 'srt' => true, 'script_edit' => true, 'video_preview' => true],
            'workflows' => ['movie' => ['access' => 'locked'], 'translate' => ['access' => 'included', 'minutes' => 60, 'max_video_seconds' => 300], 'subtitles' => ['access' => 'included', 'minutes' => 30]]], $extra);
    }

    private function assign(array $definition, string $name = 'Plan'): CustomerPackageAssignment
    {
        $package = Package::factory()->create(['status' => 'active', 'published_version' => 1, 'name' => $name, 'definition' => $definition]);
        $version = PackageVersion::create(['package_id' => $package->id, 'number' => 1, 'definition' => $definition]);

        return CustomerPackageAssignment::create(['user_id' => $this->customer->id, 'package_id' => $package->id, 'package_version_id' => $version->id, 'source' => 'payment_new',
            'snapshot' => ['name' => $name, 'code' => $package->code, 'type' => 'paid', 'version' => 1, 'definition' => $definition], 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(20)]);
    }

    private function definition(array $workflows, array $extra = []): array
    {
        $base = Package::factory()->make()->definition;

        return [...$base, 'max_video_seconds' => 7200, 'included_minutes' => 1000, 'included_seconds' => 60000, ...$extra,
            'features' => [...$base['features'], 'movie' => ($workflows['movie']['access'] ?? 'included') === 'included', 'dub' => ($workflows['translate']['access'] ?? 'included') === 'included', 'subs' => ($workflows['subtitles']['access'] ?? 'included') === 'included'],
            'workflows' => $workflows];
    }

    private function mediaRun(array $settings, int $duration = 600): MediaRun
    {
        $project = MediaProject::factory()->create(['user_id' => $this->customer->id, 'status' => 'ready', 'settings' => MediaSettings::normalize($settings),
            'metadata' => ['duration' => $duration, 'width' => 1280, 'height' => 720, 'audio' => true]]);

        return MediaRun::factory()->create(['media_project_id' => $project->id, 'phase' => 'analysis', 'status' => 'queued', 'settings' => MediaSettings::normalize($settings)]);
    }

    private function reserveError(MediaRun $run): array
    {
        try {
            app(UsageLedger::class)->reserve($run);
        } catch (ValidationException $error) {
            return $error->errors();
        }
        $this->fail('The reservation should have been refused.');
    }

    public function test_admin_saves_workflow_rules_and_the_plan_total_is_their_sum(): void
    {
        $response = $this->actingAs($this->admin, 'admin')->postJson('/admin/packages', $this->packagePayload())->assertCreated();
        $definition = Package::findOrFail($response->json('package.id'))->definition;

        $this->assertSame(['access' => 'locked', 'minutes' => null, 'max_video_seconds' => null, 'max_target_seconds' => null], $definition['workflows']['movie']);
        $this->assertSame(90, $definition['included_minutes']);
        $this->assertFalse($definition['features']['movie']);
        $this->assertTrue($definition['features']['dub'] && $definition['features']['subs']);
        $this->assertTrue($definition['watermark']);

        $missing = $this->packagePayload(['code' => 'PKG-X', 'name' => 'X', 'request_id' => (string) Str::uuid(), 'workflows' => ['subtitles' => ['minutes' => null]]]);
        $this->postJson('/admin/packages', $missing)->assertUnprocessable()->assertJsonValidationErrors('workflows.subtitles.minutes');
        $tooLong = $this->packagePayload(['code' => 'PKG-Y', 'name' => 'Y', 'request_id' => (string) Str::uuid(), 'workflows' => ['translate' => ['max_video_seconds' => 7200]]]);
        $this->postJson('/admin/packages', $tooLong)->assertUnprocessable()->assertJsonValidationErrors('workflows.translate.max_video_seconds');
        $none = $this->packagePayload(['code' => 'PKG-Z', 'name' => 'Z', 'request_id' => (string) Str::uuid(), 'workflows' => ['translate' => ['access' => 'hidden'], 'subtitles' => ['access' => 'locked']]]);
        $this->postJson('/admin/packages', $none)->assertUnprocessable()->assertJsonValidationErrors('workflows.subtitles.access');
    }

    public function test_top_up_packages_need_only_price_workflow_and_minutes(): void
    {
        $this->actingAs($this->admin, 'admin');
        $payload = ['name' => 'Movie +100', 'code' => 'TOP-MOVIE', 'type' => 'topup', 'status' => 'active', 'request_id' => (string) Str::uuid(), 'currency' => 'USD', 'price' => '4.50', 'description' => '', 'note' => ''];
        $this->postJson('/admin/packages', $payload)->assertUnprocessable()->assertJsonValidationErrors(['topup_workflow', 'topup_minutes']);
        $id = $this->postJson('/admin/packages', [...$payload, 'request_id' => (string) Str::uuid(), 'topup_workflow' => 'movie', 'topup_minutes' => 100])->assertCreated()->json('package.id');

        $definition = Package::findOrFail($id)->definition;
        $this->assertSame(['workflow' => 'movie', 'minutes' => 100], $definition['topup']);
        $this->assertSame(450, $definition['price_minor']);
        $this->assertArrayNotHasKey('validity_days', $definition);
        $this->get('/admin/packages?type=topup')->assertOk()->assertInertia(fn (Assert $page) => $page->has('catalog.packages', 1));
    }

    public function test_locked_workflows_long_sources_and_long_recaps_are_refused_by_the_server(): void
    {
        $this->assign($this->definition([
            'movie' => ['access' => 'locked'], 'translate' => ['access' => 'included', 'minutes' => 60, 'max_video_seconds' => 300],
            'subtitles' => ['access' => 'included', 'minutes' => 30],
        ]));
        $this->assertArrayHasKey('settings.workflow', $this->reserveError($this->mediaRun(['workflow' => 'movie', 'mode' => 'dub'])));
        $this->assertStringContainsString('up to 5 minutes', $this->reserveError($this->mediaRun(['workflow' => 'translate', 'mode' => 'dub'], 600))['usage'][0]);

        CustomerPackageAssignment::query()->delete();
        $this->assign($this->definition(['movie' => ['access' => 'included', 'minutes' => 100, 'max_target_seconds' => 300]]), 'Plan 2');
        $this->assertArrayHasKey('settings.target_seconds', $this->reserveError($this->mediaRun(['workflow' => 'movie', 'mode' => 'dub', 'target_seconds' => 600])));
        $record = app(UsageLedger::class)->reserve($this->mediaRun(['workflow' => 'movie', 'mode' => 'dub', 'target_seconds' => 300]));
        $this->assertSame('movie', $record->workflow);
    }

    public function test_each_workflow_spends_only_its_own_minutes_and_top_ups_extend_them(): void
    {
        $assignment = $this->assign($this->definition(['movie' => ['access' => 'included', 'minutes' => 10], 'translate' => ['access' => 'included', 'minutes' => 60], 'subtitles' => ['access' => 'included', 'minutes' => 60]]));
        $ledger = app(UsageLedger::class);
        $ledger->reserve($this->mediaRun(['workflow' => 'movie', 'mode' => 'dub'], 480));

        $this->assertStringContainsString('Summarize a Long Movie minutes', $this->reserveError($this->mediaRun(['workflow' => 'movie', 'mode' => 'dub'], 300))['usage'][0]);
        $ledger->reserve($this->mediaRun(['workflow' => 'translate', 'mode' => 'dub'], 1800));

        UsageCredit::create(['user_id' => $this->customer->id, 'workflow' => 'movie', 'seconds' => 600, 'allowance_start' => $assignment->starts_at, 'expires_at' => $assignment->ends_at]);
        $ledger->reserve($this->mediaRun(['workflow' => 'movie', 'mode' => 'dub'], 300));

        $balances = $ledger->workflowBalances($this->customer->id, $assignment);
        $this->assertEquals(['total' => 1200, 'reserved' => 780, 'available' => 420, 'credits' => 600], array_intersect_key($balances['movie'], array_flip(['total', 'reserved', 'available', 'credits'])));
        $this->assertSame(1800, $balances['translate']['available']);

        $this->actingAs($this->customer)->get('/account/plan')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('overview.workflows.movie.available', 420)->where('overview.credits', 600)->where('overview.processing.total', 1000 * 60 + 600));
    }

    public function test_customers_buy_top_ups_for_included_workflows_and_admin_approval_adds_the_minutes(): void
    {
        $assignment = $this->assign($this->definition(['movie' => ['access' => 'locked'], 'translate' => ['access' => 'included', 'minutes' => 60], 'subtitles' => ['access' => 'included', 'minutes' => 30]]), 'Starter');
        $make = function (string $workflow, string $code): Package {
            $definition = ['name' => $code, 'code' => $code, 'type' => 'topup', 'currency' => 'USD', 'price_minor' => 300, 'price' => '3.00', 'topup' => ['workflow' => $workflow, 'minutes' => 50], 'description' => '', 'note' => ''];
            $package = Package::factory()->create(['name' => $code, 'code' => $code, 'type' => 'topup', 'status' => 'active', 'published_version' => 1, 'definition' => $definition]);
            PackageVersion::create(['package_id' => $package->id, 'number' => 1, 'definition' => $definition]);

            return $package;
        };
        $subs = $make('subtitles', 'TOP-SUBS');
        $make('movie', 'TOP-MOVIE');
        $method = app(PaymentConfiguration::class)->saveMethod($this->admin, ['name' => 'Bank', 'provider' => 'Bank', 'type' => 'bank', 'currency' => 'USD', 'status' => 'active', 'sort_order' => 0,
            'details' => ['holder' => 'Business', 'identifier' => '12345678', 'branch' => '', 'routing' => ''], 'instructions' => ['en' => 'Pay.', 'my' => '', 'th' => ''],
            'make_default' => true, 'enable_currency' => true, 'remove_qr' => false, 'config_revision' => 0, 'request_id' => (string) Str::uuid()])['id'];

        $this->actingAs($this->customer, 'web');
        $options = $this->getJson('/account/billing-api/options')->assertOk();
        $topups = collect($options->json('topups'))->keyBy('workflow');
        $this->assertTrue($topups['subtitles']['available']);
        $this->assertSame('topup', $topups['subtitles']['purpose']);
        $this->assertFalse($topups['movie']['available']);
        $this->assertSame('topupNeedsWorkflow', $topups['movie']['reason']);
        $this->assertNotContains('TOP-SUBS', collect($options->json('plans'))->pluck('name')->all());

        $receipt = UploadedFile::fake()->createWithContent('receipt.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jD1sAAAAASUVORK5CYII='));
        $id = $this->postJson('/account/billing-api', ['request_id' => (string) Str::uuid(), 'config_revision' => app(PaymentConfiguration::class)->settings()['revision'],
            'package_id' => $subs->id, 'package_version' => 1, 'payment_method_id' => $method, 'received' => '3.00', 'reference' => 'TOP-1', 'sender' => 'Customer',
            'transfer_date' => now()->subMinute()->toIso8601String(), 'receipt' => $receipt])->assertCreated()->json('id');
        $payment = PaymentTransaction::findOrFail($id);
        $this->assertSame('topup', $payment->purpose);
        $this->assertSame(['workflow' => 'subtitles', 'minutes' => 50], $this->getJson('/account/billing-api/'.$id)->json('package.topup'));

        app(Payments::class)->act($this->admin, $id, ['action' => 'approve', 'request_id' => (string) Str::uuid(), 'revision' => $payment->revision,
            'customer_revision' => $this->customer->fresh()->account_revision, 'verified' => ['account' => true, 'amount' => true, 'reference' => true, 'outcome' => true]]);
        $this->assertSame('approved', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->subscription_id);
        $this->assertSame(1, CustomerPackageAssignment::where('user_id', $this->customer->id)->count());
        $credit = UsageCredit::where('payment_transaction_id', $id)->firstOrFail();
        $this->assertSame([3000, 'subtitles'], [$credit->seconds, $credit->workflow]);
        $this->assertSame(30 * 60 + 3000, app(UsageLedger::class)->workflowBalances($this->customer->id, $assignment)['subtitles']['total']);

        // A refund that ends access removes the bought minutes again.
        $refund = app(Payments::class)->act($this->admin, $id, ['action' => 'refund', 'request_id' => (string) Str::uuid(), 'revision' => $payment->fresh()->revision, 'amount' => '3.00', 'destination' => 'Same bank', 'reason' => 'Requested', 'access_action' => 'end']);
        $refundId = PaymentRefund::where('payment_transaction_id', $id)->value('id');
        app(Payments::class)->act($this->admin, $id, ['action' => 'refund_complete', 'request_id' => (string) Str::uuid(), 'revision' => $payment->fresh()->revision, 'refund_id' => $refundId,
            'confirmed' => true, 'reference' => 'OUT-1', 'transfer_date' => now()->toDateString(), 'reason' => 'Done', 'receipt' => UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jD1sAAAAASUVORK5CYII='))]);
        $this->assertNotNull($credit->fresh()->revoked_at);
        $this->assertSame(30 * 60, app(UsageLedger::class)->workflowBalances($this->customer->id, $assignment)['subtitles']['total']);
        $this->assertIsArray($refund);
    }

    public function test_top_ups_need_an_active_plan(): void
    {
        $definition = ['name' => 'TOP', 'code' => 'TOP', 'type' => 'topup', 'currency' => 'USD', 'price_minor' => 300, 'price' => '3.00', 'topup' => ['workflow' => 'subtitles', 'minutes' => 50], 'description' => '', 'note' => ''];
        $package = Package::factory()->create(['name' => 'TOP', 'code' => 'TOP', 'type' => 'topup', 'status' => 'active', 'published_version' => 1, 'definition' => $definition]);
        PackageVersion::create(['package_id' => $package->id, 'number' => 1, 'definition' => $definition]);

        $this->actingAs($this->customer, 'web');
        $topup = $this->getJson('/account/billing-api/options')->assertOk()->json('topups.0');
        $this->assertFalse($topup['available']);
        $this->assertSame('topupNeedsPlan', $topup['reason']);
        $this->assertNotContains('TOP', collect(app(CustomerPlan::class)->plans())->pluck('name')->all());
    }

    public function test_studio_and_dashboard_receive_the_plan_rules_and_watermark_follows_the_reserved_plan(): void
    {
        $this->assign($this->definition(['movie' => ['access' => 'locked'], 'translate' => ['access' => 'hidden'], 'subtitles' => ['access' => 'included', 'minutes' => 30]], ['watermark' => true]));
        $this->actingAs($this->customer)->get('/projects/new')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('workflows.movie.access', 'locked')->where('workflows.translate.access', 'hidden')->where('workflows.subtitles.available', 1800)->where('watermark', true));
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('dashboard.plan.workflows', ['movie' => 'locked', 'translate' => 'hidden', 'subtitles' => 'included']));

        $run = $this->mediaRun(['mode' => 'subs']);
        app(UsageLedger::class)->reserve($run);
        $this->assertTrue(MediaRenderer::watermarked($run));
        UsageRecord::where('media_run_id', $run->id)->update(['package_snapshot' => json_encode(['definition' => ['watermark' => false]])]);
        $this->assertFalse(MediaRenderer::watermarked($run));
    }

    public function test_plans_saved_before_workflow_minutes_keep_the_shared_allowance(): void
    {
        $base = Package::factory()->make()->definition;
        $legacy = [...$base, 'max_video_seconds' => 7200, 'included_minutes' => 20, 'included_seconds' => 1200, 'features' => [...$base['features'], 'dub' => true, 'subs' => true]];
        unset($legacy['workflows'], $legacy['features']['movie']);
        $assignment = $this->assign($legacy);
        $ledger = app(UsageLedger::class);
        $ledger->reserve($this->mediaRun(['workflow' => 'movie', 'mode' => 'dub'], 600));
        $ledger->reserve($this->mediaRun(['mode' => 'subs'], 600));
        $this->assertStringContainsString('not enough processing allowance', $this->reserveError($this->mediaRun(['mode' => 'subs'], 60))['usage'][0]);
        $this->assertNull($ledger->workflowBalances($this->customer->id, $assignment)['movie']['total']);
    }
}
