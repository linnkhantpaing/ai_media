<?php

namespace Tests\Feature;

use App\Jobs\InspectMediaProject;
use App\Models\MediaProject;
use App\Models\MediaWorker;
use App\Models\User;
use App\Services\Media\AutomaticMediaWorker;
use App\Services\Media\ProviderSettings;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class AutomaticMediaWorkerTest extends TestCase
{
    use DatabaseMigrations;

    private AutomaticMediaWorker $automatic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['queue.default' => 'database', 'operations.auto_launch_allowed' => true, 'media.gemini_key' => 'test-key-for-automatic-processing']);
        $this->automatic = new class extends AutomaticMediaWorker
        {
            public int $launches = 0;

            public bool $fail = false;

            public function supported(): bool
            {
                return true;
            }

            protected function launch(): void
            {
                $this->launches++;
                if ($this->fail) {
                    throw new RuntimeException('Internal process failure must stay private.');
                }
            }
        };
        $this->app->instance(AutomaticMediaWorker::class, $this->automatic);
    }

    private function queued(): void
    {
        DB::table('jobs')->insert(['queue' => 'media', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
    }

    public function test_no_job_means_standby_without_a_running_process(): void
    {
        $this->automatic->wake();
        $this->assertSame(0, $this->automatic->launches);
        $this->assertSame('standby', $this->automatic->status()['state']);
    }

    public function test_new_work_launches_once_and_a_stale_start_is_recoverable(): void
    {
        $this->queued();
        $this->automatic->wake();
        $this->automatic->wake();
        $this->assertSame(1, $this->automatic->launches);
        $this->assertSame('starting', $this->automatic->status()['state']);
        $this->travel(11)->seconds();
        $this->automatic->wake();
        $this->assertSame(2, $this->automatic->launches);
    }

    public function test_fresh_worker_prevents_duplicates_but_stale_heartbeats_do_not_block_recovery(): void
    {
        $this->queued();
        $worker = MediaWorker::factory()->create(['heartbeat_at' => now(), 'stopped_at' => null]);
        $this->automatic->wake();
        $this->assertSame(0, $this->automatic->launches);
        $worker->update(['heartbeat_at' => now()->subSeconds(60)]);
        $this->automatic->wake();
        $this->assertSame(1, $this->automatic->launches);
    }

    public function test_disabled_saved_policy_never_starts_a_worker(): void
    {
        $settings = app(ProviderSettings::class);
        $configuration = $settings->current();
        $configuration['auto_processing'] = false;
        $settings->save($configuration, 0, User::factory()->create(['is_admin' => true])->id);
        $this->queued();
        $this->automatic->wake();
        $this->assertSame(0, $this->automatic->launches);
        $this->assertSame('manual', $this->automatic->status()['state']);
    }

    public function test_queue_dispatch_wakes_only_after_commit_and_rollback_starts_nothing(): void
    {
        $project = MediaProject::factory()->create();
        try {
            DB::transaction(function () use ($project): void {
                InspectMediaProject::dispatch($project->id)->afterCommit();
                $this->assertSame(0, $this->automatic->launches);
                throw new RuntimeException('Rollback this draft.');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, $this->automatic->launches);
        DB::transaction(function () use ($project): void {
            InspectMediaProject::dispatch($project->id)->afterCommit();
            $this->assertSame(0, $this->automatic->launches);
        });
        $this->assertSame(1, $this->automatic->launches);
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_authenticated_page_recovers_pending_jobs_after_an_app_restart(): void
    {
        $this->queued();
        $this->get('/login')->assertOk();
        $this->assertSame(0, $this->automatic->launches);
        $this->actingAs(User::factory()->create())->get('/')->assertOk();
        $this->assertSame(1, $this->automatic->launches);
    }

    public function test_launch_failure_is_sanitized_and_backed_off(): void
    {
        $this->queued();
        $this->automatic->fail = true;
        $this->automatic->wake();
        $this->automatic->wake();
        $this->assertSame(1, $this->automatic->launches);
        $this->assertSame('failed', $this->automatic->status()['state']);
        $this->assertSame('auto_worker_launch_failed', $this->automatic->status()['errorCode']);
        $this->assertStringNotContainsString('Internal', json_encode($this->automatic->status()));
        $this->travel(31)->seconds();
        $this->automatic->wake();
        $this->assertSame(2, $this->automatic->launches);
    }

    public function test_saving_worker_policy_preserves_valid_ai_checks_and_validates_bounds(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $settings = app(ProviderSettings::class);
        $configuration = $settings->current();
        $settings->report($configuration, 'text', 'success', null, $user->id);
        $settings->save($configuration, 0, $user->id);
        $current = $settings->present();
        $data = collect($current)->only(['revision', 'enabled', 'text_models', 'speech_model', 'voice', 'speech_rate', 'voice_volume', 'timeout', 'auto_processing', 'worker_idle_seconds'])->all();
        $data['credentials'] = array_map(fn (array $key): array => collect($key)->only(['id', 'label', 'enabled'])->all(), $current['credentials']);
        $data['worker_idle_seconds'] = 10;
        $this->actingAs($user, 'admin')->putJson('/admin/settings/providers', $data)->assertUnprocessable()->assertJsonValidationErrors('worker_idle_seconds');
        $data['worker_idle_seconds'] = 45;
        $this->putJson('/admin/settings/providers', $data)->assertOk()->assertJsonPath('provider.auto_processing', true)->assertJsonPath('provider.worker_idle_seconds', 45)->assertJsonPath('provider.checks.0.status', 'success')->assertJsonPath('health.automation.enabled', true);
        $this->assertSame(45, $this->automatic->policy()['idleSeconds']);
    }

    public function test_windows_launcher_preserves_system_environment_without_inheriting_request_context(): void
    {
        $previous = $_SERVER['SystemRoot'] ?? null;
        $_SERVER['SystemRoot'] = 'request-context-must-not-replace-the-os';
        $_SERVER['HTTP_COOKIE'] = 'private-request-cookie';
        try {
            $environment = (new \ReflectionMethod(AutomaticMediaWorker::class, 'windowsEnvironment'))->invoke($this->automatic);
            if (getenv('SystemRoot') !== false) {
                $this->assertSame(getenv('SystemRoot'), $environment['SystemRoot']);
            }
            $this->assertArrayNotHasKey('HTTP_COOKIE', $environment);
            $this->assertNotContains('request-context-must-not-replace-the-os', $environment);
        } finally {
            if ($previous === null) {
                unset($_SERVER['SystemRoot']);
            } else {
                $_SERVER['SystemRoot'] = $previous;
            }
            unset($_SERVER['HTTP_COOKIE']);
        }
    }

    public function test_cleanly_stopped_worker_sessions_are_history_and_not_available_capacity(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        MediaWorker::factory()->create(['stopped_at' => now(), 'heartbeat_at' => now()]);
        $this->actingAs($admin, 'admin')->getJson('/admin/settings/providers/health')->assertOk()->assertJsonPath('workers.total', 0)->assertJsonPath('workers.online', 0)->assertJsonPath('automation.state', 'standby');
        MediaWorker::factory()->create(['heartbeat_at' => now(), 'stopped_at' => null, 'automatic' => true]);
        $this->getJson('/admin/settings/providers/health')->assertOk()->assertJsonPath('workers.total', 1)->assertJsonPath('workers.online', 1);
    }
}
