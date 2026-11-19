<?php

namespace Tests\Feature;

use App\Models\MediaPreview;
use App\Models\MediaProject;
use App\Models\MediaTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomerQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        config(['filesystems.media_disk' => 'local', 'operations.auto_launch_allowed' => false]);
    }

    public function test_guests_and_admin_only_sessions_cannot_read_a_customer_queue(): void
    {
        $this->getJson('/media/queue')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['is_admin' => true]), 'admin');
        $this->getJson('/media/queue')->assertUnauthorized();
    }

    public function test_counts_and_items_include_only_owned_nonterminal_work_without_private_details(): void
    {
        $user = User::factory()->create();
        $project = MediaProject::factory()->create(['user_id' => $user->id, 'title' => 'Owned work']);
        foreach (['running', 'running', 'queued', 'retry_waiting', 'stopping'] as $status) {
            MediaTask::factory()->create(['media_project_id' => $project->id, 'type' => 'generate', 'status' => $status, 'progress' => 125, 'error_code' => 'sensitive-provider-detail']);
        }
        foreach (['completed', 'failed', 'cancelled', 'awaiting_review'] as $status) {
            MediaTask::factory()->create(['media_project_id' => $project->id, 'status' => $status]);
        }
        MediaTask::factory()->create(['media_project_id' => MediaProject::factory()->create(['title' => 'Another customer private title'])->id]);
        $response = $this->actingAs($user)->getJson('/media/queue')->assertOk()
            ->assertJsonPath('total', 5)->assertJsonPath('running', 2)->assertJsonPath('queued', 1)
            ->assertJsonPath('retrying', 1)->assertJsonPath('stopping', 1)->assertJsonCount(5, 'items')
            ->assertJsonPath('items.0.progress', 100)->assertJsonPath('items.0.title', 'Owned work')
            ->assertDontSee('Another customer private title')->assertDontSee('sensitive-provider-detail');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(['id', 'title', 'type', 'status', 'progress', 'url'], array_keys($response->json('items.0')));
    }

    public function test_count_is_not_truncated_to_the_eight_recent_items_and_completion_removes_work(): void
    {
        $project = MediaProject::factory()->create();
        MediaTask::factory()->count(12)->create(['media_project_id' => $project->id]);
        $this->actingAs($project->user)->getJson('/media/queue')->assertOk()->assertJsonPath('total', 12)->assertJsonCount(8, 'items');
        MediaTask::where('media_project_id', $project->id)->update(['status' => 'completed']);
        $this->getJson('/media/queue')->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'items');
    }

    public function test_source_and_preview_jobs_link_back_to_the_correct_owned_workspace(): void
    {
        $project = MediaProject::factory()->create();
        MediaTask::factory()->create(['media_project_id' => $project->id, 'type' => 'validation']);
        MediaTask::factory()->create(['media_project_id' => $project->id, 'type' => 'preview']);
        $rewrite = MediaPreview::factory()->create(['media_project_id' => $project->id, 'kind' => 'rewrite']);
        $rewriteTask = MediaTask::where('source_key', 'preview:'.$rewrite->id)->firstOrFail();
        $response = $this->actingAs($project->user)->getJson('/media/queue')->assertOk();
        $this->assertSame('/projects/'.$project->id.'/analyze', collect($response->json('items'))->firstWhere('id', $rewriteTask->id)['url']);
        $this->assertSame('/projects/'.$project->id.'/upload', collect($response->json('items'))->firstWhere('type', 'validation')['url']);
        $this->assertSame('/projects/'.$project->id.'/analyze?studio=render', collect($response->json('items'))->first(fn ($item) => $item['type'] === 'preview' && $item['id'] !== $rewriteTask->id)['url']);
    }

    public function test_browsing_other_forms_and_polling_never_cancel_or_consume_queued_server_work(): void
    {
        $project = MediaProject::factory()->create();
        $task = MediaTask::factory()->create(['media_project_id' => $project->id]);
        DB::table('jobs')->insert(['queue' => 'media', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
        $this->actingAs($project->user)->get('/')->assertOk();
        $this->get('/projects')->assertOk();
        $this->getJson('/media/queue')->assertOk()->assertJsonPath('total', 1);
        $this->assertSame('queued', $task->fresh()->status);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertNull(DB::table('jobs')->value('reserved_at'));
    }

    public function test_suspended_accounts_cannot_poll_queue_data(): void
    {
        $user = User::factory()->create(['account_status' => 'suspended']);
        $this->actingAs($user)->getJson('/media/queue')->assertUnauthorized()->assertJsonPath('code', 'account_unavailable');
    }
}
