<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $fallback = $request->attributes->get('platform_settings')['language'] ?? 'my';
        $locale = $request->user($request->is('admin', 'admin/*') ? 'admin' : 'web')?->preferred_locale ?? $request->cookie('ui_locale') ?? $fallback;
        App::setLocale(in_array($locale, ['my', 'en', 'th'], true) ? $locale : $fallback);

        return $next($request);
    }
}
