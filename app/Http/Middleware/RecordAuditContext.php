<?php

namespace App\Http\Middleware;

use App\Services\AuditTrail;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class RecordAuditContext
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (HttpExceptionInterface|AuthorizationException $exception) {
            if ($exception instanceof AuthorizationException || $exception->getStatusCode() === 403) {
                $this->blocked($request);
            }
            throw $exception;
        }
        if ($response->getStatusCode() === 403) {
            $this->blocked($request);
        }

        return $response;
    }

    private function blocked(Request $request): void
    {
        if ($request->attributes->get('audit_blocked_recorded')) {
            return;
        }
        $request->attributes->set('audit_blocked_recorded', true);
        $user = $request->user('admin') ?? $request->user('web');
        app(AuditTrail::class)->record('security', (string) Str::uuid(), 'security', 'access_blocked', 'blocked', $user?->id,
            'route', null, $request->route()?->getName() ?? 'Protected resource');
    }
}
