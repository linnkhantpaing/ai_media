<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AdminLoginRequest extends LoginRequest
{
    public function authenticate(): void
    {
        $key = 'admin-login:'.hash('sha256', $this->string('email').'|'.$this->ip());
        $ipKey = 'admin-login-ip:'.hash('sha256', (string) $this->ip());

        if (RateLimiter::tooManyAttempts($key, 5) || RateLimiter::tooManyAttempts($ipKey, 20)) {
            event(new Lockout($this));
            throw ValidationException::withMessages([
                'email' => __('auth.throttle', [
                    'seconds' => max(RateLimiter::availableIn($key), RateLimiter::availableIn($ipKey)),
                ]),
            ]);
        }

        $guard = Auth::guard('admin');
        $guard->setRememberDuration(60 * 24 * 7);

        if (! $guard->attempt([
            ...$this->only('email', 'password'),
            'is_admin' => true,
        ], $this->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            RateLimiter::hit($ipKey, 60);
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($key);
    }
}
