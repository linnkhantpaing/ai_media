<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');
        if (! $user || $user->is_admin) {
            return $next($request);
        }
        $key = 'customer_auth_version:'.$user->id;
        $version = $request->session()->get($key);
        // A freshly authenticated remember cookie has no session version yet. Compare
        // the remember token through Laravel, then adopt the current account version.
        $remembered = Auth::guard('web')->viaRemember();
        if ($user->account_status !== 'active' || (! $remembered && ($version === null ? (int) $user->auth_version !== 0 : (int) $version !== (int) $user->auth_version))) {
            Auth::guard('web')->logoutCurrentDevice();
            $request->session()->forget($key);
            $request->session()->regenerate();
            $request->session()->regenerateToken();
            if ($request->expectsJson()) {
                return response()->json(['message' => __('auth.failed'), 'code' => 'account_unavailable'], 401);
            }

            return redirect()->route('login')->withErrors(['email' => __('auth.failed')]);
        }
        $request->session()->put($key, $user->auth_version);

        if ($request->routeIs('media.initialize', 'media.youtube', 'media.upload', 'media.complete', 'media.import.retry', 'media.reopen', 'media.start', 'media.generate', 'media.retry', 'media.preview.create')) {
            $assignment = $user->currentPackageAssignment;
            if (($user->managed_account || $assignment) && (! $assignment || $assignment->status() !== 'active')) {
                return response()->json(['code' => 'subscription_unavailable'], 403);
            }
        }

        return $next($request);
    }
}
