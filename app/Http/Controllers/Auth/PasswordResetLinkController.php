<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\CustomerAccounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class PasswordResetLinkController extends Controller
{
    public function create(Request $request): Response
    {
        $email = $request->query('email');

        return Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
            'email' => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? Str::limit($email, 254, '') : '',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }

        $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:254']]);

        // Without working outgoing mail the link would be silently dropped, so say so (for every
        // address alike, which reveals nothing about which accounts exist) instead of "check your inbox".
        if (! app(CustomerAccounts::class)->mailReady()) {
            Log::warning('Password recovery requested while outgoing email is not configured.');

            return back()->with('status', 'mail_unavailable');
        }

        try {
            Password::sendResetLink($request->only('email'));
        } catch (TransportExceptionInterface $exception) {
            Log::warning('Password recovery delivery failed.', ['type' => $exception::class]);
        }

        return back()->with('status', 'reset_link_sent');
    }
}
