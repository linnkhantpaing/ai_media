<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CustomerAccountEvent;
use App\Models\User;
use App\Services\AuditTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuditSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->freezeTime();
        Queue::fake();
        config(['operations.auto_launch_allowed' => false]);
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    private function record(array $data = []): AuditEvent
    {
        $event = AuditEvent::create(array_replace([
            'source' => 'test', 'source_id' => (string) Str::uuid(), 'category' => 'accounts',
            'action' => 'account.edit', 'result' => 'success', 'sensitive' => false,
            'actor_id' => $this->admin->id, 'actor_kind' => 'admin', 'actor_name' => $this->admin->name,
            'actor_email' => $this->admin->email, 'target_type' => 'user', 'target_id' => '999999',
            'target_label' => 'Historical customer', 'details' => ['fields' => ['name']],
            'occurred_at' => now(), 'recorded_at' => now(),
        ], $data));

        return $event;
    }

    public function test_customer_and_guest_cannot_read_or_export_audit_data(): void
    {
        $event = $this->record();
        foreach (['/admin/audit', '/admin/audit/export', '/admin/audit/'.$event->id] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        $customer = User::factory()->create(['is_admin' => false]);
        $this->actingAs($customer, 'web')->getJson('/admin/audit')->assertUnauthorized();
        $this->actingAs($customer, 'admin')->getJson('/admin/audit')->assertForbidden();
        $this->assertDatabaseHas('audit_events', ['action' => 'access_blocked', 'result' => 'blocked']);
    }

    public function test_page_uses_real_counts_and_admin_navigation(): void
    {
        $this->record();
        $this->record(['result' => 'failed', 'category' => 'security']);
        $this->record(['result' => 'blocked', 'category' => 'security', 'sensitive' => true]);
        $this->record(['occurred_at' => now()->subDays(50)]);
        $this->actingAs($this->admin, 'admin')->get('/admin/audit')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/audit-security')->where('audit.summary.total', 3)->where('audit.summary.successful', 1)
            ->where('audit.summary.failed', 2)->where('audit.summary.sensitive', 1)
            ->where('audit.tabs.security', 2)->where('adminLinks.audit', '/admin/audit'));
    }

    public function test_filters_pagination_and_custom_date_boundaries(): void
    {
        for ($i = 0; $i < 27; $i++) {
            $this->record(['source_id' => (string) $i, 'occurred_at' => now()->subMinutes($i)]);
        }
        $this->record(['category' => 'settings', 'action' => 'general.configuration_saved', 'target_label' => 'General Settings']);
        $this->actingAs($this->admin, 'admin')->getJson('/admin/audit?page=2&per_page=10')->assertOk()->assertJsonPath('pagination.page', 2)->assertJsonCount(10, 'items');
        $this->getJson('/admin/audit?page=3&per_page=10')->assertOk()->assertJsonPath('pagination.page', 3)->assertJsonCount(8, 'items');
        $this->getJson('/admin/audit?q=General&category=settings&actor=admin&result=success')->assertOk()->assertJsonPath('pagination.total', 1);
        $this->getJson('/admin/audit?q=%25')->assertOk()->assertJsonPath('pagination.total', 0);
        $this->getJson('/admin/audit?dates=custom&from='.now()->toDateString().'&to='.now()->toDateString())->assertOk()->assertJsonPath('pagination.total', 28);
        $this->getJson('/admin/audit?dates=custom&from=2026-11-13&to=2026-11-01')->assertUnprocessable();
        $this->getJson('/admin/audit?category=invalid')->assertUnprocessable();
        $this->getJson('/admin/audit?per_page=99999')->assertUnprocessable();
    }

    public function test_domain_events_are_captured_once_redacted_and_survive_target_deletion(): void
    {
        $customer = User::factory()->create();
        $event = CustomerAccountEvent::create(['user_id' => $customer->id, 'actor_id' => $this->admin->id, 'type' => 'edit',
            'metadata' => ['fields' => ['name'], 'password' => 'DO-NOT-EXPOSE', 'evidence_snapshot' => 'CIPHER-TEXT', 'reason' => 'Correct name',
                'package_change' => ['before' => ['password' => 'OTHER-SECRET', 'version' => 1], 'after' => ['version' => 2, 'token' => 'PRIVATE-TOKEN']]]]);
        app(AuditTrail::class)->capture('account', $event->id);
        $this->assertDatabaseCount('audit_events', 1);
        $audit = AuditEvent::first();
        $customer->delete();
        $response = $this->actingAs($this->admin, 'admin')->getJson('/admin/audit/'.$audit->id)->assertOk()
            ->assertJsonPath('details.reason', 'Correct name')
            ->assertJsonPath('related_url', null);
        $this->assertSame('1', $response->json('details.before')['package.version']);
        foreach (['DO-NOT-EXPOSE', 'CIPHER-TEXT', 'OTHER-SECRET', 'PRIVATE-TOKEN'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
            $this->assertStringNotContainsString($secret, $audit->getRawOriginal('details'));
        }
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_legacy_import_is_repeatable_and_never_reads_encrypted_configuration(): void
    {
        DB::table('media_provider_revisions')->insert(['configuration' => 'NEVER-DECRYPT-ME', 'fingerprint' => str_repeat('x', 64), 'created_by' => $this->admin->id, 'created_at' => now()->subDay()]);
        $customer = User::factory()->create();
        DB::table('customer_account_events')->insert(['user_id' => $customer->id, 'actor_id' => $this->admin->id, 'type' => 'create',
            'metadata' => '{}', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $this->artisan('audit:import-history')->assertSuccessful();
        $this->artisan('audit:import-history')->assertSuccessful();
        $this->assertDatabaseCount('audit_events', 2);
        $this->assertNull(AuditEvent::first()->ip_address);
        $this->assertStringNotContainsString('NEVER-DECRYPT-ME', AuditEvent::all()->toJson());
    }

    public function test_auth_events_record_masked_context_without_credentials(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '103.88.12.45', 'HTTP_USER_AGENT' => 'Mozilla Chrome/100.0']);
        $this->post('/admin/login', ['email' => 'nobody@example.com', 'password' => 'PRIVATE-PASSWORD'])->assertSessionHasErrors('email');
        $this->assertDatabaseHas('audit_events', ['action' => 'login_failed', 'result' => 'failed', 'actor_kind' => 'unknown', 'ip_address' => '103.88.12.*']);
        $this->post('/admin/login', ['email' => $this->admin->email, 'password' => 'password'])->assertRedirect('/admin/dashboard');
        $this->assertDatabaseHas('audit_events', ['action' => 'login_success', 'actor_id' => $this->admin->id]);
        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $this->assertDatabaseHas('audit_events', ['action' => 'logout', 'actor_id' => $this->admin->id]);
        $this->assertStringNotContainsString('PRIVATE-PASSWORD', AuditEvent::all()->toJson());
    }

    public function test_csv_respects_filters_redacts_details_and_prevents_spreadsheet_formulas(): void
    {
        $this->record(['category' => 'payments', 'target_label' => '=HYPERLINK("bad")', 'details' => ['password' => 'NOT-IN-CSV']]);
        $this->record(['category' => 'accounts']);
        $response = $this->actingAs($this->admin, 'admin')->get('/admin/audit/export?category=payments')->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('Historical customer', $csv);
        $this->assertStringNotContainsString('NOT-IN-CSV', $csv);
        $this->assertDatabaseCount('audit_events', 2);
    }

    public function test_get_and_export_do_not_replay_actions_and_no_mutation_routes_exist(): void
    {
        $event = $this->record();
        $this->actingAs($this->admin, 'admin')->getJson('/admin/audit')->assertOk();
        $this->getJson('/admin/audit/'.$event->id)->assertOk();
        $this->get('/admin/audit/export')->assertOk();
        $this->postJson('/admin/audit', [])->assertMethodNotAllowed();
        $this->patchJson('/admin/audit/'.$event->id, [])->assertMethodNotAllowed();
        $this->deleteJson('/admin/audit/'.$event->id)->assertMethodNotAllowed();
        $this->assertDatabaseCount('audit_events', 1);
        Queue::assertNothingPushed();
    }

    public function test_records_cannot_be_updated_through_the_model(): void
    {
        $event = $this->record();
        $this->expectException(\LogicException::class);
        $event->update(['result' => 'failed']);
    }

    public function test_multiple_sensitive_events_are_counted_as_numbers_and_failures_include_blocked(): void
    {
        $this->record(['sensitive' => true, 'result' => 'blocked']);
        $this->record(['sensitive' => true, 'result' => 'failed']);
        $this->record(['sensitive' => true]);
        $this->actingAs($this->admin, 'admin')->getJson('/admin/audit')->assertOk()->assertJsonPath('summary.sensitive', 3);
        $this->getJson('/admin/audit?result=unsuccessful')->assertOk()->assertJsonPath('pagination.total', 2);
    }

    public function test_smtp_failure_is_not_reported_as_success_or_sensitive_change(): void
    {
        $id = DB::table('platform_setting_events')->insertGetId(['revision' => 1, 'actor_id' => $this->admin->id, 'action' => 'smtp_email_error', 'fields' => '[]', 'created_at' => now()]);
        app(AuditTrail::class)->capture('general', $id);
        $this->assertDatabaseHas('audit_events', ['action' => 'general.smtp_email_error', 'result' => 'failed', 'sensitive' => false]);
    }

    public function test_rolled_back_business_change_does_not_leave_a_success_event(): void
    {
        $customer = User::factory()->create();
        DB::beginTransaction();
        CustomerAccountEvent::create(['user_id' => $customer->id, 'actor_id' => $this->admin->id, 'type' => 'edit', 'metadata' => []]);
        $this->assertDatabaseCount('audit_events', 1);
        DB::rollBack();
        $this->assertDatabaseCount('audit_events', 0);
    }
}
