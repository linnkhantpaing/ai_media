<?php

use App\Http\Controllers\Admin\GeneralSettingsController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\CustomerBillingController;
use App\Http\Controllers\CustomerDashboardController;
use App\Http\Controllers\CustomerNotificationController;
use App\Http\Controllers\CustomerPlanController;
use App\Http\Controllers\CustomerQueueController;
use App\Http\Controllers\CustomerSupportController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MediaProjectController;
use App\Http\Controllers\MediaToolsController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\PreferencesController;
use App\Http\Controllers\RecapStudioController;
use App\Http\Middleware\EnsureCustomerAccess;
use App\Http\Middleware\EnsurePlatformAvailable;
use Illuminate\Support\Facades\Route;

Route::get('/settings/providers', fn () => redirect()->route('admin.settings.providers'))->name('settings.providers.legacy');
Route::get('/settings/storage', fn () => redirect()->route('admin.settings.storage'))->name('settings.storage.legacy');
Route::get('/settings/defaults', fn () => redirect()->route('admin.settings.defaults'))->name('settings.defaults.legacy');

require __DIR__.'/admin.php';
Route::get('/branding/{file}', [GeneralSettingsController::class, 'asset'])->where('file', '[a-f0-9-]{36}\\.(png|jpg|jpeg|webp|ico)')->name('branding.asset');

Route::middleware('guest:web')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login-requests')->name('login.store');
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:password-reset')->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->middleware('throttle:password-reset')->name('password.store');
});

Route::post('/locale', LocaleController::class)->middleware('throttle:locale-switch')->name('locale.update');

