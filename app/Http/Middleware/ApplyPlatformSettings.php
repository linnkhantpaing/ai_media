<?php

namespace App\Http\Middleware;

use App\Services\GeneralSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyPlatformSettings
{
    public function handle(Request $request, Closure $next): Response
    {
        $settings = app(GeneralSettings::class);
        $state = $settings->current();
        $request->attributes->set('platform_settings', $settings->publicData($state));
        $oldName = config('app.name');
        $oldMail = config('mail');
        config(['app.name' => $state['configuration']['name']]);
        $smtp = $state['configuration']['smtp'];
        if ($smtp['enabled']) {
            config(['mail.default' => 'platform', 'mail.mailers.platform' => $settings->mailConfig($smtp),
                'mail.reply_to' => $smtp['reply_to'] ? ['address' => $smtp['reply_to']] : null,
                'mail.from' => ['name' => $smtp['from_name'], 'address' => $smtp['from_email']]]);
            app('mail.manager')->purge('platform');
        }
        if (! $smtp['enabled']) {
            config(['mail.default' => 'array']);
        }
        try {
            return $next($request);
        } finally {
            app('mail.manager')->purge('platform');
            config(['app.name' => $oldName, 'mail' => $oldMail]);
        }
    }
}
