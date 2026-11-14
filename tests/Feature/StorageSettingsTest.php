<?php

namespace Tests\Feature;

use App\Exceptions\MediaFailure;
use App\Jobs\RunMediaCleanup;
use App\Models\MediaProject;
use App\Models\User;
use App\Services\Media\MediaStorage;
use App\Services\Media\RetentionCleanup;
use App\Services\Media\StorageSettings;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StorageSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('r2');
        Queue::fake();
        Http::preventStrayRequests();
        config(['filesystems.media_disk' => 'local', 'filesystems.disks.r2.endpoint' => 'https://'.str_repeat('a', 32).'.r2.cloudflarestorage.com', 'filesystems.disks.r2.bucket' => 'ai-media', 'filesystems.disks.r2.root' => '', 'filesystems.disks.r2.key' => 'private-access-key-1234', 'filesystems.disks.r2.secret' => 'private-secret-value-12345678', 'media.max_upload_bytes' => 1048576, 'media.max_duration_seconds' => 600, 'media.storage_limit_bytes' => 4294967296]);
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    private function draft(array $policy = [], int $revision = 0): array
    {
        $settings = app(StorageSettings::class);

        return ['revision' => $revision, 'connection' => array_intersect_key($settings->connection(), array_flip(['name', 'endpoint', 'bucket', 'prefix'])), 'policy' => array_replace($settings->policy(), $policy), 'apply_existing' => false];
    }

    private function file(MediaProject $project, string $path = 'result.mp4', array $data = []): int
    {
        $relative = 'media/'.$project->id.'/'.$path;
        Storage::disk('local')->put($relative, 'video');

        return DB::table('media_files')->insertGetId(array_replace(['media_project_id' => $project->id, 'disk' => $project->storage_disk, 'path' => $relative, 'bytes' => 5, 'is_source' => false, 'state' => 'active', 'verified_at' => now(), 'expires_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()], $data));
    }

    private function enable(): void
    {
        $this->actingAs($this->admin, 'admin')->putJson('/admin/settings/storage', $this->draft(['retention_enabled' => true]))->assertOk();
    }

    public function test_storage_management_requires_the_admin_guard_for_all_actions(): void
    {
        $this->get('/admin/settings/storage')->assertRedirect('/admin/login');
        $this->putJson('/admin/settings/storage', $this->draft())->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'web');
        foreach (['test', 'preview', 'cleanup'] as $action) {
            $this->postJson('/admin/settings/storage/'.$action, $this->draft())->assertUnauthorized();
        }
        $this->postJson('/admin/settings/storage/runs/'.Str::uuid().'/retry')->assertUnauthorized();
        $this->getJson('/admin/settings/storage/history')->assertUnauthorized();
        $this->getJson('/admin/settings/storage/usage')->assertUnauthorized();
        $this->actingAs($this->admin, 'web')->putJson('/admin/settings/storage', $this->draft())->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'admin')->getJson('/admin/settings/storage/history')->assertForbidden();
    }

    public function test_page_is_honest_and_does_not_reveal_server_secrets(): void
    {
        $this->actingAs($this->admin, 'admin')->get('/admin/settings/storage')->assertOk()->assertInertia(fn (Assert $page) => $page->component('settings/storage')->where('configuration.policy.retention_enabled', false)->where('configuration.check', null)->where('automation.state', 'disabled')->missing('configuration.connection.secret')->missing('configuration.connection.key'))->assertDontSee('private-access-key-1234')->assertDontSee('private-secret-value-12345678');
    }

    public function test_admin_storage_links_are_generated_and_legacy_endpoints_cannot_write(): void
    {
        $this->get('/settings/storage')->assertRedirect('/admin/settings/storage');
        $this->actingAs($this->admin, 'admin')->get('/admin/settings/storage')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('adminLinks.storage', '/admin/settings/storage')
            ->where('adminLinks.providers', '/admin/settings/providers')
            ->where('linksStorage.save', '/admin/settings/storage')
            ->where('linksStorage.preview', '/admin/settings/storage/preview')
            ->where('linksStorage.cleanup', '/admin/settings/storage/cleanup')
            ->where('linksStorage.history', '/admin/settings/storage/history')
            ->where('linksStorage.usage', '/admin/settings/storage/usage')
            ->where('linksStorage.retry', '/admin/settings/storage/runs/__RUN__/retry')
            ->where('links.logout', '/admin/logout')->missing('frameLinks.storageRetention'));
        $this->putJson('/settings/storage', $this->draft())->assertStatus(405);
        foreach (['test', 'preview', 'cleanup'] as $action) {
            $this->postJson('/settings/storage/'.$action, $this->draft())->assertNotFound();
        }
        $this->postJson('/settings/storage/runs/'.Str::uuid().'/retry')->assertNotFound();
        $this->assertSame(0, app(StorageSettings::class)->present()['revision']);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_moved_form_reuses_saved_connection_policy_and_files_without_migration(): void
    {
        $settings = app(StorageSettings::class);
        $settings->save($this->draft(['budget' => 8589934592]), $this->admin->id);
        $head = (array) DB::table('media_storage_settings')->find(1);
        $profiles = DB::table('media_storage_profiles')->count();
        $project = MediaProject::factory()->create(['status' => 'completed', 'storage_disk' => 'local']);
        $fileId = $this->file($project);
        $this->actingAs($this->admin, 'admin')->get('/admin/settings/storage')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('configuration.revision', 1)->where('configuration.policy.budget', 8589934592)
            ->missing('configuration.connection.key')->missing('configuration.connection.secret'));
        $this->assertSame($head, (array) DB::table('media_storage_settings')->find(1));
        $this->assertSame($profiles, DB::table('media_storage_profiles')->count());
        $this->assertSame('active', DB::table('media_files')->find($fileId)->state);
        Storage::disk('local')->assertExists('media/'.$project->id.'/result.mp4');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_admin_storage_changes_apply_to_a_separate_customers_new_uploads(): void
    {
        $customer = User::factory()->create(['is_admin' => false]);
        $this->actingAs($customer, 'web');
        $old = $this->postJson('/media/projects', ['client_id' => (string) Str::uuid(), 'filename' => 'old.mp4', 'size' => 12])->assertCreated()->json('project.id');
        $this->actingAs($this->admin, 'admin')->putJson('/admin/settings/storage', $this->draft(['max_duration' => 120]))->assertOk();
        $this->assertDatabaseHas('media_storage_settings', ['id' => 1, 'updated_by' => $this->admin->id]);
        $this->actingAs($customer, 'web');
        $new = $this->postJson('/media/projects', ['client_id' => (string) Str::uuid(), 'filename' => 'new.mp4', 'size' => 12])->assertCreated()->json('project.id');
        $this->assertSame(600, MediaProject::findOrFail($old)->storage_policy['max_duration']);
        $this->assertSame(120, MediaProject::findOrFail($new)->storage_policy['max_duration']);
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->missing('frameLinks.storageRetention')->missing('configuration'));
        $this->actingAs($this->admin, 'admin')->postJson('/admin/settings/storage/preview')->assertOk();
        $this->assertSame($this->admin->id, DB::table('media_cleanup_previews')->latest('created_at')->first()->user_id);
    }

    public function test_storage_mutations_require_csrf_outside_the_testing_bypass(): void
    {
        $data = $this->draft();
        $this->actingAs($this->admin, 'admin');
        $this->app['env'] = 'local';
        $this->putJson('/admin/settings/storage', $data)->assertStatus(419);
        foreach (['test', 'preview', 'cleanup'] as $action) {
            $this->postJson('/admin/settings/storage/'.$action, $data)->assertStatus(419);
        }
        $this->postJson('/admin/settings/storage/runs/'.Str::uuid().'/retry')->assertStatus(419);
        $this->assertSame(0, app(StorageSettings::class)->present()['revision']);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_initial_save_imports_encrypted_configuration_without_remote_calls_or_deleting_files(): void
    {
        $project = MediaProject::factory()->create(['storage_disk' => 'local', 'status' => 'completed']);
        $id = $this->file($project);
        $this->actingAs($this->admin, 'admin')->putJson('/admin/settings/storage', $this->draft())->assertOk()->assertJsonPath('configuration.revision', 1);
        $this->assertNotNull($project->fresh()->storage_profile_id);
        $this->assertNotNull($project->fresh()->storage_policy);
        $encrypted = DB::table('media_storage_profiles')->value('configuration');
        $this->assertStringNotContainsString('private-secret-value', $encrypted);
        $this->assertSame('active', DB::table('media_files')->find($id)->state);
        Storage::disk('local')->assertExists('media/'.$project->id.'/result.mp4');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_changed_connection_requires_its_exact_successful_candidate_test_and_revision(): void
    {
        $data = $this->draft();
        $data['connection']['name'] = 'New profile';
        $this->actingAs($this->admin, 'admin')->putJson('/admin/settings/storage', $data)->assertUnprocessable()->assertJsonValidationErrors('connection');
        $this->postJson('/admin/settings/storage/test', $data)->assertOk()->assertJsonPath('report.success', true);
        $this->assertSame([], Storage::disk('local')->allFiles('checks'));
        $this->putJson('/admin/settings/storage', $data)->assertOk();
        $this->putJson('/admin/settings/storage', $data)->assertConflict();
        $this->assertSame('New profile', app(StorageSettings::class)->connection()['name']);
    }

    public function test_test_results_cannot_be_reused_by_another_admin_or_different_values(): void
    {
        $data = $this->draft();
        $data['connection']['name'] = 'Tested';
        $this->actingAs($this->admin, 'admin')->postJson('/admin/settings/storage/test', $data)->assertOk();
        $this->actingAs(User::factory()->create(['is_admin' => true]), 'admin')->putJson('/admin/settings/storage', $data)->assertUnprocessable();
        $data['connection']['name'] = 'Changed after test';
        $this->actingAs($this->admin, 'admin')->putJson('/admin/settings/storage', $data)->assertUnprocessable();
    }

    public function test_untrusted_endpoints_traversal_and_invalid_policy_are_rejected(): void
    {
        $this->actingAs($this->admin, 'admin');
        foreach (['http://127.0.0.1', 'https://example.com', 'https://'.str_repeat('a', 32).'.r2.cloudflarestorage.com/ai-media'] as $endpoint) {
            $data = $this->draft();
            $data['connection']['endpoint'] = $endpoint;
            $this->postJson('/admin/settings/storage/test', $data)->assertUnprocessable();
        }
        foreach ([['keep_days' => 40, 'source_days' => 30], ['source_days' => 60, 'artifact_days' => 30], ['max_duration' => 5000], ['max_bytes' => 2147483649]] as $policy) {
            $this->putJson('/admin/settings/storage', $this->draft($policy))->assertUnprocessable();
        }
        $data = $this->draft();
        $data['connection']['prefix'] = '../pos';
        $this->putJson('/admin/settings/storage', $data)->assertUnprocessable();
    }

    public function test_upload_limits_are_live_and_existing_accepted_projects_keep_the_old_policy(): void
    {
        $this->actingAs($this->admin, 'admin');
        $input = ['client_id' => (string) Str::uuid(), 'filename' => 'test.mp4', 'size' => 12];
        $old = $this->actingAs($this->admin, 'web')->postJson('/media/projects', $input)->assertCreated()->json('project.id');
        $this->putJson('/admin/settings/storage', $this->draft(['max_bytes' => 2097152, 'max_duration' => 120]))->assertOk();
        $this->assertSame(600, MediaProject::find($old)->storage_policy['max_duration']);
        $this->actingAs($this->admin, 'web')->postJson('/media/projects', ['client_id' => (string) Str::uuid(), 'filename' => 'new.mp4', 'size' => 2097153])->assertUnprocessable();
        $new = $this->actingAs($this->admin, 'web')->postJson('/media/projects', [...$input, 'client_id' => (string) Str::uuid()])->assertCreated()->json('project.id');
        $this->assertSame(120, MediaProject::find($new)->storage_policy['max_duration']);
    }

    public function test_dry_run_does_not_delete_and_confirmation_is_scoped_and_single_use(): void
    {
        $this->enable();
        $project = MediaProject::factory()->create(['status' => 'completed', 'storage_disk' => 'local']);
        $file = $this->file($project);
        $preview = $this->postJson('/admin/settings/storage/preview')->assertOk()->assertJsonCount(1, 'preview.files')->json('preview.id');
        $this->assertSame('active', DB::table('media_files')->find($file)->state);
        $this->postJson('/admin/settings/storage/cleanup', ['preview' => $preview])->assertUnprocessable();
        $run = $this->postJson('/admin/settings/storage/cleanup', ['preview' => $preview, 'confirm' => true])->assertAccepted()->json('runId');
        Queue::assertPushed(RunMediaCleanup::class);
        app(RetentionCleanup::class)->execute($run);
        $this->assertSame('deleted', DB::table('media_files')->find($file)->state);
        Storage::disk('local')->assertMissing('media/'.$project->id.'/result.mp4');
        $this->postJson('/admin/settings/storage/cleanup', ['preview' => $preview, 'confirm' => true])->assertConflict();
    }

    public function test_files_are_rechecked_when_a_project_becomes_active_after_preview(): void
    {
        $this->enable();
        $project = MediaProject::factory()->create(['status' => 'completed', 'storage_disk' => 'local']);
        $file = $this->file($project);
        $preview = $this->postJson('/admin/settings/storage/preview')->json('preview.id');
        $project->update(['status' => 'rendering']);
        $run = $this->postJson('/admin/settings/storage/cleanup', ['preview' => $preview, 'confirm' => true])->json('runId');
        app(RetentionCleanup::class)->execute($run);
        $this->assertSame('active', DB::table('media_files')->find($file)->state);
        Storage::disk('local')->assertExists('media/'.$project->id.'/result.mp4');
    }

    public function test_review_projects_untracked_files_and_outside_paths_are_protected(): void
    {
        $this->enable();
        $project = MediaProject::factory()->create(['status' => 'awaiting_review', 'storage_disk' => 'local']);
        $this->file($project);
        Storage::disk('local')->put('unrelated/keep.txt', 'keep');
        $other = MediaProject::factory()->create(['status' => 'completed', 'storage_disk' => 'local']);
        $this->file($other, '../outside.mp4');
        $this->postJson('/admin/settings/storage/preview')->assertOk()->assertJsonCount(0, 'preview.files');
        Storage::disk('local')->assertExists('unrelated/keep.txt');
    }

    public function test_expired_files_cannot_be_downloaded_from_the_local_cache(): void
    {
        $this->enable();
        $project = MediaProject::factory()->create(['user_id' => $this->admin->id, 'status' => 'completed', 'storage_disk' => 'local']);
        $path = 'media/'.$project->id.'/result.mp4';
        $project->update(['output_path' => $path]);
        $this->file($project);
        $this->actingAs($this->admin, 'web')->get('/media/projects/'.$project->id.'/files/video')->assertNotFound();
    }

    public function test_published_new_projects_receive_deadlines_but_older_policies_remain_unchanged(): void
    {
        $this->enable();
        $project = MediaProject::factory()->create(['status' => 'completed', 'storage_disk' => 'local', 'storage_policy' => app(StorageSettings::class)->policy()]);
        $id = $this->file($project, 'source.mp4', ['is_source' => true, 'expires_at' => null]);
        app(RetentionCleanup::class)->published($project);
        $this->assertNotNull(DB::table('media_files')->find($id)->expires_at);
        $older = MediaProject::factory()->create(['status' => 'completed', 'storage_policy' => [...app(StorageSettings::class)->policy(), 'retention_enabled' => false]]);
        app(RetentionCleanup::class)->published($older);
        $this->assertNull($older->fresh()->source_expires_at);
    }

    public function test_automation_does_not_claim_ready_without_scheduler_heartbeat(): void
    {
        $this->enable();
        $this->getJson('/admin/settings/storage/usage')->assertOk()->assertJsonPath('automation.state', 'setup_required');
    }

    public function test_failed_deletion_keeps_inventory_and_capacity_until_a_successful_retry(): void
    {
        $this->enable();
        $project = MediaProject::factory()->create(['status' => 'completed', 'storage_disk' => 'r2']);
        $id = $this->file($project);
        $good = Storage::disk('r2');
        $bad = \Mockery::mock(FilesystemAdapter::class);
        $bad->shouldReceive('delete')->andThrow(new \RuntimeException('private failure'));
        Storage::set('r2', $bad);
        $preview = $this->postJson('/admin/settings/storage/preview')->json('preview.id');
        $run = $this->postJson('/admin/settings/storage/cleanup', ['preview' => $preview, 'confirm' => true])->json('runId');
        app(RetentionCleanup::class)->execute($run);
        $this->assertSame('pending_delete', DB::table('media_files')->find($id)->state);
        $this->assertSame('partial', DB::table('media_cleanup_runs')->find($run)->status);
        $before = app(MediaStorage::class)->reservedBytes();
        Storage::set('r2', $good);
        $retry = $this->postJson('/admin/settings/storage/runs/'.$run.'/retry')->assertAccepted()->json('runId');
        app(RetentionCleanup::class)->execute($retry);
        $this->assertSame('deleted', DB::table('media_files')->find($id)->state);
        $this->assertSame($before - 5, app(MediaStorage::class)->reservedBytes());
    }

    public function test_verified_cache_cleanup_keeps_remote_data_and_inventory_restorable(): void
    {
        $this->enable();
        $project = MediaProject::factory()->create(['status' => 'completed', 'storage_disk' => 'r2', 'storage_policy' => app(StorageSettings::class)->policy()]);
        $id = $this->file($project, 'source.mp4', ['is_source' => true, 'expires_at' => null]);
        $path = 'media/'.$project->id.'/source.mp4';
        Storage::disk('r2')->put($path, 'video');
        touch(Storage::disk('local')->path($path), now()->subHours(48)->timestamp);
        $preview = $this->postJson('/admin/settings/storage/preview')->assertJsonCount(1, 'preview.files')->assertJsonPath('preview.files.0.kind', 'cache')->json('preview.id');
        $run = $this->postJson('/admin/settings/storage/cleanup', ['preview' => $preview, 'confirm' => true])->json('runId');
        app(RetentionCleanup::class)->execute($run);
        Storage::disk('local')->assertMissing($path);
        Storage::disk('r2')->assertExists($path);
        $this->assertSame('active', DB::table('media_files')->find($id)->state);
        $this->assertFileExists(app(MediaStorage::class)->materialize($project, $path));
    }

    public function test_new_connection_does_not_change_the_profile_of_existing_projects(): void
    {
        config(['filesystems.media_disk' => 'r2']);
        $this->actingAs($this->admin, 'admin');
        $input = ['client_id' => (string) Str::uuid(), 'filename' => 'before.mp4', 'size' => 12];
        $old = $this->actingAs($this->admin, 'web')->postJson('/media/projects', $input)->assertCreated()->json('project.id');
        $oldProfile = MediaProject::find($old)->storage_profile_id;
        $data = $this->draft();
        $data['connection']['bucket'] = 'new-media';
        $disk = Storage::disk('r2');
        $manager = Storage::getFacadeRoot();
        $proxy = \Mockery::mock($manager);
        $proxy->shouldReceive('build')->andReturn($disk);
        Storage::swap($proxy);
        $this->postJson('/admin/settings/storage/test', $data)->assertOk()->assertJsonPath('report.success', true);
        $this->putJson('/admin/settings/storage', $data)->assertOk();
        $new = $this->actingAs($this->admin, 'web')->postJson('/media/projects', [...$input, 'client_id' => (string) Str::uuid()])->assertCreated()->json('project.id');
        $this->assertSame($oldProfile, MediaProject::find($old)->storage_profile_id);
        $this->assertSame('ai-media', app(StorageSettings::class)->profile($oldProfile)['bucket']);
        $this->assertSame('new-media', app(StorageSettings::class)->profile(MediaProject::find($new)->storage_profile_id)['bucket']);
    }

    public function test_stale_cleanup_preview_cannot_delete_after_policy_changes(): void
    {
        $this->enable();
        $project = MediaProject::factory()->create(['status' => 'completed', 'storage_disk' => 'local']);
        $id = $this->file($project);
        $preview = $this->postJson('/admin/settings/storage/preview')->json('preview.id');
        $this->putJson('/admin/settings/storage', $this->draft(['keep_days' => 14], 1))->assertOk();
        $this->postJson('/admin/settings/storage/cleanup', ['preview' => $preview, 'confirm' => true])->assertConflict();
        $this->assertSame('active', DB::table('media_files')->find($id)->state);
    }

    public function test_a_lower_new_upload_limit_does_not_break_an_accepted_resumable_upload(): void
    {
        config(['media.max_upload_bytes' => 2097152]);
        $this->actingAs($this->admin, 'admin');
        $input = ['client_id' => (string) Str::uuid(), 'filename' => 'accepted.mp4', 'size' => 1500000];
        $id = $this->actingAs($this->admin, 'web')->postJson('/media/projects', $input)->assertCreated()->json('project.id');
        $this->putJson('/admin/settings/storage', $this->draft(['max_bytes' => 1048576]))->assertOk();
        $this->actingAs($this->admin, 'web')->postJson('/media/projects', $input)->assertCreated()->assertJsonPath('project.id', $id);
        $this->actingAs($this->admin, 'web')->postJson('/media/projects', [...$input, 'client_id' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_importing_storage_configuration_does_not_reorder_existing_project_history(): void
    {
        $project = MediaProject::factory()->create(['status' => 'completed', 'storage_disk' => 'local', 'updated_at' => now()->subDays(3)]);
        $before = $project->updated_at->toIso8601String();
        $this->actingAs($this->admin, 'admin')->putJson('/admin/settings/storage', $this->draft())->assertOk();
        $this->assertSame($before, $project->fresh()->updated_at->toIso8601String());
    }

    public function test_expired_untracked_preview_artifacts_cannot_be_read_from_the_local_cache(): void
    {
        $this->enable();
        $project = MediaProject::factory()->create(['status' => 'completed', 'storage_disk' => 'local', 'artifacts_expires_at' => now()->subDay()]);
        $path = 'media/'.$project->id.'/preview/voice.wav';
        Storage::disk('local')->put($path, 'private audio');
        $this->expectException(MediaFailure::class);
        app(MediaStorage::class)->materialize($project, $path);
    }

    public function test_read_only_history_can_poll_without_using_the_mutation_rate_limit(): void
    {
        $this->actingAs($this->admin, 'admin');
        for ($i = 0; $i < 12; $i++) {
            $this->getJson('/admin/settings/storage/history')->assertOk();
        }
    }
}
