<?php

use App\Http\Controllers\Admin\GeneralSettingsController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\CustomerSupportController;
use App\Http\Controllers\FrameController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MediaProjectController;
use App\Http\Controllers\MediaToolsController;
use App\Http\Controllers\OperationsController;
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
    Route::inertia('/', 'workspace')->name('workspace');
    Route::prefix('support-api')->name('support.')->group(function (): void {
        Route::get('/', [CustomerSupportController::class, 'index'])->name('index');
        Route::post('/', [CustomerSupportController::class, 'store'])->middleware('throttle:10,1')->name('store');
        Route::get('/{conversation}', [CustomerSupportController::class, 'show'])->whereNumber('conversation')->name('show');
        Route::post('/{conversation}/messages', [CustomerSupportController::class, 'send'])->whereNumber('conversation')->middleware('throttle:20,1')->name('send');
        Route::post('/{conversation}/read', [CustomerSupportController::class, 'read'])->whereNumber('conversation')->middleware('throttle:120,1')->name('read');
        Route::get('/{conversation}/attachments/{attachment}', [CustomerSupportController::class, 'attachment'])->whereNumber('conversation')->whereUuid('attachment')->name('attachment');
    });
    Route::get('/projects', [MediaProjectController::class, 'index'])->name('projects.index');
    Route::get('/projects/new', [MediaProjectController::class, 'create'])->name('projects.create');
    foreach (['setup' => 'projectSetup', 'script' => 'scriptReview', 'preview' => 'previewStudio', 'processing' => 'projectProcessing', 'result' => 'projectResult'] as $step => $frame) {
        Route::get('/projects/new/'.$step, [MediaProjectController::class, 'legacy'])->defaults('step', $step)->name('projects.step.'.$step);
    }

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
        Route::get('/files/{kind}', [MediaProjectController::class, 'file'])->whereIn('kind', ['source', 'poster', 'video', 'audio', 'subtitles'])->name('media.file');
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
            Route::patch('/script', [MediaProjectController::class, 'script'])->name('media.script');
        });
    });

    Route::get('/settings/preferences', [FrameController::class, 'show'])->defaults('frame', 'preferences')->name('settings.preferences');
    Route::middleware('can:manage-system')->group(function (): void {
        Route::get('/operations/jobs', [OperationsController::class, 'index'])->name('operations.jobs');
        Route::get('/operations/workers', [OperationsController::class, 'workers'])->name('operations.workers');
        Route::get('/operations/jobs/{mediaTask}', [OperationsController::class, 'show'])->whereNumber('mediaTask')->name('operations.job');
        Route::post('/operations/jobs/{mediaTask}/action', [OperationsController::class, 'action'])->whereNumber('mediaTask')->middleware('throttle:media-actions')->name('operations.action');
        Route::get('/operations/jobs/{mediaTask}/files/{kind}', [OperationsController::class, 'file'])->whereNumber('mediaTask')->whereIn('kind', ['poster', 'video', 'audio', 'subtitles'])->name('operations.file');
    });
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
