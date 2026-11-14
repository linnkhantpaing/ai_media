<?php

namespace App\Http\Middleware;

use App\Services\Media\AutomaticMediaWorker;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WakeMediaWorker
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->user()) {
            app(AutomaticMediaWorker::class)->wake();
        }

        return $response;
    }
}
