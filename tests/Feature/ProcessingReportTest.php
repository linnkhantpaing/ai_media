<?php

namespace Tests\Feature;

use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Models\MediaTask;
use App\Models\Package;
use App\Models\UsageRecord;
use App\Models\User;
use App\Services\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProcessingReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private string $url = '/admin/reports/usage-processing';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 11, 13)->setTime(10, 0));
        Mail::fake();
        Notification::fake();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->customer = User::factory()->create();
        $this->actingAs($this->admin, 'admin');
    }

    private function operation(string $status = 'completed', array $extra = [], ?MediaProject $project = null): array
    {
        $project ??= MediaProject::withoutEvents(fn () => MediaProject::factory()->create(['user_id' => $this->customer->id, 'source_type' => 'youtube', 'settings' => ['mode' => 'dub']]));
        $run = MediaRun::withoutEvents(fn () => MediaRun::factory()->create(['media_project_id' => $project->id, 'phase' => 'generate', 'status' => $status, 'settings' => ['mode' => 'dub', 'output' => 'mp4_srt']]));
        $record = UsageRecord::factory()->create(['user_id' => $this->customer->id, 'media_project_id' => $project->id, 'media_run_id' => $run->id,
            'charged_seconds' => $status === 'completed' ? 120 : 0, 'charged_videos' => $status === 'completed' ? 1 : 0, 'status' => $status === 'completed' ? 'charged' : 'released']);
        $task = MediaTask::withoutEvents(fn () => MediaTask::factory()->create(['media_project_id' => $project->id, 'media_run_id' => $run->id,
            'type' => 'generate', 'mode' => 'dub', 'status' => $status, 'started_at' => now()->subMinutes(5), 'finished_at' => now(),
            'processing_seconds' => 120, ...$extra]));

        return [$project, $run, $record, $task];
    }

    public function test_only_admin_can_read_report_details_and_csv(): void
    {
        auth('admin')->logout();
        $this->get($this->url)->assertRedirect('/admin/login');
        $this->actingAs($this->customer, 'web')->getJson($this->url)->assertUnauthorized();
        $this->getJson($this->url.'/customers/'.$this->customer->id)->assertUnauthorized();
        $this->getJson($this->url.'/export')->assertUnauthorized();
        $this->actingAs($this->admin, 'admin')->getJson($this->url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson($this->url.'/customers/'.$this->admin->id)->assertNotFound();
    }

    public function test_charges_use_event_time_and_adjustments_without_multiplying_exports(): void
    {
        [$project, $run, $record] = $this->operation();
        $record->events()->create(['kind' => 'charged', 'changes' => ['seconds' => 120], 'created_at' => now()]);
        $record->events()->create(['kind' => 'adjusted', 'changes' => ['seconds' => 30], 'reason' => 'private reason', 'created_at' => now()]);
        $this->operation('failed', [], $project);
        $this->operation('cancelled', [], $project);
        MediaTask::withoutEvents(fn () => MediaTask::factory()->create(['media_project_id' => $project->id, 'type' => 'preview', 'status' => 'completed', 'finished_at' => now(), 'mode' => 'dub']));
        $response = $this->getJson($this->url)->assertOk()->assertJsonPath('summary.seconds', 90)
            ->assertJsonPath('summary.projects', 1)->assertJsonPath('summary.successful', 1)->assertJsonPath('summary.failed', 1)
            ->assertJsonPath('summary.cancelled', 1)->assertJsonPath('summary.success_rate', 50)->assertJsonPath('summary.average_duration', 120)
            ->assertJsonPath('modes.dub', 90)->assertJsonPath('sources.youtube', 1)->assertJsonCount(1, 'items');
        $this->assertSame(90, array_sum(array_column($response->json('trend.points'), 'seconds')));
        $this->assertSame(1, array_sum(array_column($response->json('trend.points'), 'exports')));
        $this->assertStringNotContainsString('private reason', $response->getContent());
    }

    public function test_missing_telemetry_is_unavailable_and_preview_validation_do_not_inflate_exports(): void
    {
        [$project] = $this->operation('completed', ['processing_seconds' => null]);
        MediaTask::withoutEvents(fn () => MediaTask::factory()->create(['media_project_id' => $project->id, 'type' => 'validation', 'status' => 'failed', 'finished_at' => now()]));
        $this->getJson($this->url)->assertOk()->assertJsonPath('summary.successful', 1)->assertJsonPath('summary.failed', 0)
            ->assertJsonPath('summary.average_duration', null)->assertJsonPath('summary.seconds', 0);
        $this->getJson($this->url.'?view=stages')->assertOk()->assertJsonCount(2, 'items');
        $this->getJson($this->url.'?dates=custom&from=2025-01-01&to=2025-01-02')->assertOk()
            ->assertJsonPath('summary.success_rate', null)->assertJsonPath('summary.average_duration', null);
    }

    public function test_current_verified_storage_and_reservations_ignore_date_range(): void
    {
        [$project, $run, $record] = $this->operation();
        $record->update(['status' => 'reserved', 'reserved_seconds' => 75]);
        foreach (['verified' => 1024, 'unverified' => 2048, 'deleted' => 4096] as $kind => $bytes) {
            DB::table('media_files')->insert(['media_project_id' => $project->id, 'disk' => 'local', 'path' => $kind,
                'bytes' => $bytes, 'state' => $kind === 'deleted' ? 'deleted' : 'ready', 'is_source' => false,
                'verified_at' => $kind === 'unverified' ? null : now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->getJson($this->url.'?dates=custom&from=2025-01-01&to=2025-01-02')->assertOk()
            ->assertJsonPath('summary.storage', 1024)->assertJsonPath('summary.reserved', 75)
            ->assertJsonPath('summary.successful', 0)->assertJsonPath('items.0.storage', 1024);
    }

    public function test_historical_package_filters_and_snapshots_survive_current_changes(): void
    {
        [$project, $run, $record] = $this->operation();
        $package = Package::factory()->create(['name' => 'Renamed current package']);
        $record->update(['package_id' => $package->id, 'package_snapshot' => ['name' => 'Purchased Pro', 'version' => 1]]);
        $record->events()->create(['kind' => 'charged', 'changes' => ['seconds' => 120], 'created_at' => now()]);
        $this->getJson($this->url.'?package='.$package->id.'&mode=dub&source=youtube')->assertOk()->assertJsonPath('summary.seconds', 120)
            ->assertJsonPath('summary.successful', 1)->assertJsonPath('items.0.packages.0.name', 'Purchased Pro')->assertJsonPath('items.0.packages.0.version', 1);
        $this->getJson($this->url.'?mode=subs')->assertOk()->assertJsonPath('summary.seconds', 0)->assertJsonPath('summary.successful', 0);
        $this->getJson($this->url.'?q=nonmatching')->assertOk()->assertJsonPath('summary.seconds', 120)->assertJsonCount(0, 'items');
        $this->getJson($this->url.'?view=packages&package='.$package->id)->assertOk()->assertJsonPath('items.0.name', 'Renamed current package')->assertJsonPath('items.0.seconds', 120);
    }

    public function test_detail_is_read_only_customer_scoped_and_has_safe_job_links(): void
    {
        [$project] = $this->operation();
        $project->updateQuietly(['source_path' => 'PRIVATE_SOURCE', 'lines' => [['text' => 'PRIVATE_TRANSCRIPT']], 'metadata' => ['poster' => true, 'duration' => 60]]);
        $other = User::factory()->create();
        $this->getJson($this->url.'/customers/'.$this->customer->id.'?customer='.$other->id)->assertOk()
            ->assertJsonPath('summary.successful', 1)->assertJsonCount(1, 'recent')->assertJsonPath('recent.0.status', 'completed')
            ->assertJsonPath('customer.id', $this->customer->id)->assertJsonPath('recent.0.source_duration', 60);
        $response = $this->getJson($this->url.'/customers/'.$this->customer->id);
        $this->assertStringNotContainsString('PRIVATE_', $response->getContent());
        $this->assertStringContainsString('/files/poster', $response->json('recent.0.poster_url'));
        $this->assertArrayNotHasKey('project_id', $response->json('recent.0'));
        $this->assertStringContainsString('/admin/operations/jobs?', $response->json('recent.0.job_url'));
        $this->assertDatabaseCount('usage_records', 1);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_timezone_boundaries_use_local_midnight_and_completion_dates(): void
    {
        $this->app->make(GeneralSettings::class);
        $this->partialMock(GeneralSettings::class, function ($mock): void {
            $mock->shouldReceive('publicData')->andReturn(['timezone' => 'Asia/Bangkok']);
        });
        $this->operation('completed', ['started_at' => '2026-11-11 16:00:00', 'finished_at' => '2026-11-11 17:00:00']);
        $this->operation('failed', ['finished_at' => '2026-11-11 16:59:59']);
        $this->getJson($this->url.'?dates=custom&from=2026-11-12&to=2026-11-12')->assertOk()
            ->assertJsonPath('summary.successful', 1)->assertJsonPath('summary.failed', 0)
            ->assertJsonPath('range.timezone', 'Asia/Bangkok')->assertJsonPath('trend.points.0.date', '2026-11-12');
    }

    public function test_pagination_csv_validation_and_formula_protection(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->customer = User::factory()->create(['name' => $i === 0 ? '=FORMULA()' : 'User '.$i]);
            $this->operation();
        }
        $this->getJson($this->url.'?page=2')->assertOk()->assertJsonCount(2, 'items')->assertJsonPath('pagination.total', 12);
        $this->getJson($this->url.'?page=999')->assertOk()->assertJsonPath('pagination.page', 2);
        $csv = $this->get($this->url.'/export?page=2')->assertOk()->streamedContent();
        $this->assertCount(13, array_filter(explode("\n", trim($csv))));
        $this->assertStringContainsString("'=FORMULA()", $csv);
        $this->getJson($this->url.'?mode=invalid')->assertUnprocessable();
        $this->getJson($this->url.'?dates=custom')->assertUnprocessable();
        $this->getJson($this->url.'?dates=custom&from=2026-11-13&to=2026-11-12')->assertUnprocessable();
    }

    public function test_corrections_use_their_own_period_and_can_produce_negative_net_usage(): void
    {
        [, , $record] = $this->operation();
        $record->events()->create(['kind' => 'charged', 'changes' => ['seconds' => 120], 'created_at' => now()->subMonths(2)]);
        $record->events()->create(['kind' => 'adjusted', 'changes' => ['seconds' => 60], 'created_at' => now()]);
        $response = $this->getJson($this->url)->assertOk()->assertJsonPath('summary.seconds', -60)->assertJsonPath('modes.dub', -60);
        $this->assertSame(-60, array_sum(array_column($response->json('trend.points'), 'seconds')));
        $this->getJson($this->url.'?dates=all')->assertOk()->assertJsonPath('summary.seconds', 60);
    }

    public function test_deleted_project_keeps_ledger_history_without_guessing_its_source_or_mode(): void
    {
        [$project, , $record] = $this->operation();
        $record->events()->create(['kind' => 'charged', 'changes' => ['seconds' => 120], 'created_at' => now()]);
        $project->deleteQuietly();
        $this->getJson($this->url)->assertOk()->assertJsonPath('summary.seconds', 120)
            ->assertJsonPath('sources.unknown', 1)->assertJsonPath('modes.unknown', 120);
        $this->getJson($this->url.'?source=youtube')->assertOk()->assertJsonPath('summary.seconds', 0);
    }

    public function test_detail_uses_an_honest_thumbnail_fallback_when_metadata_is_missing(): void
    {
        [$project] = $this->operation();
        $project->updateQuietly(['metadata' => ['poster' => false, 'duration' => 'unknown']]);
        $this->getJson($this->url.'/customers/'.$this->customer->id)->assertOk()
            ->assertJsonPath('recent.0.poster_url', null)->assertJsonPath('recent.0.source_duration', null);
    }
}