Route::middleware(['auth:web', EnsureCustomerAccess::class, 'auth.session', EnsurePlatformAvailable::class])->group(function (): void {
    Route::get('/', [CustomerDashboardController::class, 'index'])->name('workspace');
    Route::get('/dashboard/live', [CustomerDashboardController::class, 'live'])->middleware('throttle:120,1')->name('dashboard.live');
    Route::get('/dashboard/usage', [CustomerDashboardController::class, 'usage'])->middleware('throttle:60,1')->name('dashboard.usage');
    Route::post('/dashboard/checklist/hide', [CustomerDashboardController::class, 'hideChecklist'])->middleware('throttle:20,1')->name('dashboard.checklist');
    Route::prefix('support-api')->name('support.')->group(function (): void {
        Route::get('/chat', [CustomerSupportController::class, 'chat'])->name('chat');
        Route::post('/chat/messages', [CustomerSupportController::class, 'sendChat'])->middleware('throttle:20,1')->name('chat.send');
        Route::patch('/chat/messages/{message}', [CustomerSupportController::class, 'editChatMessage'])->whereNumber('message')->middleware('throttle:20,1')->name('chat.edit');
        Route::delete('/chat/messages/{message}', [CustomerSupportController::class, 'deleteChatMessage'])->whereNumber('message')->middleware('throttle:20,1')->name('chat.delete');
        Route::post('/chat/read', [CustomerSupportController::class, 'readChat'])->middleware('throttle:120,1')->name('chat.read');
        Route::get('/', [CustomerSupportController::class, 'index'])->name('index');
        Route::post('/', [CustomerSupportController::class, 'store'])->middleware('throttle:10,1')->name('store');
        Route::get('/{conversation}', [CustomerSupportController::class, 'show'])->whereNumber('conversation')->name('show');
        Route::post('/{conversation}/messages', [CustomerSupportController::class, 'send'])->whereNumber('conversation')->middleware('throttle:20,1')->name('send');
        Route::post('/{conversation}/read', [CustomerSupportController::class, 'read'])->whereNumber('conversation')->middleware('throttle:120,1')->name('read');
        Route::get('/{conversation}/attachments/{attachment}', [CustomerSupportController::class, 'attachment'])->whereNumber('conversation')->whereUuid('attachment')->name('attachment');
    });
    Route::get('/account/billing', [CustomerBillingController::class, 'index'])->name('account.billing');
    Route::prefix('account/billing-api')->name('account.billing.')->controller(CustomerBillingController::class)->group(function (): void {
        Route::get('/options', 'options')->name('options');
        Route::post('/', 'store')->middleware('throttle:10,1')->name('store');
        Route::get('/methods/{method}/qr', 'methodQr')->whereNumber('method')->name('method-qr');
        Route::get('/{payment}', 'show')->whereNumber('payment')->name('show');
        Route::post('/{payment}', 'update')->whereNumber('payment')->middleware('throttle:10,1')->name('update');
        Route::get('/{payment}/receipts/{receipt}', 'receipt')->whereNumber('payment')->whereUuid('receipt')->name('receipt');
        Route::get('/{payment}/qr', 'paymentQr')->whereNumber('payment')->name('payment-qr');
    });
    Route::get('/notifications', [CustomerNotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/feed', [CustomerNotificationController::class, 'feed'])->middleware('throttle:120,1')->name('notifications.feed');
    Route::post('/notifications/action', [CustomerNotificationController::class, 'action'])->middleware('throttle:60,1')->name('notifications.action');
    Route::get('/account/plan', [CustomerPlanController::class, 'index'])->name('account.plan');
    Route::get('/account/plan/exports/{run}', [CustomerPlanController::class, 'show'])->whereUuid('run')->name('account.plan.export');
    Route::get('/projects', [MediaProjectController::class, 'index'])->name('projects.index');
    Route::get('/projects/new', [MediaProjectController::class, 'create'])->name('projects.create');
    foreach (['setup' => 'projectSetup', 'script' => 'scriptReview', 'preview' => 'previewStudio', 'processing' => 'projectProcessing', 'result' => 'projectResult'] as $step => $frame) {
        Route::get('/projects/new/'.$step, [MediaProjectController::class, 'legacy'])->defaults('step', $step)->name('projects.step.'.$step);
    }

    Route::get('/media/queue', CustomerQueueController::class)->middleware('throttle:120,1')->name('media.queue.feed');
    Route::put('/media/name-dictionary', [RecapStudioController::class, 'dictionary'])->middleware('throttle:media-actions')->name('media.dictionary.save');
    Route::get('/media/presets', [MediaToolsController::class, 'presets'])->name('media.presets');
    Route::post('/media/presets', [MediaToolsController::class, 'savePreset'])->middleware('throttle:media-actions')->name('media.presets.save');
    Route::delete('/media/presets/{preset}', [MediaToolsController::class, 'deletePreset'])->whereNumber('preset')->middleware('throttle:media-actions')->name('media.presets.delete');
    Route::post('/media/projects/youtube', [MediaProjectController::class, 'importYouTube'])->middleware('throttle:media-create')->name('media.youtube');
    Route::post('/media/projects', [MediaProjectController::class, 'initialize'])->middleware('throttle:media-create')->name('media.initialize');
    Route::get('/projects/{mediaProject}/upload', [MediaProjectController::class, 'uploadPage'])->whereUuid('mediaProject')->name('media.upload.page');
    Route::get('/projects/{mediaProject}/setup', [MediaProjectController::class, 'setup'])->whereUuid('mediaProject')->name('media.setup');
    Route::get('/projects/{mediaProject}/analyze', [MediaProjectController::class, 'analysis'])->whereUuid('mediaProject')->name('media.analysis');
    Route::prefix('/media/projects/{mediaProject}')->whereUuid('mediaProject')->group(function (): void {
        Route::get('/', [MediaProjectController::class, 'status'])->name('media.status');
        Route::put('/upload', [MediaProjectController::class, 'upload'])->name('media.upload');
        Route::post('/complete', [MediaProjectController::class, 'complete'])->name('media.complete');
        Route::patch('/', [MediaProjectController::class, 'update'])->name('media.update');
        Route::delete('/', [MediaProjectController::class, 'destroy'])->name('media.destroy');
        Route::get('/files/{kind}', [MediaProjectController::class, 'file'])->whereIn('kind', ['source', 'poster', 'video', 'audio', 'subtitles', 'styledSubtitles'])->name('media.file');
        Route::post('/previews', [MediaToolsController::class, 'createPreview'])->middleware('throttle:media-previews')->name('media.preview.create');
        Route::get('/previews/{preview}', [MediaToolsController::class, 'preview'])->whereUuid('preview')->name('media.preview.status');
        Route::post('/previews/{preview}/cancel', [MediaToolsController::class, 'cancelPreview'])->whereUuid('preview')->middleware('throttle:media-actions')->name('media.preview.cancel');
        Route::get('/previews/{preview}/file', [MediaToolsController::class, 'previewFile'])->whereUuid('preview')->name('media.preview.file');
        Route::middleware('throttle:media-actions')->group(function (): void {
            Route::post('/import/retry', [MediaProjectController::class, 'retryImport'])->name('media.import.retry');
            Route::post('/import/cancel', [MediaProjectController::class, 'cancelImport'])->name('media.import.cancel');
            Route::post('/reopen', [MediaProjectController::class, 'reopen'])->name('media.reopen');
            Route::post('/start', [MediaProjectController::class, 'start'])->name('media.start');
            Route::post('/generate', [MediaProjectController::class, 'generate'])->name('media.generate');
            Route::post('/retry', [MediaProjectController::class, 'retry'])->name('media.retry');
            Route::post('/cancel', [MediaProjectController::class, 'cancel'])->name('media.cancel');
            Route::patch('/scenes', [RecapStudioController::class, 'scenes'])->name('media.scenes');
            Route::patch('/script', [MediaProjectController::class, 'script'])->name('media.script');
        });
    });

    Route::get('/settings/preferences', [PreferencesController::class, 'show'])->name('settings.preferences');
    Route::prefix('settings/preferences')->name('settings.preferences.')->controller(PreferencesController::class)->group(function (): void {
        Route::put('/', 'save')->middleware('throttle:30,1')->name('save');
        Route::get('/voice-sample', 'voiceSample')->middleware('throttle:media-previews')->name('voice-sample');
        Route::put('/email', 'changeEmail')->middleware('throttle:6,1')->name('email');
        Route::put('/password', 'changePassword')->middleware('throttle:6,1')->name('password');
        Route::post('/photo', 'savePhoto')->middleware('throttle:10,1')->name('photo');
        Route::delete('/photo', 'removePhoto')->middleware('throttle:10,1')->name('photo.remove');
        Route::get('/avatar', 'avatar')->middleware('throttle:120,1')->name('avatar');
        Route::post('/devices/sign-out-others', 'signOutOtherDevices')->middleware('throttle:6,1')->name('devices.others');
        Route::delete('/devices/{device}', 'signOutDevice')->where('device', '[a-f0-9]{32}')->middleware('throttle:20,1')->name('devices.destroy');
        Route::post('/presets', 'storePreset')->middleware('throttle:30,1')->name('presets.store');
        Route::put('/presets/order', 'orderPresets')->middleware('throttle:60,1')->name('presets.order');
        Route::put('/presets/default', 'defaultPreset')->middleware('throttle:60,1')->name('presets.default');
        Route::put('/presets/{preset}', 'updatePreset')->whereNumber('preset')->middleware('throttle:30,1')->name('presets.update');
        Route::post('/presets/{preset}/duplicate', 'duplicatePreset')->whereNumber('preset')->middleware('throttle:30,1')->name('presets.duplicate');
        Route::delete('/presets/{preset}', 'destroyPreset')->whereNumber('preset')->middleware('throttle:30,1')->name('presets.destroy');
    });
    Route::middleware('can:manage-system')->group(function (): void {
        Route::get('/operations/jobs', [OperationsController::class, 'index'])->name('operations.jobs');
        Route::get('/operations/workers', [OperationsController::class, 'workers'])->name('operations.workers');
        Route::get('/operations/jobs/{mediaTask}', [OperationsController::class, 'show'])->whereNumber('mediaTask')->name('operations.job');
        Route::post('/operations/jobs/{mediaTask}/action', [OperationsController::class, 'action'])->whereNumber('mediaTask')->middleware('throttle:media-actions')->name('operations.action');
        Route::get('/operations/jobs/{mediaTask}/files/{kind}', [OperationsController::class, 'file'])->whereNumber('mediaTask')->whereIn('kind', ['poster', 'video', 'audio', 'subtitles'])->name('operations.file');
    });
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
