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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomerDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->customer = User::factory()->create(['is_admin' => false, 'name' => 'Soe Min']);
    }

    private function plan(array $features = [], int $daysLeft = 5): CustomerPackageAssignment
    {
        $package = Package::factory()->create(['status' => 'active', 'published_version' => 1, 'name' => 'Pro']);
        $definition = $package->definition;
        $definition['features'] = [...$definition['features'], ...$features];
        $version = PackageVersion::create(['package_id' => $package->id, 'number' => 1, 'definition' => $definition]);

        return CustomerPackageAssignment::create(['user_id' => $this->customer->id, 'package_id' => $package->id, 'package_version_id' => $version->id, 'source' => 'payment_new',
            'snapshot' => ['name' => 'Pro', 'code' => $package->code, 'type' => 'paid', 'version' => 1, 'definition' => $definition], 'starts_at' => now()->subDays(25), 'ends_at' => now()->addDays($daysLeft)]);
    }

    private function project(string $status, array $extra = []): MediaProject
    {
        return MediaProject::factory()->create(['user_id' => $this->customer->id, 'status' => $status, ...$extra]);
    }

    public function test_dashboard_combines_plan_work_actions_recent_billing_and_usage(): void
    {
        $assignment = $this->plan(['movie' => false]);
        $rendering = $this->project('rendering', ['title' => 'Space Odyssey']);
        $run = MediaRun::factory()->create(['media_project_id' => $rendering->id, 'phase' => 'generate', 'status' => 'rendering', 'progress' => 64]);
        $rendering->update(['active_run_id' => $run->id]);
        $this->project('awaiting_review', ['title' => 'The Last Samurai']);
        $this->project('failed', ['title' => 'Broken One']);
        $this->project('failed', ['title' => 'Old failure', 'updated_at' => now()->subDays(30)]);
        $done = $this->project('completed', ['title' => 'Inception', 'output_path' => 'media/x/output.mp4', 'subtitle_path' => 'media/x/output.srt']);
        $export = MediaRun::factory()->create(['media_project_id' => $done->id, 'phase' => 'generate', 'status' => 'completed', 'finished_at' => now()->subDay()]);
        UsageRecord::factory()->create(['user_id' => $this->customer->id, 'media_run_id' => $export->id, 'media_project_id' => $done->id, 'charged_seconds' => 600, 'charged_videos' => 1, 'allowance_start' => $assignment->starts_at]);
        UsageRecord::factory()->create(['charged_seconds' => 900]);
        $payment = PaymentTransaction::factory()->create(['user_id' => $this->customer->id, 'status' => 'needs_information']);

        $this->actingAs($this->customer)->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('workspace')
            ->where('dashboard.errors', [])
            ->where('dashboard.plan.package.name', 'Pro')->where('dashboard.plan.expiring', true)
            ->where('dashboard.plan.workflows', ['movie' => false, 'translate' => true, 'subtitles' => true])
            ->where('dashboard.plan.processing.used', 600)
            ->where('dashboard.work.total', 3)->where('dashboard.work.items.0.title', 'Space Odyssey')
            ->where('dashboard.work.items.0.state', 'running')->where('dashboard.work.items.0.stage', 'rendering')->where('dashboard.work.items.0.progress', 64)
            ->where('dashboard.work.items.1.state', 'review')->where('dashboard.work.items.2.state', 'failed')
            ->where('dashboard.work.progress.rendering', 1)
            ->where('dashboard.actions.0.kind', 'payment_info')->where('dashboard.actions.0.data.code', 'PYT-'.str_pad((string) $payment->id, 7, '0', STR_PAD_LEFT))
            ->where('dashboard.actions.1.kind', 'export_failed')->where('dashboard.actions.1.data.count', 1)
            ->where('dashboard.actions.2.kind', 'scripts_ready')->where('dashboard.actions.3.kind', 'plan_expiring')
            ->where('dashboard.recent.0.title', 'Inception')->where('dashboard.recent.0.video', '/media/projects/'.$done->id.'/files/video?download=1')
            ->where('dashboard.billing.pending.status', 'needs_information')->where('dashboard.billing.last', null)
            ->where('dashboard.checklist', null)
            ->where('dashboard.usage.total_seconds', 600)->where('dashboard.usage.videos', 1)->has('dashboard.usage.bars', 14)
            ->where('planBadge.name', 'Pro')->where('planBadge.status', 'active'));
    }

    public function test_new_customers_get_a_getting_started_checklist_they_can_hide(): void
    {
        $this->project('ready');
        $this->actingAs($this->customer)->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.checklist.steps', ['account' => true, 'upload' => true, 'review' => false, 'export' => false, 'defaults' => false])
            ->where('dashboard.actions.0.kind', 'no_plan')->where('dashboard.plan.package', null)->where('planBadge.status', 'none'));
        $this->postJson('/dashboard/checklist/hide')->assertOk();
        $this->getJson('/dashboard/live?sections[]=checklist')->assertOk()->assertJsonPath('checklist', null)->assertJsonMissingPath('plan');
    }

    public function test_usage_ranges_are_validated_and_scoped_to_the_customer(): void
    {
        $this->plan();
        $done = $this->project('completed');
        $old = MediaRun::factory()->create(['media_project_id' => $done->id, 'phase' => 'generate', 'status' => 'completed', 'finished_at' => now()->subDays(20)]);
        UsageRecord::factory()->create(['user_id' => $this->customer->id, 'media_run_id' => $old->id, 'charged_seconds' => 1200, 'returned_seconds' => 120, 'charged_videos' => 1]);
        UsageRecord::factory()->create(['user_id' => $this->customer->id, 'status' => 'reserved', 'charged_seconds' => 0, 'reserved_seconds' => 300, 'charged_videos' => 0]);

        $this->actingAs($this->customer);
        $this->getJson('/dashboard/usage?range=14')->assertOk()->assertJsonPath('total_seconds', 0);
        $this->getJson('/dashboard/usage?range=30')->assertOk()->assertJsonPath('total_seconds', 1080)->assertJsonCount(30, 'bars');
        $all = $this->getJson('/dashboard/usage?range=all')->assertOk()->assertJsonPath('unit', 'month')->assertJsonPath('total_seconds', 1080);
        $this->assertGreaterThanOrEqual(6, count($all->json('bars')));
        $this->getJson('/dashboard/usage?range=7')->assertUnprocessable();
        $this->getJson('/dashboard/live?sections[]=admin')->assertUnprocessable();
    }

    public function test_expired_plans_ask_for_renewal_and_stay_viewable(): void
    {
        $this->plan([], -2);
        $this->actingAs($this->customer)->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.plan.status', 'expired')->where('dashboard.actions.0.kind', 'plan_expired')
            ->where('planBadge.status', 'expired'));
    }
}
