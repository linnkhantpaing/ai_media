<?php

namespace Tests\Feature;

use App\Models\MediaProject;
use App\Models\MediaTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupportInboxTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Mail::fake();
        Notification::fake();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->customer = User::factory()->create();
        $this->actingAs($this->admin, 'admin');
    }

    private function data(array $extra = []): array
    {
        return ['customer_id' => $this->customer->id, 'subject' => 'Export question', 'category' => 'video',
            'priority' => 'normal', 'body' => 'How can I help?', 'request_id' => (string) Str::uuid(), ...$extra];
    }

    private function conversation(): int
    {
        return $this->postJson('/admin/support', $this->data())->assertCreated()->json('id');
    }

    private function incoming(int $id, string $text = 'Please help'): int
    {
        $this->actingAs($this->customer, 'web');
        $message = $this->postJson('/support-api/'.$id.'/messages', ['body' => $text, 'request_id' => (string) Str::uuid()])->assertCreated()->json('id');
        $this->actingAs($this->admin, 'admin');

        return $message;
    }

    public function test_customer_chat_combines_owned_cases_and_routes_quoted_replies(): void
    {
        $first = $this->conversation();
        $firstMessage = $this->getJson('/admin/support/'.$first)->json('messages.0.id');
        $this->travel(1)->day();
        $second = $this->postJson('/admin/support', $this->data(['body' => 'A later admin reply']))->assertCreated()->json('id');
        $secondMessage = $this->getJson('/admin/support/'.$second)->json('messages.0.id');
        $private = $this->postJson('/admin/support/'.$first.'/messages', ['kind' => 'note', 'body' => 'Private admin note', 'request_id' => (string) Str::uuid()])->assertCreated()->json('id');
        $other = $this->postJson('/admin/support', $this->data(['customer_id' => User::factory()->create()->id, 'body' => 'Other customer']))->assertCreated()->json('id');
        $foreign = $this->getJson('/admin/support/'.$other)->json('messages.0.id');
        auth('admin')->logout();
        $this->actingAs($this->customer, 'web');

        $this->getJson('/support-api/chat?badge=1')->assertExactJson(['unread' => 2]);
        $timeline = $this->getJson('/support-api/chat')->assertOk()->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.0.id', $firstMessage)->assertJsonPath('messages.1.id', $secondMessage);
        $this->assertNotSame(substr($timeline->json('messages.0.at'), 0, 10), substr($timeline->json('messages.1.at'), 0, 10));
        $this->postJson('/support-api/chat/read', ['through' => $firstMessage])->assertExactJson(['unread' => 1]);
        $this->postJson('/support-api/chat/read', ['through' => $secondMessage])->assertExactJson(['unread' => 0]);
        $this->postJson('/support-api/chat/read', ['through' => $foreign])->assertNotFound();

        foreach ([$foreign, $private] as $target) {
            $this->postJson('/support-api/chat/messages', ['body' => 'Reply', 'reply_to' => $target, 'request_id' => (string) Str::uuid()])
                ->assertUnprocessable()->assertJsonValidationErrors('reply_to');
        }

        $reply = ['body' => 'Reply to the older message', 'reply_to' => $firstMessage, 'request_id' => (string) Str::uuid()];
        $posted = $this->postJson('/support-api/chat/messages', $reply)->assertCreated()->json('id');
        $this->postJson('/support-api/chat/messages', $reply)->assertCreated()->assertJsonPath('id', $posted);
        $this->assertDatabaseHas('support_messages', ['id' => $posted, 'conversation_id' => $first, 'reply_to' => $firstMessage]);
        $this->getJson('/support-api/chat?after='.$secondMessage)->assertOk()
            ->assertJsonCount(1, 'messages')->assertJsonPath('messages.0.reply.id', $firstMessage);
        $this->travelBack();
    }

    public function test_customer_chat_reuses_initial_case_and_syncs_edits_and_deleted_quotes(): void
    {
        auth('admin')->logout();
        $this->actingAs($this->customer, 'web');
        $this->getJson('/support-api/chat')
            ->assertJsonCount(0, 'messages')
            ->assertJsonCount(0, 'changes')
            ->assertJsonPath('has_older', false)
            ->assertJsonPath('has_newer', false)
            ->assertJsonPath('reset', false)
            ->assertJsonPath('unread', 0);
        $firstData = ['body' => 'First customer message', 'request_id' => (string) Str::uuid()];
        $first = $this->postJson('/support-api/chat/messages', $firstData)->assertCreated()->json('id');
        $this->postJson('/support-api/chat/messages', $firstData)->assertCreated()->assertJsonPath('id', $first);
        $followup = ['body' => 'Follow-up', 'request_id' => (string) Str::uuid()];
        $this->postJson('/support-api/chat/messages', $followup)->assertCreated();
        $this->assertDatabaseCount('support_conversations', 1);
        $id = DB::table('support_conversations')->value('id');
        $this->actingAs($this->admin, 'admin');
        $adminReply = $this->postJson('/admin/support/'.$id.'/messages', ['kind' => 'reply', 'body' => 'Received',
            'reply_to' => $first, 'request_id' => (string) Str::uuid()])->assertCreated()->json('id');
        auth('admin')->logout();
        $this->actingAs($this->customer, 'web');
        $snapshot = $this->getJson('/support-api/chat')->assertJsonPath('messages.0.can_edit', true)->json('snapshot');
        $this->patchJson('/support-api/chat/messages/'.$adminReply, ['body' => 'Other sender'])->assertForbidden();
        $this->deleteJson('/support-api/chat/messages/'.$adminReply)->assertForbidden();
        $this->patchJson('/support-api/chat/messages/'.$first, ['body' => ''])->assertUnprocessable();
        $this->patchJson('/support-api/chat/messages/'.$first, ['body' => 'Edited text'])->assertOk();
        $url = '/support-api/chat?after='.$adminReply.'&loaded_from='.$first.'&since='.$snapshot;
        $this->getJson($url)->assertJsonPath('changes.0.reply.body', 'Edited text')
            ->assertJsonPath('changes.2.edited', true);
        $this->actingAs(User::factory()->create(), 'web');
        $this->patchJson('/support-api/chat/messages/'.$first, ['body' => 'Other account'])->assertNotFound();
        $this->deleteJson('/support-api/chat/messages/'.$first)->assertNotFound();
        $this->actingAs($this->customer, 'web');
        $this->deleteJson('/support-api/chat/messages/'.$first)->assertOk();
        $this->deleteJson('/support-api/chat/messages/'.$first)->assertOk();
        $this->postJson('/support-api/chat/messages', $firstData)->assertCreated()->assertJsonPath('id', $first);
        $this->patchJson('/support-api/chat/messages/'.$first, ['body' => 'Restore'])->assertConflict();
        $this->getJson($url)->assertJsonPath('changes.0.reply.deleted', true)->assertJsonPath('changes.0.reply.body', '');
        $this->assertDatabaseHas('support_messages', ['id' => $first, 'body' => '']);
        $this->actingAs($this->admin, 'admin');
        $this->getJson('/admin/support/'.$id)->assertJsonPath('messages.0.deleted', true)->assertJsonPath('messages.2.reply.deleted', true);
    }

    public function test_deleting_own_reply_redacts_attachments_quotes_and_is_idempotent(): void
    {
        $id = $this->post('/admin/support', $this->data(['body' => 'delete-content-canary', 'files' => [UploadedFile::fake()->image('proof.png')]]), ['Accept' => 'application/json'])->assertCreated()->json('id');
        $detail = $this->getJson('/admin/support/'.$id)->assertOk()->assertJsonPath('messages.0.can_delete', true);
        $message = $detail->json('messages.0.id');
        $url = $detail->json('messages.0.attachments.0.url');
        $this->actingAs($this->customer, 'web');
        $this->postJson('/support-api/'.$id.'/messages', ['body' => 'A quoted reply', 'reply_to' => $message, 'request_id' => (string) Str::uuid()])->assertCreated();
        $this->actingAs($this->admin, 'admin');
        $this->deleteJson('/admin/support/'.$id.'/messages/'.$message)->assertOk();
        $this->deleteJson('/admin/support/'.$id.'/messages/'.$message)->assertOk();
        $this->assertDatabaseHas('support_messages', ['id' => $message, 'body' => '']);
        $this->assertNotNull(DB::table('support_messages')->where('id', $message)->value('deleted_at'));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'support.message_deleted')->count());
        $this->get($url)->assertNotFound();
        $result = $this->getJson('/admin/support/'.$id)->assertJsonPath('messages.0.deleted', true)
            ->assertJsonPath('messages.0.can_delete', false)->assertJsonCount(0, 'messages.0.attachments')
            ->assertJsonPath('messages.1.reply.deleted', true)->assertJsonPath('messages.1.reply.body', '');
        $this->assertStringNotContainsString('delete-content-canary', $result->getContent());
        auth('admin')->logout();
        $this->actingAs($this->customer, 'web');
        $result = $this->getJson('/support-api/'.$id)->assertOk()->assertJsonPath('messages.0.deleted', true)->assertJsonCount(0, 'messages.0.attachments');
        $this->assertStringNotContainsString('delete-content-canary', $result->getContent());
        $attachment = basename($url);
        $this->get('/support-api/'.$id.'/attachments/'.$attachment)->assertNotFound();
        $this->postJson('/support-api/'.$id.'/messages', ['body' => 'Quote unavailable message', 'reply_to' => $message, 'request_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('reply_to');
        $this->getJson('/support-api')->assertJsonPath('counts.unread', 0);
    }

    public function test_deletion_is_scoped_to_own_admin_messages_and_private_notes_stay_private(): void
    {
        $id = $this->conversation();
        $original = $this->getJson('/admin/support/'.$id)->json('messages.0.id');
        $incoming = $this->incoming($id);
        $this->deleteJson('/admin/support/'.$id.'/messages/'.$incoming)->assertForbidden();
        $other = $this->conversation();
        $this->deleteJson('/admin/support/'.$other.'/messages/'.$original)->assertNotFound();
        $this->actingAs(User::factory()->create(['is_admin' => true]), 'admin');
        $this->getJson('/admin/support/'.$id)->assertJsonPath('messages.0.can_delete', false);
        $this->deleteJson('/admin/support/'.$id.'/messages/'.$original)->assertForbidden();
        $this->actingAs($this->admin, 'admin');
        $note = $this->postJson('/admin/support/'.$id.'/messages', ['kind' => 'note', 'body' => 'private-deletion-canary', 'request_id' => (string) Str::uuid()])->assertCreated()->json('id');
        $this->deleteJson('/admin/support/'.$id.'/messages/'.$note)->assertOk();
        auth('admin')->logout();
        $this->actingAs($this->customer, 'web');
        $this->deleteJson('/admin/support/'.$id.'/messages/'.$original)->assertUnauthorized();
        $result = $this->getJson('/support-api/'.$id)->assertJsonCount(2, 'messages')->assertJsonCount(0, 'deleted_ids');
        $this->assertStringNotContainsString('private-deletion-canary', $result->getContent());
    }

    public function test_deleted_replies_cannot_be_restored_by_send_replay_and_preview_is_a_tombstone(): void
    {
        $id = $this->conversation();
        $data = ['kind' => 'reply', 'body' => 'replay-deletion-canary', 'request_id' => (string) Str::uuid()];
        $message = $this->postJson('/admin/support/'.$id.'/messages', $data)->assertCreated()->json('id');
        $this->deleteJson('/admin/support/'.$id.'/messages/'.$message)->assertOk();
        $this->postJson('/admin/support/'.$id.'/messages', $data)->assertCreated()->assertJsonPath('id', $message);
        $this->getJson('/admin/support')->assertJsonPath('items.0.preview', '')->assertJsonPath('items.0.preview_deleted', true)->assertJsonPath('items.0.preview_sender', 'admin');
        $this->getJson('/admin/support/'.$id)->assertJsonPath('messages.1.deleted', true)->assertJsonPath('messages.1.body', '');
        $this->assertDatabaseCount('support_messages', 2);
    }

    public function test_poll_reports_deleted_messages_in_loaded_older_history(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $id = $this->conversation();
        $first = $this->getJson('/admin/support/'.$id)->json('messages.0.id');
        for ($i = 0; $i < 61; $i++) {
            $this->postJson('/admin/support/'.$id.'/messages', ['kind' => 'reply', 'body' => 'Reply '.$i, 'request_id' => (string) Str::uuid()])->assertCreated();
        }
        $this->deleteJson('/admin/support/'.$id.'/messages/'.$first)->assertOk();
        $this->getJson('/admin/support/'.$id)->assertJsonCount(60, 'messages')->assertJsonCount(0, 'deleted_ids');
        $this->getJson('/admin/support/'.$id.'?loaded_from='.$first)->assertJsonPath('deleted_ids.0', $first);
    }

    public function test_admin_only_routes_and_customer_owner_isolation(): void
    {
        $id = $this->conversation();
        auth('admin')->logout();
        $this->get('/admin/support')->assertRedirect('/admin/login');
        $other = User::factory()->create();
        $this->actingAs($other, 'web');
        $this->getJson('/admin/support')->assertUnauthorized();
        $this->getJson('/support-api/'.$id)->assertNotFound();
        $this->postJson('/support-api/'.$id.'/messages', ['body' => 'intrude', 'request_id' => (string) Str::uuid()])->assertNotFound();
        $this->postJson('/support-api/'.$id.'/read', ['through' => 0])->assertNotFound();
        $this->getJson('/support-api')->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_create_replay_is_idempotent_and_conflicting_replay_is_rejected(): void
    {
        $data = $this->data();
        $id = $this->postJson('/admin/support', $data)->assertCreated()->json('id');
        $this->postJson('/admin/support', $data)->assertCreated()->assertJsonPath('id', $id);
        $this->postJson('/admin/support', [...$data, 'body' => 'different'])->assertConflict();
        $this->assertDatabaseCount('support_conversations', 1);
        $this->assertDatabaseCount('support_messages', 1);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_unread_and_needs_reply_are_separate_and_read_acknowledgements_are_monotonic(): void
    {
        $id = $this->conversation();
        $this->getJson('/admin/support')->assertJsonPath('items.0.preview', 'How can I help?')->assertJsonPath('items.0.preview_sender', 'admin');
        $first = $this->incoming($id);
        $second = $this->incoming($id, 'Another detail');
        $this->getJson('/admin/support')->assertOk()->assertJsonPath('counts.unread', 1)->assertJsonPath('counts.needs_reply', 1)->assertJsonPath('items.0.unread', 2);
        $this->getJson('/admin/support/'.$id)->assertOk();
        $this->getJson('/admin/support')->assertJsonPath('counts.unread', 1);
        $this->postJson('/admin/support/'.$id.'/read', ['through' => $second])->assertOk();
        $this->postJson('/admin/support/'.$id.'/read', ['through' => $first])->assertOk();
        $this->getJson('/admin/support')->assertJsonPath('counts.unread', 0)->assertJsonPath('counts.needs_reply', 1);
        $this->postJson('/admin/support/'.$id.'/messages', ['kind' => 'note', 'body' => 'Internal investigation', 'request_id' => (string) Str::uuid()])->assertCreated();
        $this->getJson('/admin/support')->assertJsonPath('counts.needs_reply', 1)->assertJsonPath('items.0.preview', 'Another detail')->assertJsonPath('items.0.preview_sender', 'customer');
        $this->postJson('/admin/support/'.$id.'/messages', ['kind' => 'reply', 'body' => 'Please retry', 'request_id' => (string) Str::uuid()])->assertCreated();
        $this->getJson('/admin/support')->assertJsonPath('counts.needs_reply', 0)->assertJsonPath('items.0.status', 'waiting_customer')->assertJsonPath('items.0.preview', 'Please retry')->assertJsonPath('items.0.preview_sender', 'admin');
    }

    public function test_notes_and_their_files_are_never_visible_to_customer_and_cannot_be_quoted_publicly(): void
    {
        $id = $this->conversation();
        $note = $this->post('/admin/support/'.$id.'/messages', ['kind' => 'note', 'body' => 'private-note-canary',
            'files' => [UploadedFile::fake()->image('private.png')], 'request_id' => (string) Str::uuid()], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $attachment = DB::table('support_attachments')->where('message_id', $note)->value('id');
        $this->postJson('/admin/support/'.$id.'/messages', ['kind' => 'reply', 'body' => 'public', 'reply_to' => $note, 'request_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('reply_to');
        auth('admin')->logout();
        $this->actingAs($this->customer, 'web');
        $result = $this->getJson('/support-api/'.$id)->assertOk();
        $this->assertStringNotContainsString('private-note-canary', $result->getContent());
        $this->assertNull($result->json('context'));
        $this->get('/support-api/'.$id.'/attachments/'.$attachment)->assertNotFound();
        $this->postJson('/support-api/'.$id.'/messages', ['kind' => 'note', 'body' => 'bad', 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson('/support-api/'.$id.'/messages', ['body' => 'bad', 'reply_to' => $note, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_scoped_private_attachments_and_validation(): void
    {
        $data = $this->data(['files' => [UploadedFile::fake()->image('proof.png')]]);
        $id = $this->post('/admin/support', $data, ['Accept' => 'application/json'])->assertCreated()->json('id');
        $detail = $this->getJson('/admin/support/'.$id)->assertOk();
        $url = $detail->json('messages.0.attachments.0.url');
        $this->assertStringNotContainsString('support-attachments/', $detail->getContent());
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $other = $this->conversation();
        $aid = $detail->json('messages.0.attachments.0.id');
        $this->get('/admin/support/'.$other.'/attachments/'.$aid)->assertNotFound();
        $this->post('/admin/support', $this->data(['files' => [UploadedFile::fake()->create('attack.svg', 1, 'image/svg+xml')]]),
            ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('files.0');
        $this->post('/admin/support', $this->data(['files' => [UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf')]]),
            ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('files.0');
        auth('admin')->logout();
        $this->actingAs(User::factory()->create(), 'web');
        $this->get('/support-api/'.$id.'/attachments/'.$aid)->assertNotFound();
    }

    public function test_replies_are_idempotent_and_cross_conversation_quote_is_rejected(): void
    {
        $id = $this->conversation();
        $other = $this->conversation();
        $foreign = DB::table('support_messages')->where('conversation_id', $other)->value('id');
        $data = ['kind' => 'reply', 'body' => 'Hello again', 'request_id' => (string) Str::uuid()];
        $message = $this->postJson('/admin/support/'.$id.'/messages', $data)->assertCreated()->json('id');
        $this->postJson('/admin/support/'.$id.'/messages', $data)->assertCreated()->assertJsonPath('id', $message);
        $this->postJson('/admin/support/'.$id.'/messages', [...$data, 'body' => 'changed'])->assertConflict();
        $this->postJson('/admin/support/'.$id.'/messages', [...$data, 'reply_to' => $foreign, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson('/admin/support/'.$id.'/read', ['through' => $foreign])->assertUnprocessable();
        $this->assertDatabaseCount('support_messages', 3);
    }

    public function test_status_revision_resolve_and_customer_reopen(): void
    {
        $id = $this->conversation();
        $this->patchJson('/admin/support/'.$id, ['revision' => 1, 'status' => 'resolved', 'priority' => 'high'])->assertOk();
        $this->patchJson('/admin/support/'.$id, ['revision' => 1, 'status' => 'open'])->assertConflict();
        $this->getJson('/admin/support/'.$id)->assertJsonPath('status', 'resolved')->assertJsonPath('messages.1.event.status', 'resolved');
        $this->incoming($id);
        $this->getJson('/admin/support/'.$id)->assertJsonPath('status', 'open')->assertJsonPath('priority', 'high');
        $this->getJson('/admin/support?tab=needs_reply')->assertJsonCount(1, 'items');
    }

    public function test_customer_receipts_are_never_fabricated(): void
    {
        $id = $this->conversation();
        $detail = $this->getJson('/admin/support/'.$id)->assertJsonPath('messages.0.read_by_customer', false);
        $last = $detail->json('latest_id');
        $this->actingAs($this->customer, 'web');
        $this->getJson('/support-api/'.$id)->assertOk();
        $this->getJson('/admin/support/'.$id)->assertJsonPath('messages.0.read_by_customer', false);
        $this->postJson('/support-api/'.$id.'/read', ['through' => $last])->assertOk();
        $this->getJson('/admin/support/'.$id)->assertJsonPath('messages.0.read_by_customer', true);
    }

    public function test_related_records_are_owned_and_dont_change_payments_or_jobs(): void
    {
        $foreignProject = MediaProject::factory()->create(['user_id' => User::factory()->create()->id]);
        $foreignJob = MediaTask::factory()->create(['media_project_id' => $foreignProject->id]);
        $this->postJson('/admin/support', $this->data(['related_type' => 'job', 'related_id' => $foreignJob->id]))->assertUnprocessable()->assertJsonValidationErrors('related_id');
        $project = MediaProject::factory()->create(['user_id' => $this->customer->id]);
        $job = MediaTask::factory()->create(['media_project_id' => $project->id, 'status' => 'failed']);
        $id = $this->postJson('/admin/support', $this->data(['related_type' => 'job', 'related_id' => $job->id]))->assertCreated()->json('id');
        $this->getJson('/admin/support/'.$id)->assertJsonPath('related.id', $job->id)->assertJsonPath('related.status', 'failed');
        $this->assertSame('failed', $job->fresh()->status);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_history_pagination_is_stable_and_does_not_skip_messages(): void
    {
        $id = $this->conversation();
        $messages = [];
        for ($i = 0; $i < 70; $i++) {
            $messages[] = ['conversation_id' => $id, 'sender_id' => $this->customer->id, 'sender_kind' => 'customer', 'kind' => 'reply',
                'body' => 'Message '.$i, 'request_id' => (string) Str::uuid(), 'fingerprint' => hash('sha256', (string) $i), 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('support_messages')->insert($messages);
        $latest = $this->getJson('/admin/support/'.$id)->assertJsonCount(60, 'messages')->assertJsonPath('has_older', true);
        $old = $this->getJson('/admin/support/'.$id.'?before='.$latest->json('messages.0.id'))->assertJsonCount(11, 'messages')->assertJsonPath('has_older', false);
        $this->assertLessThan($latest->json('messages.0.id'), $old->json('latest_id'));
    }

    public function test_inbox_search_filters_options_and_notification_are_real(): void
    {
        $id = $this->conversation();
        $this->incoming($id);
        $this->getJson('/admin/support?q=SUP-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT))->assertJsonCount(1, 'items');
        $this->getJson('/admin/support?q=absent-customer')->assertJsonCount(0, 'items');
        $this->getJson('/admin/support?category=payment')->assertJsonCount(0, 'items');
        $this->getJson('/admin/support/options?customer='.$this->customer->id)->assertOk();
        $notice = DB::table('admin_notices')->where('kind', 'support_reply')->first();
        $this->assertNotNull($notice);
        $this->getJson('/admin/notifications/'.$notice->id)->assertOk()->assertJsonPath('related_url', '/admin/support?conversation='.$id)->assertJsonPath('category', 'support');
        $this->getJson('/admin/support?tab=invalid')->assertUnprocessable();
        $this->postJson('/admin/support', $this->data(['body' => '']))->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->assertDatabaseMissing('audit_events', ['target_label' => 'Please help']);
    }

    public function test_attachment_only_messages_and_files_only_creation_work(): void
    {
        $data = $this->data(['files' => [UploadedFile::fake()->image('screenshot.png')]]);
        unset($data['body']);
        $id = $this->post('/admin/support', $data, ['Accept' => 'application/json'])->assertCreated()->json('id');
        $this->post('/admin/support/'.$id.'/messages', ['kind' => 'reply', 'request_id' => (string) Str::uuid(),
            'files' => [UploadedFile::fake()->image('second.png')]], ['Accept' => 'application/json'])->assertCreated();
        $this->getJson('/admin/support/'.$id)->assertJsonPath('messages.0.body', '')->assertJsonCount(1, 'messages.0.attachments')->assertJsonCount(1, 'messages.1.attachments');
    }

    public function test_read_updates_the_support_notice_without_resolving_the_unanswered_issue(): void
    {
        $id = $this->conversation();
        $message = $this->incoming($id);
        $notice = DB::table('admin_notices')->where('kind', 'support_reply')->first();
        $this->postJson('/admin/support/'.$id.'/read', ['through' => $message])->assertOk();
        $this->assertDatabaseHas('admin_notice_states', ['notice_id' => $notice->id, 'admin_id' => $this->admin->id, 'read_revision' => 1]);
        $this->assertSame('attention', DB::table('admin_notices')->find($notice->id)->issue);
        $this->postJson('/admin/support/'.$id.'/messages', ['kind' => 'reply', 'body' => 'Resolved explanation', 'request_id' => (string) Str::uuid()])->assertCreated();
        $this->assertSame('resolved', DB::table('admin_notices')->find($notice->id)->issue);
    }

    private function seedInbox(int $count = 61, bool $waiting = false): void
    {
        $rows = [];
        for ($n = 0; $n < $count; $n++) {
            $rows[] = ['customer_id' => $this->customer->id, 'subject' => 'Support '.$n, 'category' => $n % 2 === 0 ? 'video' : 'general', 'priority' => 'normal',
                'status' => 'open', 'request_id' => (string) Str::uuid(), 'fingerprint' => hash('sha256', (string) $n),
                'last_customer_message_id' => $waiting && $n % 3 === 0 ? $n + 1 : null,
                'last_customer_at' => $waiting && $n % 2 === 0 ? now()->subMinutes($n % 5) : null,
                'created_at' => now(), 'updated_at' => now(), 'last_activity_at' => now()];
        }
        DB::table('support_conversations')->insert($rows);
    }

    public function test_admin_inbox_streams_twenty_at_a_time_without_duplicates(): void
    {
        $this->seedInbox();
        $first = $this->getJson('/admin/support')->assertOk()->assertJsonCount(20, 'items')
            ->assertJsonPath('batch.size', 20)->assertJsonPath('batch.total', 61)->assertJsonPath('batch.has_more', true)->assertJsonPath('counts.all', 61)->assertJsonMissingPath('pagination');
        $second = $this->getJson('/admin/support?after='.urlencode($first->json('batch.next_cursor')))->assertOk()->assertJsonCount(20, 'items');
        $third = $this->getJson('/admin/support?after='.urlencode($second->json('batch.next_cursor')))->assertOk()->assertJsonCount(20, 'items');
        $fourth = $this->getJson('/admin/support?after='.urlencode($third->json('batch.next_cursor')))->assertOk()->assertJsonCount(1, 'items')
            ->assertJsonPath('batch.has_more', false)->assertJsonPath('batch.next_cursor', null);
        $ids = [...array_column($first->json('items'), 'id'), ...array_column($second->json('items'), 'id'), ...array_column($third->json('items'), 'id'), ...array_column($fourth->json('items'), 'id')];
        $this->assertCount(61, array_unique($ids));
        $expected = DB::table('support_conversations')->orderByDesc('last_activity_at')->orderByDesc('id')->pluck('id')->all();
        $this->assertSame($expected, $ids);
    }

    public function test_inbox_cursor_is_bound_to_filters_sort_and_admin_identity(): void
    {
        $this->seedInbox();
        $cursor = $this->getJson('/admin/support')->json('batch.next_cursor');
        $this->getJson('/admin/support?after=bad-cursor')->assertUnprocessable()->assertJsonValidationErrors('after');
        $this->getJson('/admin/support?category=video&after='.urlencode($cursor))->assertUnprocessable();
        $this->getJson('/admin/support?sort=waiting&after='.urlencode($cursor))->assertUnprocessable();
        $anotherAdmin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($anotherAdmin, 'admin')->getJson('/admin/support?after='.urlencode($cursor))->assertUnprocessable();
        $this->actingAs($this->admin, 'admin');
        $response = $this->getJson('/admin/support?category=video')->assertOk()->assertJsonCount(20, 'items')->assertJsonPath('batch.total', 31);
        $this->getJson('/admin/support?category=video&after='.urlencode($response->json('batch.next_cursor')))->assertOk()->assertJsonCount(11, 'items')->assertJsonPath('batch.has_more', false);
    }

    public function test_inbox_cursor_does_not_shift_when_new_conversation_is_inserted(): void
    {
        $this->seedInbox(45);
        $first = $this->getJson('/admin/support')->assertOk();
        $new = $this->conversation();
        $second = $this->getJson('/admin/support?after='.urlencode($first->json('batch.next_cursor')))->assertOk()->assertJsonCount(20, 'items');
        $third = $this->getJson('/admin/support?after='.urlencode($second->json('batch.next_cursor')))->assertOk()->assertJsonCount(5, 'items');
        $ids = [...array_column($first->json('items'), 'id'), ...array_column($second->json('items'), 'id'), ...array_column($third->json('items'), 'id')];
        $this->assertCount(45, array_unique($ids));
        $this->assertNotContains($new, $ids);
        $this->getJson('/admin/support')->assertJsonPath('items.0.id', $new);
    }

    public function test_waiting_sort_streams_null_dates_and_equal_timestamps_consistently(): void
    {
        $this->seedInbox(61, true);
        $ids = [];
        $cursor = null;
        do {
            $r = $this->getJson('/admin/support?sort=waiting'.($cursor ? '&after='.urlencode($cursor) : ''))->assertOk();
            $ids = [...$ids, ...array_column($r->json('items'), 'id')];
            $cursor = $r->json('batch.next_cursor');
        } while ($cursor);
        $this->assertCount(61, array_unique($ids));
        $expected = DB::table('support_conversations')->orderByRaw("CASE WHEN last_customer_message_id > COALESCE(last_admin_reply_id, 0) AND status != 'resolved' THEN 0 ELSE 1 END")
            ->orderByRaw("COALESCE(last_customer_at, '1970-01-01 00:00:00')")->orderByDesc('id')->pluck('id')->all();
        $this->assertSame($expected, $ids);
    }

    public function test_oversized_image_dimensions_are_rejected_and_files_are_not_stored(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jD1sAAAAASUVORK5CYII=');
        $png = substr_replace($png, pack('N2', 9000, 9000), 16, 8);
        $this->post('/admin/support', $this->data(['files' => [UploadedFile::fake()->createWithContent('huge.png', $png)]]),
            ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('files.0');
        $this->assertDatabaseCount('support_conversations', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_admin_guard_rejects_customer_role_and_customer_cannot_spoof_creator(): void
    {
        $this->actingAs($this->customer, 'admin')->getJson('/admin/support')->assertForbidden();
        auth('admin')->logout();
        $this->actingAs($this->customer, 'web');
        $this->postJson('/support-api', $this->data())->assertUnprocessable()->assertJsonValidationErrors(['customer_id', 'priority']);
        $data = ['subject' => 'Need help', 'category' => 'general', 'body' => 'My own question', 'request_id' => (string) Str::uuid()];
        $id = $this->postJson('/support-api', $data)->assertCreated()->json('id');
        $this->assertDatabaseHas('support_conversations', ['id' => $id, 'customer_id' => $this->customer->id, 'status' => 'open']);
        $this->getJson('/support-api')->assertJsonCount(1, 'items');
    }

    public function test_customer_widget_creates_conversation_and_admin_reply_is_read_only_after_acknowledgement(): void
    {
        $this->actingAs($this->customer, 'web');
        $data = ['subject' => 'Help with my video', 'category' => 'general', 'body' => 'My export needs help', 'request_id' => (string) Str::uuid()];
        $id = $this->postJson('/support-api', $data)->assertCreated()->json('id');
        $this->postJson('/support-api', $data)->assertCreated()->assertJsonPath('id', $id);
        $this->getJson('/support-api')->assertOk()->assertJsonPath('items.0.id', $id)->assertJsonPath('counts.unread', 0);

        $this->actingAs($this->admin, 'admin');
        $reply = $this->postJson('/admin/support/'.$id.'/messages', ['kind' => 'reply', 'body' => 'Please send a screenshot', 'request_id' => (string) Str::uuid()])->assertCreated()->json('id');
        $this->postJson('/admin/support/'.$id.'/messages', ['kind' => 'note', 'body' => 'Internal admin investigation', 'request_id' => (string) Str::uuid()])->assertCreated();

        $this->actingAs($this->customer, 'web');
        $this->getJson('/support-api')->assertJsonPath('counts.unread', 1)->assertJsonPath('items.0.unread', 1);
        $this->getJson('/support-api/'.$id)->assertOk()->assertJsonPath('context', null)->assertJsonPath('status', 'waiting_customer')
            ->assertJsonCount(2, 'messages')->assertJsonPath('messages.1.sender_kind', 'admin')->assertDontSee('Internal admin investigation');
        $this->getJson('/support-api')->assertJsonPath('counts.unread', 1);
        $this->postJson('/support-api/'.$id.'/read', ['through' => $reply])->assertOk();
        $this->getJson('/support-api')->assertJsonPath('counts.unread', 0);
        $this->postJson('/support-api/'.$id.'/messages', ['body' => 'Here is more detail', 'request_id' => (string) Str::uuid()])->assertCreated();
        $this->getJson('/support-api/'.$id)->assertJsonPath('status', 'open');
        Mail::assertNothingSent();
    }

    public function test_customer_widget_uploads_private_attachment_without_text_and_rejects_empty_message(): void
    {
        $this->actingAs($this->customer, 'web');
        $id = $this->post('/support-api', ['subject' => 'Screenshot', 'category' => 'video', 'request_id' => (string) Str::uuid(),
            'files' => [UploadedFile::fake()->image('problem.png')]], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $detail = $this->getJson('/support-api/'.$id)->assertOk()->assertJsonCount(1, 'messages.0.attachments');
        $url = $detail->json('messages.0.attachments.0.url');
        $this->assertStringStartsWith('/support-api/'.$id.'/attachments/', $url);
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->postJson('/support-api/'.$id.'/messages', ['body' => ' ', 'request_id' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->actingAs(User::factory()->create(), 'web');
        $this->get($url)->assertNotFound();
    }

    public function test_customer_poll_redacts_admin_messages_deleted_from_loaded_older_history(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $id = $this->conversation();
        $first = DB::table('support_messages')->where('conversation_id', $id)->value('id');
        for ($n = 0; $n < 62; $n++) {
            $this->postJson('/admin/support/'.$id.'/messages', ['kind' => 'reply', 'body' => 'Reply '.$n, 'request_id' => (string) Str::uuid()])->assertCreated();
        }
        $this->deleteJson('/admin/support/'.$id.'/messages/'.$first)->assertOk();
        $this->actingAs($this->customer, 'web');
        $this->getJson('/support-api/'.$id.'?loaded_from='.$first)->assertOk()->assertJsonPath('deleted_ids.0', (int) $first)->assertJsonCount(60, 'messages');
        $this->getJson('/support-api/'.$id.'?loaded_from=invalid')->assertUnprocessable();
    }
}
