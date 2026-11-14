<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('auth/login', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();
        $request->session()->regenerate();
        $user = $request->user('web');
        $request->session()->put('customer_auth_version:'.$user->id, $user->auth_version);
        if (! $user->is_admin) {
            $user->forceFill(['last_login_at' => now()])->save();
        }

        $locale = $request->cookie('ui_locale');
        if (in_array($locale, ['my', 'en', 'th'], true)) {
            $request->user()->forceFill(['preferred_locale' => $locale])->save();
        }

        $intended = $request->session()->pull('url.intended');
        $destination = route('workspace', absolute: false);

        if (is_string($intended) && ! str_contains($intended, '\\') && ! preg_match('/[\x00-\x20]/', $intended)) {
            $parts = parse_url($intended);
            if (is_array($parts) && (! isset($parts['host']) || $parts['host'] === $request->getHost())
                && (! isset($parts['scheme']) || in_array($parts['scheme'], ['http', 'https'], true))) {
                $path = $parts['path'] ?? '/';
                if (str_starts_with($path, '/') && ! str_starts_with($path, '//')
                    && $path !== '/admin' && ! str_starts_with($path, '/admin/')) {
                    $destination = $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
                }
            }
        }

        return redirect()->to($destination);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('login');
    }
}
