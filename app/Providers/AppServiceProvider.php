<?php

namespace App\Providers;

use App\Models\CustomerAccountEvent;
use App\Models\CustomerPackageAssignment;
use App\Models\MediaTask;
use App\Models\MediaTaskAction;
use App\Models\PackageEvent;
use App\Models\PaymentConfigurationEvent;
use App\Models\PaymentEvent;
use App\Models\PaymentRefund;
use App\Models\PaymentTransaction;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\AdminNotifications;
use App\Services\AuditTrail;
use App\Services\CustomerNotifications;
use App\Services\Media\ProviderSettings;
use App\Services\Media\StorageSettings;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProviderSettings::class);
        $this->app->singleton(StorageSettings::class);
    }

    public function boot(): void
    {

        foreach ([CustomerAccountEvent::class, PackageEvent::class, PaymentEvent::class,
            PaymentConfigurationEvent::class, MediaTaskAction::class, UsageEvent::class] as $model) {
            $model::created(fn ($record) => app(AuditTrail::class)->modelCreated($record));
        }
        foreach ([
            Login::class => ['login_success', 'success'],
            Failed::class => ['login_failed', 'failed'],
            Logout::class => ['logout', 'success'],
            PasswordReset::class => ['password_reset', 'success'],
        ] as $eventClass => [$action, $result]) {
            Event::listen($eventClass, function ($event) use ($action, $result): void {
                app(AuditTrail::class)->security($action, $result === 'failed' ? null : $event->user, $event->guard ?? 'web', $result);
            });
        }
        Event::listen(Lockout::class, function ($event): void {
            app(AuditTrail::class)->security('login_blocked', null, $event->request->is('admin/*') ? 'admin' : 'web', 'blocked');
        });

        PaymentTransaction::saved(function ($record): void {
            if ($record->wasRecentlyCreated || $record->wasChanged(['status', 'revision'])) {
                app(AdminNotifications::class)->payment($record);
                if ($record->wasChanged('status')) {
                    app(CustomerNotifications::class)->fromPayment($record);
                }
            }
        });
        PaymentRefund::saved(function ($record): void {
            app(AdminNotifications::class)->refund($record);
            if ($record->wasRecentlyCreated || $record->wasChanged('status')) {
                app(CustomerNotifications::class)->fromRefund($record);
            }
        });
        MediaTask::saved(function ($record): void {
            if ($record->wasRecentlyCreated || $record->wasChanged('status')) {
                app(AdminNotifications::class)->task($record);
                app(CustomerNotifications::class)->fromTask($record);
            }
        });
        CustomerPackageAssignment::saved(fn () => Cache::forget('admin-notifications-synced'));
        User::saved(function ($user): void {
            if ($user->wasChanged('account_status')) {
                Cache::forget('admin-notifications-synced');
            }
        });
        Gate::define('manage-system', fn (User $user): bool => (bool) $user->is_admin);

        Password::defaults(fn () => Password::min(12)->mixedCase()->numbers()->symbols());

        ResetPassword::createUrlUsing(fn (User $user, string $token): string => rtrim(config('app.url'), '/').route('password.reset', [
            'token' => $token, 'email' => $user->getEmailForPasswordReset(),
        ], absolute: false)
        );

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        foreach (['media-create' => 20, 'media-actions' => 30, 'media-previews' => 12, 'provider-settings' => 10] as $name => $maximum) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($maximum)
                ->by((string) ($request->user()?->id ?? $request->ip()))
                ->response(fn () => response()->json(['code' => 'rate_limited'], 429)));
        }

        foreach (['login-requests' => 30, 'password-reset' => 5, 'locale-switch' => 30] as $name => $maximum) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($maximum)
                ->by(hash('sha256', (string) $request->ip()))
                ->response(fn (Request $request, array $headers) => back()->withErrors(['email' => __('auth.throttle', [
                    'seconds' => $headers['Retry-After'] ?? 60,
                ])])->withHeaders($headers)
                )
            );
        }
    }
}
