<?php

use App\Http\Controllers\Admin\AiCostController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\AuthenticatedSessionController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\GeneralSettingsController;
use App\Http\Controllers\Admin\JobMonitorController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\PackageController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\PaymentSettingsController;
use App\Http\Controllers\Admin\ProcessingReportController;
use App\Http\Controllers\Admin\RevenueController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\Admin\SupportController;
use App\Http\Controllers\Admin\UsageLedgerController;
use App\Http\Controllers\Admin\UserAccountController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\ProjectDefaultsController;
use App\Http\Controllers\ProviderSettingsController;
use App\Http\Controllers\StorageSettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::middleware('guest:admin')->group(function (): void {
        Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
        Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login-requests')->name('login.store');
    });

    Route::post('/locale', LocaleController::class)->middleware('throttle:locale-switch')->name('locale.update');

    Route::middleware(['auth:admin', 'auth.session', 'can:manage-system'])->group(function (): void {
        Route::redirect('/', '/admin/dashboard')->name('home');
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::prefix('support')->name('support.')->group(function (): void {
            Route::get('/', [SupportController::class, 'index'])->name('index');
            Route::get('/options', [SupportController::class, 'options'])->middleware('throttle:60,1')->name('options');
            Route::post('/', [SupportController::class, 'store'])->middleware('throttle:30,1')->name('store');
            Route::get('/{conversation}', [SupportController::class, 'show'])->whereNumber('conversation')->name('show');
            Route::post('/{conversation}/messages', [SupportController::class, 'send'])->whereNumber('conversation')->middleware('throttle:30,1')->name('send');
            Route::patch('/{conversation}/messages/{message}', [SupportController::class, 'editMessage'])->whereNumber('conversation')->whereNumber('message')->middleware('throttle:30,1')->name('messages.update');
            Route::delete('/{conversation}/messages/{message}', [SupportController::class, 'destroyMessage'])->whereNumber('conversation')->whereNumber('message')->middleware('throttle:30,1')->name('messages.destroy');
            Route::patch('/{conversation}', [SupportController::class, 'update'])->whereNumber('conversation')->middleware('throttle:60,1')->name('update');
            Route::post('/{conversation}/read', [SupportController::class, 'read'])->whereNumber('conversation')->middleware('throttle:120,1')->name('read');
            Route::get('/{conversation}/attachments/{attachment}', [SupportController::class, 'attachment'])->whereNumber('conversation')->whereUuid('attachment')->name('attachment');
        });
        Route::get('/reports/usage-processing', [ProcessingReportController::class, 'index'])->name('processing.index');
        Route::get('/reports/usage-processing/export', [ProcessingReportController::class, 'export'])->middleware('throttle:10,1')->name('processing.export');
        Route::get('/reports/usage-processing/customers/{customer}', [ProcessingReportController::class, 'show'])->whereNumber('customer')->name('processing.show');
        Route::get('/reports/ai-costs', [AiCostController::class, 'index'])->name('costs.index');
        Route::get('/reports/ai-costs/projects/{project}', [AiCostController::class, 'project'])->whereUuid('project')->middleware('throttle:60,1')->name('costs.project');
        Route::put('/reports/ai-costs/settings', [AiCostController::class, 'saveSettings'])->middleware('throttle:20,1')->name('costs.settings');
        Route::get('/reports/revenue', [RevenueController::class, 'index'])->name('revenue.index');
        Route::get('/reports/revenue/export', [RevenueController::class, 'export'])->middleware('throttle:10,1')->name('revenue.export');
        Route::get('/reports/revenue/{type}/{entry}', [RevenueController::class, 'show'])->whereIn('type', ['payment', 'refund'])->whereNumber('entry')->name('revenue.show');
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('/notifications/feed', [NotificationController::class, 'feed'])->name('notifications.feed');
        Route::post('/notifications/action', [NotificationController::class, 'action'])->middleware('throttle:60,1')->name('notifications.action');
        Route::get('/notifications/{notice}', [NotificationController::class, 'show'])->whereNumber('notice')->name('notifications.show');
        Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
        Route::get('/audit/export', [AuditController::class, 'export'])->middleware('throttle:10,1')->name('audit.export');
        Route::get('/audit/{auditEvent}', [AuditController::class, 'show'])->whereNumber('auditEvent')->name('audit.show');
        Route::get('/settings/general', [GeneralSettingsController::class, 'show'])->name('settings.general');
        Route::post('/settings/general', [GeneralSettingsController::class, 'save'])->middleware('throttle:provider-settings')->name('settings.general.save');
        Route::post('/settings/general/test', [GeneralSettingsController::class, 'test'])->middleware('throttle:provider-settings')->name('settings.general.test');
        Route::prefix('settings/providers')->name('settings.providers')->group(function (): void {
            Route::get('/', [ProviderSettingsController::class, 'show']);
            Route::middleware('throttle:provider-settings')->group(function (): void {
                Route::put('/', [ProviderSettingsController::class, 'save'])->name('.save');
                Route::post('/test', [ProviderSettingsController::class, 'test'])->name('.test');
                Route::post('/models', [ProviderSettingsController::class, 'models'])->name('.models');
                Route::get('/health', [ProviderSettingsController::class, 'health'])->name('.health');
            });
            Route::get('/samples/{sample}', [ProviderSettingsController::class, 'sample'])->whereUuid('sample')->middleware('signed:relative')->name('.sample');
        });
        Route::prefix('settings/storage')->name('settings.storage')->group(function (): void {
            Route::get('/', [StorageSettingsController::class, 'show']);
            Route::middleware('throttle:provider-settings')->group(function (): void {
                Route::put('/', [StorageSettingsController::class, 'save'])->name('.save');
                foreach (['test', 'preview', 'cleanup'] as $action) {
                    Route::post('/'.$action, [StorageSettingsController::class, $action])->name('.'.$action);
                }
                Route::post('/runs/{run}/retry', [StorageSettingsController::class, 'retry'])->whereUuid('run')->name('.retry');
            });
            foreach (['usage', 'history'] as $action) {
                Route::get('/'.$action, [StorageSettingsController::class, $action])->middleware('throttle:60,1')->name('.'.$action);
            }
        });
        Route::prefix('payments')->name('payments.')->group(function (): void {
            Route::get('/', [PaymentController::class, 'index'])->name('index');
            Route::get('/options', [PaymentController::class, 'options'])->middleware('throttle:60,1')->name('options');
            Route::post('/', [PaymentController::class, 'store'])->middleware('throttle:30,1')->name('store');
            Route::patch('/{payment}', [PaymentController::class, 'update'])->whereNumber('payment')->middleware('throttle:30,1')->name('update');
            Route::get('/{payment}', [PaymentController::class, 'show'])->whereNumber('payment')->name('show');
            Route::post('/{payment}/action', [PaymentController::class, 'action'])->whereNumber('payment')->middleware('throttle:30,1')->name('action');
            Route::get('/{payment}/receipts/{receipt}', [PaymentController::class, 'receipt'])->whereNumber('payment')->whereUuid('receipt')->name('receipt');
        });
        Route::prefix('settings/payments')->name('settings.payments')->group(function (): void {
            Route::get('/', [PaymentSettingsController::class, 'index']);
            Route::get('/methods/{method}', [PaymentSettingsController::class, 'show'])->whereNumber('method')->name('.method');
            Route::get('/methods/{method}/qr/{version}', [PaymentSettingsController::class, 'qr'])->whereNumber(['method', 'version'])->name('.qr');
            Route::middleware('throttle:30,1')->group(function (): void {
                Route::post('/methods', [PaymentSettingsController::class, 'store'])->name('.store');
                Route::patch('/methods/{method}', [PaymentSettingsController::class, 'update'])->whereNumber('method')->name('.update');
                Route::post('/methods/{method}/action', [PaymentSettingsController::class, 'action'])->whereNumber('method')->name('.action');
                Route::put('/currencies', [PaymentSettingsController::class, 'currencies'])->name('.currencies');
                Route::put('/rules', [PaymentSettingsController::class, 'rules'])->name('.rules');
            });
        });
        Route::get('/settings/defaults', [ProjectDefaultsController::class, 'show'])->name('settings.defaults');
        Route::put('/settings/defaults', [ProjectDefaultsController::class, 'save'])->middleware('throttle:provider-settings')->name('settings.defaults.save');
        Route::get('/operations/workers', [JobMonitorController::class, 'workers'])->name('jobs.workers');
        Route::get('/operations/workers/{mediaWorker}', [JobMonitorController::class, 'worker'])->whereUuid('mediaWorker')->name('jobs.worker');
        Route::get('/operations/jobs', [JobMonitorController::class, 'index'])->name('jobs.index');
        Route::get('/operations/jobs/{mediaTask}', [JobMonitorController::class, 'show'])->whereNumber('mediaTask')->name('jobs.show');
        Route::post('/operations/jobs/{mediaTask}/action', [JobMonitorController::class, 'action'])->whereNumber('mediaTask')->middleware('throttle:30,1')->name('jobs.action');
        Route::get('/operations/jobs/{mediaTask}/files/{kind}', [JobMonitorController::class, 'file'])->whereNumber('mediaTask')->whereIn('kind', ['poster', 'video', 'audio', 'subtitles'])->name('jobs.file');
        Route::get('/usage-ledger', [UsageLedgerController::class, 'index'])->name('usage.index');
        Route::get('/usage-ledger/export', [UsageLedgerController::class, 'export'])->middleware('throttle:20,1')->name('usage.export');
        Route::get('/usage-ledger/customers/{user}', [UsageLedgerController::class, 'customer'])->whereNumber('user')->name('usage.customer');
        Route::get('/usage-ledger/{usage}/video', [UsageLedgerController::class, 'video'])->whereNumber('usage')->name('usage.video');
        Route::get('/usage-ledger/{usage}', [UsageLedgerController::class, 'show'])->whereNumber('usage')->name('usage.show');
        Route::get('/usage-ledger/{usage}/poster', [UsageLedgerController::class, 'poster'])->whereNumber('usage')->name('usage.poster');
        Route::post('/usage-ledger/{usage}/adjust', [UsageLedgerController::class, 'adjust'])->whereNumber('usage')->middleware('throttle:30,1')->name('usage.adjust');
        Route::get('/subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');
        Route::get('/subscriptions/{subscription}', [SubscriptionController::class, 'show'])->whereNumber('subscription')->middleware('throttle:60,1')->name('subscriptions.show');
        Route::post('/subscriptions/{subscription}/action', [SubscriptionController::class, 'action'])->whereNumber('subscription')->middleware('throttle:30,1')->name('subscriptions.action');
        Route::get('/packages', [PackageController::class, 'index'])->name('packages.index');
        Route::post('/packages', [PackageController::class, 'store'])->middleware('throttle:30,1')->name('packages.store');
        Route::post('/packages/bulk', [PackageController::class, 'bulk'])->middleware('throttle:30,1')->name('packages.bulk');
        Route::get('/packages/{package}', [PackageController::class, 'show'])->whereNumber('package')->name('packages.show');
        Route::patch('/packages/{package}', [PackageController::class, 'update'])->whereNumber('package')->middleware('throttle:30,1')->name('packages.update');
        Route::post('/packages/{package}/action', [PackageController::class, 'action'])->whereNumber('package')->middleware('throttle:30,1')->name('packages.action');
        Route::get('/users/package-options', [UserAccountController::class, 'packageOptions'])->middleware('throttle:60,1')->name('users.package-options');
        Route::get('/users', [UserAccountController::class, 'index'])->name('users.index');
        Route::post('/users', [UserAccountController::class, 'store'])->middleware('throttle:30,1')->name('users.store');
        Route::post('/users/{customer}/login-information', [UserAccountController::class, 'sendLoginInformation'])->whereNumber('customer')->middleware('throttle:10,1')->name('users.login-information');
        Route::get('/users/{customer}/avatar', [UserAccountController::class, 'avatar'])->whereNumber('customer')->middleware('throttle:600,1')->name('users.avatar');
        Route::get('/users/{customer}', [UserAccountController::class, 'show'])->whereNumber('customer')->name('users.show');
        Route::patch('/users/{customer}', [UserAccountController::class, 'update'])->whereNumber('customer')->middleware('throttle:30,1')->name('users.update');
        Route::delete('/users/{customer}', [UserAccountController::class, 'destroy'])->whereNumber('customer')->middleware('throttle:10,1')->name('users.destroy');
        Route::post('/users/{customer}/action', [UserAccountController::class, 'action'])->whereNumber('customer')->middleware('throttle:30,1')->name('users.action');
    });

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth:admin')->name('logout');
});
