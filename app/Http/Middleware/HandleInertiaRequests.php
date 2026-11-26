<?php

namespace App\Http\Middleware;

use App\Models\CustomerPackageAssignment;
use App\Models\User;
use App\Services\CustomerProfile;
use App\Services\GeneralSettings;
use App\Services\UsageLedger;
use Closure;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * Laravel records the "previous URL" (used by back() and validation redirects) only on
     * non-AJAX page loads, and Referrer-Policy is no-referrer, so Inertia page visits are
     * recorded here. Otherwise a failed form reached by an in-app link returns to the page
     * that was loaded before it (e.g. Forgot password errors landing on Sign in).
     */
    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('GET') && $request->hasHeader('X-Inertia') && ! $request->hasHeader('X-Inertia-Partial-Data')
            && $request->header('Purpose') !== 'prefetch' && $request->hasSession()) {
            $request->session()->setPreviousUrl($request->fullUrl());
        }

        return parent::handle($request, $next);
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        $adminArea = $request->is('admin', 'admin/*');
        $user = $request->user($adminArea ? 'admin' : 'web');
        $customer = ! $adminArea && $user && ! $user->is_admin;
        $platform = $request->attributes->get('platform_settings') ?? app(GeneralSettings::class)->publicData();

        return [
            ...parent::share($request),
            'app' => $customer ? app(CustomerProfile::class)->applyDisplay($platform, $user) : $platform,
            'locale' => app()->getLocale(),
            'auth' => ['user' => $user ? [
                ...$user->only(['name', 'email']),
                'is_admin' => (bool) $user->is_admin,
                'avatar_url' => $customer ? app(CustomerProfile::class)->avatarUrl($user) : null,
            ] : null],
            'adminLinks' => $adminArea && $user?->is_admin ? ['support' => route('admin.support.index', absolute: false), 'usageReports' => route('admin.processing.index', absolute: false), 'revenue' => route('admin.revenue.index', absolute: false), 'aiCosts' => route('admin.costs.index', absolute: false), 'notifications' => route('admin.notifications.index', absolute: false), 'audit' => route('admin.audit.index', absolute: false), 'generalSettings' => route('admin.settings.general', absolute: false), 'dashboard' => route('admin.dashboard', absolute: false), 'users' => route('admin.users.index', absolute: false), 'packages' => route('admin.packages.index', absolute: false), 'subscriptions' => route('admin.subscriptions.index', absolute: false), 'providers' => route('admin.settings.providers', absolute: false), 'storage' => route('admin.settings.storage', absolute: false), 'usageLedger' => route('admin.usage.index', absolute: false), 'payments' => route('admin.payments.index', absolute: false), 'paymentSettings' => route('admin.settings.payments', absolute: false), 'defaults' => route('admin.settings.defaults', absolute: false), 'jobMonitor' => route('admin.jobs.index', absolute: false), 'workerHealth' => route('admin.jobs.workers', absolute: false)] : [],
            'supportFeed' => ! $adminArea && $user && ! $user->is_admin ? route('support.chat', absolute: false) : null,
            'queueFeed' => ! $adminArea && $user ? route('media.queue.feed', absolute: false) : null,
            'customerNotifications' => $customer ? ['feed' => route('notifications.feed', absolute: false), 'action' => route('notifications.action', absolute: false), 'index' => route('notifications.index', absolute: false)] : null,
            'planBadge' => $customer ? fn () => $this->planBadge($user) : null,
            'notificationLinks' => $adminArea && $user?->is_admin ? ['index' => route('admin.notifications.index', absolute: false), 'feed' => route('admin.notifications.feed', absolute: false), 'action' => route('admin.notifications.action', absolute: false)] : null,
            'frameLinks' => [
                'dashboard' => route('workspace', absolute: false),
                'newProject' => route('projects.create', absolute: false),
                'allProjects' => route('projects.index', absolute: false),
                ...collect([
                    'drafts' => 'draft', 'awaitingReview' => 'awaiting_review',
                    'processing' => 'processing', 'completed' => 'completed', 'needsAttention' => 'needs_attention',
                ])->map(fn (string $status) => route('projects.index', ['status' => $status], absolute: false))->all(),
                'myPlan' => route('account.plan', absolute: false),
                'billing' => route('account.billing', absolute: false),
                'notifications' => route('notifications.index', absolute: false),
                'preferences' => route('settings.preferences', absolute: false),
                ...collect([
                    'projectSetup' => 'setup', 'scriptReview' => 'script', 'previewStudio' => 'preview',
                    'projectProcessing' => 'processing', 'projectResult' => 'result',
                ])->map(fn (string $step) => route('projects.step.'.$step, absolute: false))->all(),
                ...($user?->can('manage-system') ? [
                    'jobMonitor' => route($adminArea ? 'admin.jobs.index' : 'operations.jobs', absolute: false),
                    'workerHealth' => route($adminArea ? 'admin.jobs.workers' : 'operations.workers', absolute: false),
                ] : []),
            ],
            'links' => [
                'workspace' => route($adminArea ? 'admin.dashboard' : 'workspace', absolute: false),
                'login' => route($adminArea ? 'admin.login' : 'login', absolute: false),
                'logout' => route($adminArea ? 'admin.logout' : 'logout', absolute: false),
                'locale' => route($adminArea ? 'admin.locale.update' : 'locale.update', absolute: false),
                'forgotPassword' => route('password.request', absolute: false),
                'passwordEmail' => route('password.email', absolute: false),
                'passwordStore' => route('password.store', absolute: false),
            ],
        ];
    }

    /** Plan name and state for the sidebar footer. */
    private function planBadge(User $user): array
    {
        $assignment = CustomerPackageAssignment::current()->where('user_id', $user->id)->first();
        $used = app(UsageLedger::class)->storageBytes($user->id);
        if (! $assignment) {
            return ['name' => null, 'type' => null, 'status' => 'none', 'days_left' => 0, 'ends_at' => null, 'storage' => ['used' => $used, 'total' => null]];
        }
        $status = $assignment->status();
        $snapshot = $assignment->effectiveSnapshot();

        return ['name' => $snapshot['name'] ?? null, 'type' => $snapshot['type'] ?? ($snapshot['definition']['type'] ?? 'paid'), 'status' => $status,
            'days_left' => $status === 'active' ? max(0, (int) ceil(now()->diffInDays($assignment->ends_at, false))) : 0,
            'ends_at' => $assignment->ends_at->toIso8601String(),
            'storage' => ['used' => $used, 'total' => isset($snapshot['definition']['storage_bytes']) ? (int) $snapshot['definition']['storage_bytes'] : null]];
    }
}
