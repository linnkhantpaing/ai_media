<?php

namespace Tests\Feature;

use App\Jobs\ImportYouTubeVideo;
use App\Jobs\InspectMediaProject;
use App\Jobs\PrepareMediaPreview;
use App\Jobs\ProcessMediaRun;
use App\Models\CustomerAccountEvent;
use App\Models\MediaPreview;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;
use App\Services\Media\MediaInspector;
use App\Services\Media\MediaPipeline;
use App\Services\Media\MediaPreviews;
use App\Services\Media\MediaRuntime;
use App\Services\Media\MediaStorage;
use App\Services\Media\YouTubeDownloader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::factory()->create(['is_admin' => true]);
        config(['media.worker.auto_start' => false]);
        $this->package = Package::factory()->trial()->create(['status' => 'active', 'published_version' => 1]);
        PackageVersion::create(['package_id' => $this->package->id, 'number' => 1, 'definition' => $this->package->definition, 'actor_id' => $this->admin->id]);
    }

    private function payload(array $extra = []): array
    {
        return array_replace(['validity_mode' => 'package', 'starts_on' => now()->toDateString(), 'ends_on' => now()->addDays(30)->toDateString(), 'package_id' => $this->package->id, 'package_version' => 1, 'name' => 'Aung Min', 'email' => 'aung@example.com', 'password' => 'PrivatePass!2026', 'password_confirmation' => 'PrivatePass!2026', 'preferred_locale' => 'my', 'note' => 'Customer note', 'status' => 'active', 'send_login' => false, 'request_id' => (string) Str::uuid()], $extra);
    }

    private function customer(array $attributes = []): User
    {
        return User::factory()->create(array_replace(['is_admin' => false, 'account_status' => 'active', 'auth_version' => 0], $attributes))->refresh();
    }

    private function action(User $user, string $action, array $extra = []): TestResponse
    {
        return $this->actingAs($this->admin, 'admin')->postJson('/admin/users/'.$user->id.'/action', array_replace(['action' => $action, 'revision' => $user->fresh()->account_revision, 'request_id' => (string) Str::uuid(), 'reason' => 'Requested by administrator'], $extra));
    }

    public function test_customer_management_requires_the_admin_guard_and_excludes_admin_accounts(): void
    {
        $customer = $this->customer();
        $this->get('/admin/users')->assertRedirect('/admin/login');
        $this->getJson('/admin/users')->assertUnauthorized();
        $this->actingAs($customer, 'web')->postJson('/admin/users', $this->payload())->assertUnauthorized();
        $this->actingAs($this->admin, 'admin')->get('/admin/users')->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/users')->where('adminLinks.users', '/admin/users')->where('accounts.packageReady', true)->where('accounts.summary.expired', 0)->has('accounts.users', 1)->missing('accounts.users.0.password'));
        $this->getJson('/admin/users/'.$this->admin->id)->assertNotFound();
        $this->action($this->admin, 'suspend')->assertNotFound();
    }

    public function test_create_normalizes_email_hashes_password_and_assigns_the_required_package(): void
    {
        $data = $this->payload(['email' => ' AUNG@EXAMPLE.COM ', 'name' => ' Aung Min ']);
        $response = $this->actingAs($this->admin, 'admin')->postJson('/admin/users', $data)->assertCreated()->assertJsonPath('customer.name', 'Aung Min')->assertJsonPath('customer.email', 'aung@example.com')->assertJsonPath('customer.status', 'active')->assertJsonPath('delivery', 'not_requested')->assertJsonMissingPath('customer.password')->assertJsonMissingPath('customer.auth_version');
        $user = User::findOrFail($response->json('customer.id'));
        $this->assertFalse($user->is_admin);
        $this->assertTrue($user->managed_account);
        $this->assertTrue(Hash::check($data['password'], $user->password));
        $this->assertDatabaseHas('customer_account_events', ['user_id' => $user->id, 'actor_id' => $this->admin->id, 'type' => 'created']);
        $this->assertStringNotContainsString('PrivatePass', CustomerAccountEvent::first()->toJson());
        Auth::forgetGuards();
        $this->post('/login', ['email' => $user->email, 'password' => $data['password']])->assertRedirect('/')->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user, 'web');
        $this->get('/projects/new')->assertOk();
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_creating_same_request_is_idempotent_but_changed_body_conflicts(): void
    {
        $data = $this->payload();
        $this->actingAs($this->admin, 'admin')->postJson('/admin/users', $data)->assertCreated();
        $this->postJson('/admin/users', $data)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('customer_account_events', 1);
        $this->postJson('/admin/users', array_replace($data, ['name' => 'Other']))->assertConflict();
    }

    public function test_duplicate_email_privilege_and_package_injection_and_weak_password_are_rejected(): void
    {
        $this->actingAs($this->admin, 'admin');
        $this->postJson('/admin/users', $this->payload(['email' => $this->admin->email]))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/admin/users', $this->payload(['is_admin' => true, 'package_id' => 999999]))->assertUnprocessable()->assertJsonValidationErrors(['is_admin', 'package_id']);
        $this->postJson('/admin/users', $this->payload(['password' => '123456', 'password_confirmation' => '123456']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/admin/users', $this->payload(['password' => str_repeat('Aa1!', 19), 'password_confirmation' => str_repeat('Aa1!', 19)]))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_edit_updates_safe_fields_preserves_password_and_detects_stale_versions(): void
    {
        $user = $this->customer();
        $hash = $user->password;
        $payload = ['name' => 'Updated Name', 'email' => $user->email, 'preferred_locale' => 'th', 'note' => 'New note', 'revision' => 1, 'request_id' => (string) Str::uuid()];
        $this->actingAs($this->admin, 'admin')->patchJson('/admin/users/'.$user->id, $payload)->assertOk()->assertJsonPath('customer.revision', 2);
        $this->patchJson('/admin/users/'.$user->id, $payload)->assertOk()->assertJsonPath('replayed', true);
        $this->patchJson('/admin/users/'.$user->id, array_replace($payload, ['request_id' => (string) Str::uuid(), 'name' => 'Stale']))->assertConflict();
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame('th', $user->fresh()->preferred_locale);
        $this->assertDatabaseCount('customer_account_events', 1);
    }

    public function test_email_change_revokes_sessions_and_clears_old_reset_tokens(): void
    {
        $user = $this->customer();
        Password::broker()->createToken($user);
        $this->actingAs($this->admin, 'admin')->patchJson('/admin/users/'.$user->id, ['name' => $user->name, 'email' => 'new@example.com', 'preferred_locale' => 'en', 'note' => '', 'revision' => 1, 'request_id' => (string) Str::uuid()])->assertOk();
        $this->assertSame(1, $user->fresh()->auth_version);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_reset_password_preserves_status_and_revokes_old_credentials(): void
    {
        $user = $this->customer(['account_status' => 'suspended']);
        Password::broker()->createToken($user);
        $this->action($user, 'reset_password', ['password' => 'NewPrivate!2026', 'password_confirmation' => 'NewPrivate!2026'])->assertOk();
        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('NewPrivate!2026', $fresh->password));
        $this->assertSame('suspended', $fresh->account_status);
        $this->assertSame(1, $fresh->auth_version);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->action($user, 'reset_password', ['password' => 'bad', 'password_confirmation' => 'mismatch'])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_suspended_and_archived_accounts_cannot_login_and_restore_requires_reactivation(): void
    {
        $user = $this->customer(['password' => 'PrivatePass!2026']);
        $this->action($user, 'suspend')->assertOk();
        Auth::forgetGuards();
        $this->post('/login', ['email' => $user->email, 'password' => 'PrivatePass!2026'])->assertSessionHasErrors('email');
        $this->assertGuest('web');
        $this->action($user, 'archive')->assertOk();
        $this->action($user, 'restore')->assertOk()->assertJsonPath('customer.status', 'suspended');
        $this->action($user, 'reactivate')->assertOk()->assertJsonPath('customer.status', 'active');
        Auth::forgetGuards();
        $this->post('/login', ['email' => $user->email, 'password' => 'PrivatePass!2026'])->assertSessionHasNoErrors()->assertRedirect('/');
    }

    public function test_revoked_customer_session_is_rejected_without_losing_admin_session(): void
    {
        $user = $this->customer();
        $this->actingAs($user, 'web')->actingAs($this->admin, 'admin')->withSession(['customer_auth_version:'.$user->id => 0]);
        $this->action($user, 'revoke_sessions')->assertOk();
        $this->actingAs($user->fresh(), 'web')->getJson('/projects')->assertUnauthorized()->assertJsonPath('code', 'account_unavailable');
        $this->assertGuest('web');
        $this->assertAuthenticatedAs($this->admin, 'admin');
        $this->get('/admin/dashboard')->assertOk();
    }

    public function test_reset_customer_session_is_rejected_before_password_middleware_flushes_admin_session(): void
    {
        $user = $this->customer();
        $this->actingAs($user, 'web')->withSession(['customer_auth_version:'.$user->id => 0])->get('/')->assertOk();
        $this->actingAs($this->admin, 'admin');
        $this->action($user, 'reset_password', ['password' => 'ResetPrivate!2026', 'password_confirmation' => 'ResetPrivate!2026'])->assertOk();
        $this->actingAs($user->fresh(), 'web')->getJson('/projects')->assertUnauthorized();
        $this->assertAuthenticatedAs($this->admin, 'admin');
        $this->get('/admin/dashboard')->assertOk();
    }

    public function test_legacy_active_customers_keep_access_without_any_package(): void
    {
        $user = $this->customer(['managed_account' => false]);
        $this->actingAs($user, 'web')->get('/projects/new')->assertOk();
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
        Auth::forgetGuards();
        $this->post('/admin/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest('admin');
    }

    public function test_archive_keeps_projects_and_email_reserved_and_filters_are_real(): void
    {
        $active = $this->customer(['name' => 'Searchable Customer']);
        $suspended = $this->customer(['account_status' => 'suspended']);
        $archived = $this->customer(['account_status' => 'archived']);
        $project = MediaProject::factory()->create(['user_id' => $active->id]);
        $this->actingAs($this->admin, 'admin')->getJson('/admin/users')->assertOk()->assertJsonPath('summary.total', 2)->assertJsonPath('summary.active', 1)->assertJsonPath('summary.suspended', 1)->assertJsonPath('summary.expired', 0)->assertJsonCount(2, 'users');
        $this->getJson('/admin/users?status=archived')->assertJsonCount(1, 'users')->assertJsonPath('users.0.id', $archived->id);
        $this->getJson('/admin/users?q=Searchable')->assertJsonCount(1, 'users')->assertJsonPath('users.0.id', $active->id);
        $this->action($active, 'archive')->assertOk();
        $this->assertDatabaseHas('media_projects', ['id' => $project->id]);
        $this->postJson('/admin/users', $this->payload(['email' => $active->email]))->assertUnprocessable();
        $this->getJson('/admin/users/'.$active->id)->assertOk()->assertJsonCount(1, 'projects');
    }

    public function test_logged_mail_transport_cannot_claim_real_delivery(): void
    {
        config(['mail.default' => 'log']);
        Notification::fake();
        $this->actingAs($this->admin, 'admin')->postJson('/admin/users', $this->payload(['send_login' => true]))->assertUnprocessable()->assertJsonValidationErrors('send_login');
        $this->assertDatabaseCount('users', 1);
        Notification::assertNothingSent();
    }

    public function test_configured_smtp_does_not_send_welcome_emails_on_creation_or_replay(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp']);
        Notification::fake();
        $data = $this->payload(['send_login' => false]);
        $this->actingAs($this->admin, 'admin')->postJson('/admin/users', $data)->assertCreated()->assertJsonPath('delivery', 'not_requested');
        $this->postJson('/admin/users', $data)->assertOk()->assertJsonPath('delivery', 'not_repeated');
        $this->assertDatabaseHas('users', ['email' => 'aung@example.com']);
        Notification::assertNothingSent();
    }

    public function test_environment_mail_cannot_enable_welcome_delivery_without_saved_general_settings(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp']);
        Notification::fake();
        $this->actingAs($this->admin, 'admin')->postJson('/admin/users', $this->payload(['send_login' => true]))->assertUnprocessable()->assertJsonValidationErrors('send_login');
        $this->assertDatabaseCount('users', 1);
        Notification::assertNothingSent();
    }

    public function test_queued_processing_and_inspection_stop_for_suspended_customers(): void
    {
        $user = $this->customer(['account_status' => 'suspended']);
        $project = MediaProject::factory()->create(['user_id' => $user->id, 'status' => 'queued']);
        $run = MediaRun::factory()->create(['media_project_id' => $project->id]);
        $project->update(['active_run_id' => $run->id]);
        $pipeline = $this->mock(MediaPipeline::class);
        $pipeline->shouldNotReceive('check');
        $pipeline->shouldNotReceive('analyze');
        (new ProcessMediaRun($run->id))->handle($pipeline);
        $this->assertSame('account_unavailable', $run->fresh()->error_code);
        $project->update(['status' => 'validating']);
        $inspector = $this->mock(MediaInspector::class);
        $inspector->shouldNotReceive('inspect');
        (new InspectMediaProject($project->id))->handle($inspector, app(MediaStorage::class));
        $this->assertSame('account_unavailable', $project->fresh()->error_code);
        $this->assertDatabaseHas('media_projects', ['id' => $project->id]);
    }

    public function test_customer_creation_requires_csrf_outside_test_bypass(): void
    {
        $this->actingAs($this->admin, 'admin');
        $this->app['env'] = 'local';
        $this->postJson('/admin/users', $this->payload())->assertStatus(419);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_queued_import_and_preview_do_not_start_after_account_archival(): void
    {
        $user = $this->customer(['account_status' => 'archived']);
        $project = MediaProject::factory()->create(['user_id' => $user->id, 'status' => 'importing', 'source_type' => 'youtube']);
        $downloader = $this->mock(YouTubeDownloader::class);
        $downloader->shouldNotReceive('inspect');
        $downloader->shouldNotReceive('download');
        (new ImportYouTubeVideo($project->id))->handle($downloader, app(MediaRuntime::class));
        $this->assertSame('account_unavailable', $project->fresh()->error_code);
        $preview = MediaPreview::factory()->create(['media_project_id' => $project->id]);
        $service = $this->mock(MediaPreviews::class);
        $service->shouldNotReceive('process');
        (new PrepareMediaPreview($preview->id))->handle($service);
        $this->assertSame('account_unavailable', $preview->fresh()->error_code);
        $this->assertDatabaseHas('media_projects', ['id' => $project->id]);
    }

    public function test_sorting_pagination_and_audit_details_use_real_records(): void
    {
        $users = User::factory()->count(12)->create(['is_admin' => false]);
        $first = $users->first();
        $first->forceFill(['name' => 'AAA Customer', 'last_login_at' => now(), 'created_by_admin_id' => $this->admin->id])->save();
        $this->actingAs($this->admin, 'admin')->getJson('/admin/users?sort=name&per_page=10')->assertOk()->assertJsonPath('users.0.id', $first->id)->assertJsonPath('pagination.total', 12)->assertJsonPath('pagination.pages', 2)->assertJsonPath('timezone', config('app.timezone'));
        $this->getJson('/admin/users?sort=last_login')->assertJsonPath('users.0.id', $first->id);
        $this->getJson('/admin/users?page=2&per_page=10')->assertJsonCount(2, 'users')->assertJsonPath('pagination.from', 11);
        $this->getJson('/admin/users/'.$first->id)->assertJsonPath('created_by', $this->admin->name)->assertJsonMissingPath('customer.password')->assertJsonMissingPath('customer.remember_token');
        $this->getJson('/admin/users?sort=not-supported')->assertUnprocessable();
        $this->getJson('/admin/users?per_page=999')->assertUnprocessable();
    }

    public function test_malformed_request_identity_is_validated_and_hash_shaped_passwords_remain_passwords(): void
    {
        $this->actingAs($this->admin, 'admin')->postJson('/admin/users', $this->payload(['request_id' => ['unexpected']]))->assertUnprocessable()->assertJsonValidationErrors('request_id');
        $literal = Hash::make('Another private phrase');
        $response = $this->postJson('/admin/users', $this->payload(['password' => $literal, 'password_confirmation' => $literal]))->assertCreated();
        $user = User::findOrFail($response->json('customer.id'));
        $this->assertTrue(Hash::check($literal, $user->password));
        $this->assertNotSame($literal, $user->password);
    }
}
