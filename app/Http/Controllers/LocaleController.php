<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', Rule::in(['my', 'en', 'th'])],
            'return_to' => ['sometimes', 'required', 'string', 'max:2048', 'regex:#^/(?!/)[a-zA-Z0-9/_?=&%.+@~-]*$#D'],
        ]);

        if ($user = $request->user($request->is('admin', 'admin/*') ? 'admin' : 'web')) {
            $user->forceFill(['preferred_locale' => $data['locale']])->save();
        }

        Cookie::queue(cookie(
            'ui_locale', $data['locale'], 60 * 24 * 365, '/', null,
            $request->isSecure() || app()->isProduction(), true, false, 'lax',
        ));

        return isset($data['return_to']) ? redirect()->to($data['return_to'], 303) : back(303);
    }
}
