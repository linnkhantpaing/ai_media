<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AdminLoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('admin/login');
    }

    public function store(AdminLoginRequest $request): RedirectResponse
    {
        $request->authenticate();
        $request->session()->regenerate();
        $request->session()->forget('password_hash_admin');

        $locale = $request->cookie('ui_locale');
        if (in_array($locale, ['my', 'en', 'th'], true)) {
            Auth::guard('admin')->user()->forceFill(['preferred_locale' => $locale])->save();
        }

        return to_route('admin.dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('admin')->logout();
        $request->session()->forget('password_hash_admin');
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return to_route('admin.login');
    }
}
