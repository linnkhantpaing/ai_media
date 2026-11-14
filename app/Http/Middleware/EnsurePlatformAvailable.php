<?php

namespace App\Http\Middleware;

use App\Services\GeneralSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        $blocked = ['media.youtube', 'media.initialize', 'media.upload', 'media.complete', 'media.preview.create', 'media.start', 'media.generate', 'media.retry', 'media.import.retry'];
        $maintenance = app(GeneralSettings::class)->current()['configuration']['maintenance'];
        if ($maintenance['enabled'] && in_array($request->route()?->getName(), $blocked, true)) {
            return response()->json(['code' => 'platform_maintenance', 'message' => $maintenance['message']], 503, ['Retry-After' => '300']);
        }

        return $next($request);
    }
}
