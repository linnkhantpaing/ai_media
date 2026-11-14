<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\GeneralSettings;
use App\Services\PlatformMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class GeneralSettingsController extends Controller
{
    public function show(GeneralSettings $settings): Response
    {
        return Inertia::render('admin/general-settings', ['general' => $settings->adminData(), 'generalOptions' => ['timezones' => \DateTimeZone::listIdentifiers()], 'generalLinks' => [
            'save' => route('admin.settings.general.save', false), 'test' => route('admin.settings.general.test', false)]]);
    }

    public function save(Request $request, GeneralSettings $settings): JsonResponse
    {
        $data = $request->input('configuration');
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        abort_unless(is_array($data), 422);
        foreach (['name', 'tagline', 'support_email', 'support_phone', 'support_url'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]);
            }
        }
        $data = Validator::make($data, [
            'revision' => 'required|integer|min:0', 'name' => 'required|string|max:80', 'tagline' => 'nullable|string|max:120',
            'language' => ['required', Rule::in(['en', 'my', 'th'])], 'timezone' => ['required', Rule::in(\DateTimeZone::listIdentifiers())],
            'date_format' => ['required', Rule::in(['medium', 'dmy', 'mdy', 'iso'])], 'time_format' => ['required', Rule::in(['12', '24'])],
            'support_email' => 'nullable|email:rfc|max:254', 'support_phone' => 'nullable|string|max:40',
            'support_url' => ['nullable', 'url:https', 'max:500'],
            'smtp' => 'required|array:enabled,host,port,encryption,username,password,from_name,from_email,reply_to',
            'smtp.enabled' => 'required|boolean', 'smtp.host' => 'nullable|required_if:smtp.enabled,true|string|max:253|regex:/^[a-zA-Z0-9.-]+$/',
            'smtp.port' => 'required|integer|min:1|max:65535', 'smtp.encryption' => ['required', Rule::in(['tls', 'ssl', 'none'])],
            'smtp.username' => 'nullable|string|max:254', 'smtp.password' => 'nullable|string|max:1000',
            'smtp.from_name' => 'nullable|required_if:smtp.enabled,true|string|max:80',
            'smtp.from_email' => 'nullable|required_if:smtp.enabled,true|email:rfc|max:254', 'smtp.reply_to' => 'nullable|email:rfc|max:254',
            'maintenance' => 'required|array:enabled,message', 'maintenance.enabled' => 'required|boolean',
            'maintenance.message' => 'nullable|required_if:maintenance.enabled,true|string|max:200',
            'confirm_maintenance' => 'sometimes|boolean',
        ])->validate();
        $data['tagline'] = $data['tagline'] ?? '';
        foreach (['support_email', 'support_phone', 'support_url'] as $key) {
            $data[$key] = $data[$key] ?? '';
        }
        foreach (['host', 'username', 'password', 'from_name', 'from_email', 'reply_to'] as $key) {
            $data['smtp'][$key] = $data['smtp'][$key] ?? '';
        }
        $data['maintenance']['message'] = $data['maintenance']['message'] ?? '';
        if ($data['smtp']['enabled'] && $data['smtp']['encryption'] === 'none' && ! in_array($data['smtp']['host'], ['localhost', '127.0.0.1', '::1'], true)) {
            throw ValidationException::withMessages(['smtp.encryption' => 'Unencrypted SMTP is permitted only on localhost.']);
        }
        if ($data['smtp']['enabled'] && $data['smtp']['username'] && ! $data['smtp']['password'] && ! $settings->current()['configuration']['smtp']['password']) {
            throw ValidationException::withMessages(['smtp.password' => 'Enter the SMTP password.']);
        }
        if ($data['maintenance']['enabled'] && ! $settings->current()['configuration']['maintenance']['enabled'] && ! ($data['confirm_maintenance'] ?? false)) {
            throw ValidationException::withMessages(['confirm_maintenance' => 'Confirm maintenance mode before saving.']);
        }
        $request->validate(['main_logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=6000,max_height=6000'],
            'light_logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=6000,max_height=6000'],
            'favicon' => ['nullable', 'file', 'mimes:png,ico', 'max:256']]);
        $result = $settings->save($data, array_filter($request->only(['main_logo', 'light_logo', 'favicon'])), $request->user('admin'));

        return response()->json(['general' => $result, 'app' => $settings->publicData()]);
    }

    public function test(Request $request, GeneralSettings $settings, PlatformMail $mail): JsonResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'kind' => ['required', Rule::in(['connection', 'email'])], 'recipient' => 'nullable|required_if:kind,email|email:rfc|max:254']);
        $state = $settings->current();
        abort_if($state['revision'] !== (int) $data['revision'], 409, 'settings_conflict');
        if (! $state['configuration']['smtp']['enabled']) {
            throw ValidationException::withMessages(['smtp.enabled' => 'Enable and save outgoing email first.']);
        }
        $report = ['kind' => $data['kind'], 'status' => 'success', 'code' => null, 'at' => now()->toIso8601String()];
        try {
            $mail->test($state['configuration'], $data['kind'] === 'email' ? $data['recipient'] : null);
        } catch (\Throwable $e) {
            $report['status'] = 'error';
            // Never expose SMTP credentials, raw provider responses or connection strings.
            $report['code'] = str_contains(strtolower($e->getMessage()), 'authenticate') ? 'smtp_authentication_failed' : 'smtp_connection_failed';
        }
        $settings->recordMailReport($state['revision'], $report, $request->user('admin'));

        return response()->json(['report' => $report]);
    }

    public function asset(string $file, GeneralSettings $settings): BinaryFileResponse
    {
        $c = $settings->current()['configuration'];
        abort_unless(collect(['main_logo', 'light_logo', 'favicon'])->contains(fn ($key) => ($c[$key]['file'] ?? null) === $file), 404);
        abort_unless(Storage::disk('local')->exists('branding/'.$file), 404);

        return response()->file(Storage::disk('local')->path('branding/'.$file), ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=3600']);
    }
}
