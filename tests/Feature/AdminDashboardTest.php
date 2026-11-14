<?php

namespace Tests\Feature;

use App\Models\CustomerPackageAssignment;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Models\MediaTask;
use App\Models\MediaWorker;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\PaymentMethod;
use App\Models\PaymentRefund;
use App\Models\PaymentTransaction;
use App\Models\UsageRecord;
use App\Models\User;
use App\Services\GeneralSettings;
use App\Services\Media\ProviderSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private string $url = '/admin/dashboard';

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

    private function payment(int $amount, string $currency = 'MMK', string $at = '2026-11-12 10:00:00', array $extra = []): PaymentTransaction
    {
        return PaymentTransaction::factory()->create(array_replace(['user_id' => $this->customer->id, 'currency' => $currency,
            'received_minor' => $amount, 'expected_minor' => $amount, 'status' => 'approved', 'approved_at' => $at,
            'payment_method_id' => PaymentMethod::factory()->create(['currency' => $currency])->id], $extra));
    }

    private function export(string $status = 'completed', string $mode = 'dub', ?int $seconds = 60): array
    {
        $package = Package::factory()->create();
        $project = MediaProject::withoutEvents(fn () => MediaProject::factory()->create(['user_id' => $this->customer->id]));
        $run = MediaRun::withoutEvents(fn () => MediaRun::factory()->create(['media_project_id' => $project->id, 'phase' => 'generate', 'settings' => ['mode' => $mode, 'output' => 'mp4_srt']]));
        $record = UsageRecord::factory()->create(['user_id' => $this->customer->id, 'media_project_id' => $project->id, 'media_run_id' => $run->id, 'package_id' => $package->id]);
        $task = MediaTask::withoutEvents(fn () => MediaTask::factory()->create(['media_project_id' => $project->id, 'media_run_id' => $run->id,
            'type' => 'generate', 'mode' => $mode, 'status' => $status, 'started_at' => now()->subMinutes(3), 'finished_at' => now(), 'processing_seconds' => $seconds]));

        return [$record, $task];
    }

    public function test_dashboard_requires_admin_guard_and_delivers_server_rendered_initial_data(): void
    {
        auth('admin')->logout();
        $this->get($this->url)->assertRedirect('/admin/login');
        $this->actingAs($this->customer, 'web')->getJson($this->url)->assertUnauthorized();
        $this->actingAs($this->admin, 'admin')->get($this->url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/dashboard')->where('dashboard.summary.gross', 0)->where('dashboard.filters.dates', '30')->has('dashboard.links.jobs'));
        $this->getJson($this->url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('summary.successful', 0)
            ->assertJsonPath('system.workers.online', 0)->assertJsonCount(0, 'recent')->assertJsonCount(0, 'packages');
    }

    public function test_money_uses_event_dates_completed_refunds_and_separate_currencies(): void
    {
        $payment = $this->payment(1000000);
        $this->payment(29900, 'THB');
        $this->payment(999, 'USD');
        $this->payment(50000, 'MMK', '2026-10-01 10:00:00');
        $this->payment(900000, 'MMK', '2026-11-12 10:00:00', ['status' => 'pending_review', 'approved_at' => null]);
        foreach (['completed' => 25000, 'requested' => 50000] as $status => $amount) {
            PaymentRefund::create(['payment_transaction_id' => $payment->id, 'amount_minor' => $amount, 'status' => $status,
                'completed_at' => $status === 'completed' ? now() : null, 'access_action' => 'keep', 'details' => ['destination' => 'PRIVATE-DESTINATION'], 'reason' => 'PRIVATE-REASON', 'created_by' => $this->admin->id]);
        }
        $result = $this->getJson($this->url)->assertOk()->assertJsonPath('summary.gross', 1000000)->assertJsonPath('summary.refunds', 25000)->assertJsonPath('summary.net', 975000)
            ->assertJsonPath('previous.gross', 50000)->assertJsonPath('previous.refunds', 0);
        $this->assertSame(1000000, array_sum(array_column($result->json('trend.points'), 'gross')));
        $this->assertSame(25000, array_sum(array_column($result->json('trend.points'), 'refunds')));
        $this->assertStringNotContainsString('PRIVATE-', $result->getContent());
        $this->getJson($this->url.'?currency=THB')->assertOk()->assertJsonPath('summary.gross', 29900)->assertJsonPath('summary.refunds', 0);
        $this->getJson($this->url.'?currency=USD')->assertOk()->assertJsonPath('summary.gross', 999);
        $this->getJson($this->url.'?dates=all')->assertOk()->assertJsonPath('summary.gross', 1050000)->assertJsonPath('previous', null)->assertJsonPath('range.from', '2026-10-01');
    }

    public function test_exports_exclude_previews_and_analyses_and_use_net_charged_source_time(): void
    {
        [$record, $task] = $this->export();
        $record->events()->create(['kind' => 'charged', 'changes' => ['seconds' => 180], 'created_at' => now()]);
        $record->events()->create(['kind' => 'adjusted', 'changes' => ['seconds' => 30], 'reason' => 'PRIVATE-CORRECTION', 'created_at' => now()]);
        $this->export('failed', 'subs');
        $this->export('cancelled', 'subs');
        MediaTask::withoutEvents(fn () => MediaTask::factory()->create(['media_project_id' => $task->media_project_id, 'type' => 'preview', 'status' => 'completed', 'finished_at' => now()]));
        $result = $this->getJson($this->url)->assertOk()->assertJsonPath('summary.successful', 1)->assertJsonPath('summary.failed', 1)->assertJsonPath('summary.seconds', 150)
            ->assertJsonCount(3, 'recent')->assertJsonCount(1, 'packages');
        $this->assertSame(150, array_sum(array_column($result->json('modes'), 'seconds')));
        $this->assertSame(150, $result->json('packages.0.seconds'));
        $this->assertSame(60, $result->json('recent.2.duration'));
        $this->assertStringNotContainsString('PRIVATE-CORRECTION', $result->getContent());
    }

    public function test_current_access_storage_and_workers_are_independent_of_historical_filter(): void
    {
        $package = Package::factory()->create();
        $assignment = CustomerPackageAssignment::create(['user_id' => $this->customer->id, 'package_id' => $package->id,
            'package_version_id' => PackageVersion::create(['package_id' => $package->id, 'number' => 1, 'definition' => $package->definition])->id, 'snapshot' => [], 'source' => 'account_creation', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(29)]);
        [$record, $task] = $this->export();
        DB::table('media_files')->insert(['media_project_id' => $task->media_project_id, 'disk' => 'local', 'path' => 'fixture.mp4', 'bytes' => 1024, 'state' => 'ready', 'is_source' => false, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        MediaWorker::factory()->create(['heartbeat_at' => now()]);
        MediaWorker::factory()->create(['heartbeat_at' => now()->subHours(2), 'stopped_at' => now()->subHour()]);
        $this->getJson($this->url.'?dates=custom&from=2025-01-01&to=2025-01-02')->assertOk()
            ->assertJsonPath('summary.active', 1)->assertJsonPath('summary.successful', 0)->assertJsonPath('system.storage', 1024)->assertJsonPath('system.workers.online', 1);
        $assignment->update(['closed_kind' => 'ended', 'closed_at' => now()]);
        $this->getJson($this->url)->assertOk()->assertJsonPath('summary.active', 0);
        $assignment->update(['closed_kind' => null, 'closed_at' => null]);
        $this->customer->forceFill(['account_status' => 'suspended'])->save();
        $this->getJson($this->url)->assertOk()->assertJsonPath('summary.active', 0);
    }

    public function test_chart_buckets_follow_half_hour_offsets_and_historical_daylight_saving(): void
    {
        $zone = 'Asia/Yangon';
        $this->app->make(GeneralSettings::class);
        $this->partialMock(GeneralSettings::class, function ($mock) use (&$zone): void {
            $mock->shouldReceive('publicData')->andReturnUsing(function () use (&$zone): array {
                return ['timezone' => $zone];
            });
        });
        $this->payment(150, 'MMK', '2026-11-11 17:45:00');
        $this->getJson($this->url.'?dates=custom&from=2026-11-12&to=2026-11-12')->assertOk()
            ->assertJsonPath('summary.gross', 150)->assertJsonPath('trend.points.0.gross', 150);
        $zone = 'America/New_York';
        $this->payment(300, 'MMK', '2026-07-02 04:15:00');
        $this->payment(500, 'MMK', '2026-07-03 03:45:00');
        $this->getJson($this->url.'?dates=custom&from=2026-07-02&to=2026-07-02')->assertOk()
            ->assertJsonPath('summary.gross', 800)->assertJsonPath('trend.points.0.gross', 800);
    }

    public function test_local_midnight_boundaries_validation_and_secrets(): void
    {
        config(['media.gemini_key' => 'DASHBOARD-PRIVATE-KEY', 'filesystems.disks.r2.secret' => 'DASHBOARD-PRIVATE-SECRET']);
        $this->payment(100, 'MMK', '2026-11-11 16:59:59');
        $this->payment(200, 'MMK', '2026-11-11 17:00:00');
        $this->payment(300, 'MMK', '2026-11-12 16:59:59');
        $this->payment(400, 'MMK', '2026-11-12 17:00:00');
        $result = $this->getJson($this->url.'?dates=custom&from=2026-11-12&to=2026-11-12')->assertOk()->assertJsonPath('summary.gross', 500)
            ->assertJsonPath('trend.points.0.gross', 500)->assertJsonPath('system.provider', 'untested');
        $this->assertStringNotContainsString('DASHBOARD-PRIVATE', $result->getContent());
        $settings = app(ProviderSettings::class);
        $settings->report($settings->current(), 'text', 'success', null, $this->admin->id);
        $this->getJson($this->url)->assertOk()->assertJsonPath('system.provider', 'tested');
        $this->getJson($this->url.'?currency=EUR')->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->getJson($this->url.'?dates=custom&from=2026-11-13&to=2026-11-01')->assertUnprocessable();
        $this->getJson($this->url.'?dates=custom')->assertUnprocessable();
    }
}
