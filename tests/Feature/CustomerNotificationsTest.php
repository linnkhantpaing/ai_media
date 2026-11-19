<?php

namespace Tests\Feature;

use App\Models\CustomerPackageAssignment;
use App\Models\MediaProject;
use App\Models\MediaTask;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\PaymentRefund;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\CustomerNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomerNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->customer = User::factory()->create(['is_admin' => false, 'password' => Hash::make('OldPassword!2026')]);
    }

    private function types(?User $user = null): array
    {
        return DB::table('customer_notifications')->where('user_id', ($user ?? $this->customer)->id)->orderBy('id')->pluck('type')->all();
    }

    public function test_finished_scripts_videos_and_failures_notify_the_project_owner_once(): void
    {
        $project = MediaProject::factory()->create(['user_id' => $this->customer->id, 'title' => 'The Last Samurai', 'output_path' => 'media/x/output.mp4']);
        $analysis = MediaTask::factory()->create(['media_project_id' => $project->id, 'type' => 'analysis', 'status' => 'running']);
        $analysis->update(['status' => 'awaiting_review']);
        $analysis->update(['progress' => 100]);
        $render = MediaTask::factory()->create(['media_project_id' => $project->id, 'type' => 'generate', 'status' => 'running']);
        $render->update(['status' => 'completed']);
        $render->update(['status' => 'completed', 'progress' => 100]);
        $failed = MediaTask::factory()->create(['media_project_id' => $project->id, 'type' => 'generate', 'status' => 'running']);
        $failed->update(['status' => 'failed']);
        MediaTask::factory()->create(['media_project_id' => $project->id, 'type' => 'preview', 'status' => 'failed']);

        $this->assertSame(['script_ready', 'video_ready', 'export_failed'], $this->types());
        $this->actingAs($this->customer)->getJson('/notifications/feed')->assertOk()->assertJsonPath('unread', 3)
            ->assertJsonPath('all.1.type', 'video_ready')->assertJsonPath('all.1.data.project', 'The Last Samurai')
            ->assertJsonPath('all.1.action.kind', 'download')->assertJsonPath('all.1.url', '/projects/'.$project->id.'/analyze')
            ->assertJsonMissingPath('all.1.data.project_id');
    }

    public function test_payment_decisions_and_refunds_notify_without_admin_notes(): void
    {
        $payment = PaymentTransaction::factory()->create(['user_id' => $this->customer->id, 'currency' => 'MMK', 'received_minor' => 1000000]);
        $payment->update(['status' => 'needs_information', 'revision' => 2]);
        $payment->update(['status' => 'approved', 'revision' => 3]);
        PaymentRefund::create(['payment_transaction_id' => $payment->id, 'amount_minor' => 500000, 'status' => 'requested', 'reason' => 'Duplicate', 'details' => 'Internal refund note', 'access_action' => 'keep']);
        PaymentRefund::where('payment_transaction_id', $payment->id)->first()->update(['status' => 'completed']);

        $this->assertSame(['payment_info_needed', 'payment_approved', 'refund_completed'], $this->types());
        $feed = $this->actingAs($this->customer)->getJson('/notifications/feed')->assertOk();
        $feed->assertJsonPath('all.1.data.amount_minor', 1000000)->assertJsonPath('all.1.data.code', 'PYT-'.str_pad((string) $payment->id, 7, '0', STR_PAD_LEFT))
            ->assertJsonPath('all.1.url', '/account/billing?payment='.$payment->id)->assertJsonPath('all.2.action.kind', 'provide');
        $this->assertStringNotContainsString('Internal refund note', $feed->getContent());
    }

    public function test_plan_expiry_and_new_plan_notifications_are_derived_once(): void
    {
        $package = Package::factory()->create(['status' => 'active', 'published_version' => 1, 'name' => 'Pro']);
        $version = PackageVersion::create(['package_id' => $package->id, 'number' => 1, 'definition' => $package->definition]);
        $assignment = CustomerPackageAssignment::create(['user_id' => $this->customer->id, 'package_id' => $package->id, 'package_version_id' => $version->id, 'source' => 'payment_new',
            'snapshot' => ['name' => 'Pro', 'code' => $package->code, 'type' => 'paid', 'version' => 1, 'definition' => $version->definition], 'starts_at' => now()->subDays(25), 'ends_at' => now()->addDays(5)]);
        $this->actingAs($this->customer)->getJson('/notifications/feed')->assertOk();
        Cache::flush();
        $this->getJson('/notifications/feed')->assertOk();
        $this->assertSame(['plan_expiring'], $this->types());
        $this->assertSame(5, json_decode(DB::table('customer_notifications')->value('data'), true)['days']);

        $assignment->update(['ends_at' => now()->subHour()]);
        Cache::flush();
        $this->getJson('/notifications/feed')->assertJsonPath('all.0.type', 'plan_expired')->assertJsonPath('all.0.action.kind', 'renew');
    }

    public function test_listing_filters_counts_and_actions_only_touch_own_notifications(): void
    {
        $service = app(CustomerNotifications::class);
        $other = User::factory()->create(['is_admin' => false]);
        $service->notify($this->customer->id, 'payment_approved', ['code' => 'PYT-0000001', 'package' => 'Pro', 'currency' => 'USD', 'amount_minor' => 999], 'a');
        $service->notify($this->customer->id, 'payment_rejected', ['code' => 'PYT-0000002'], 'b');
        $service->notify($this->customer->id, 'password_changed', ['by' => 'you'], 'c');
        $service->notify($this->customer->id, 'password_changed', ['by' => 'you'], 'c');
        $service->notify($other->id, 'payment_approved', ['code' => 'PYT-0000009'], 'z');
        $service->notify(User::factory()->create(['is_admin' => true])->id, 'payment_approved', [], 'admin');
        $foreign = DB::table('customer_notifications')->where('user_id', $other->id)->value('id');
        $this->assertSame(4, DB::table('customer_notifications')->count());

        $this->actingAs($this->customer);
        $this->get('/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page->component('account/notifications')
            ->where('notifications.counts.all', 3)->where('notifications.counts.payment', 2)->where('notifications.counts.account', 1)->where('notifications.unread', 3));
        $this->getJson('/notifications?tab=payment&q=0000002')->assertJsonCount(1, 'items')->assertJsonPath('items.0.type', 'payment_rejected');
        $ids = DB::table('customer_notifications')->where('user_id', $this->customer->id)->pluck('id')->all();
        $this->postJson('/notifications/action', ['action' => 'read', 'ids' => [$ids[0], $foreign]])->assertOk()->assertJsonPath('unread', 2);
        $this->assertNull(DB::table('customer_notifications')->find($foreign)->read_at);
        $this->getJson('/notifications?status=read')->assertJsonCount(1, 'items');
        $this->postJson('/notifications/action', ['action' => 'archive', 'ids' => [$ids[1]]])->assertOk();
        $this->getJson('/notifications')->assertJsonPath('counts.all', 2);
        $this->getJson('/notifications?archived=1')->assertJsonPath('counts.all', 3);
        $this->postJson('/notifications/action', ['action' => 'read_all'])->assertOk()->assertJsonPath('unread', 0);
        $this->postJson('/notifications/action', ['action' => 'delete', 'ids' => [$ids[0]]])->assertUnprocessable();
        $this->assertNull(DB::table('customer_notifications')->find($foreign)->read_at);
    }

    public function test_security_changes_and_deleted_projects_are_handled_safely(): void
    {
        $this->actingAs($this->customer)->putJson('/settings/preferences/password', ['current_password' => 'OldPassword!2026', 'password' => 'NewPassword!2027', 'password_confirmation' => 'NewPassword!2027'])->assertOk();
        $this->assertSame(['password_changed'], $this->types());
        $project = MediaProject::factory()->create(['user_id' => $this->customer->id]);
        MediaTask::factory()->create(['media_project_id' => $project->id, 'type' => 'generate', 'status' => 'running'])->update(['status' => 'failed']);
        $project->delete();
        $this->getJson('/notifications/feed')->assertJsonPath('all.0.type', 'export_failed')->assertJsonPath('all.0.removed', true)
            ->assertJsonPath('all.0.url', null)->assertJsonPath('all.0.action', null);
        auth('web')->logout();
        $this->getJson('/notifications/feed')->assertUnauthorized();
    }
}
