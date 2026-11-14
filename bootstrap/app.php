<?php

use App\Http\Middleware\ApplyPlatformSettings;
use App\Http\Middleware\EnsureCustomerAccess;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RecordAuditContext;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\WakeMediaWorker;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToPriorityList(AuthenticateSession::class, EnsureCustomerAccess::class);
        $middleware->web(append: [ApplyPlatformSettings::class, RecordAuditContext::class, SetLocale::class, SecurityHeaders::class, HandleInertiaRequests::class, WakeMediaWorker::class]);
        $middleware->redirectGuestsTo(fn (Request $request): string => route($request->is('admin', 'admin/*') ? 'admin.login' : 'login'));
        $middleware->redirectUsersTo(fn (Request $request): string => route($request->is('admin', 'admin/*') ? 'admin.dashboard' : 'workspace'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['credentials', 'connection', 'configuration', 'smtp', 'password']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->respond(function (Response $response): Response {
            if ($response->getStatusCode() === 419 && request()->header('X-Inertia')) {
                return redirect()->route(request()->is('admin', 'admin/*') ? 'admin.login' : 'login')->withErrors(['email' => __('auth.session_expired')]);
            }

            return $response;
        });
    })->create();
