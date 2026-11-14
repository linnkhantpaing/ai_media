<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveProviderSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('admin')?->can('manage-system');
    }

    public function rules(): array
    {
        return [
            'revision' => ['required', 'integer', 'min:0'], 'enabled' => ['required', 'boolean'],
            'credentials' => ['present', 'array', 'max:5'],
            'credentials.*' => ['array:id,label,secret,enabled'],
            'credentials.*.id' => ['required', 'string', 'distinct', 'max:50', 'regex:/^(server|[a-f0-9-]{36})$/'],
            'credentials.*.label' => ['required', 'string', 'max:100'],
            'credentials.*.secret' => ['nullable', 'string', 'min:16', 'max:256', 'regex:/^[A-Za-z0-9._-]+$/'],
            'credentials.*.enabled' => ['required', 'boolean'],
            'text_models' => ['required', 'array', 'min:1', 'max:4'],
            'text_models.*' => ['required', 'string', 'distinct', 'regex:/^gemini-[a-zA-Z0-9._-]{1,100}$/'],
            'speech_model' => ['required', 'string', 'regex:/^gemini-[a-zA-Z0-9._-]*tts[a-zA-Z0-9._-]*$/'],
            'voice' => ['required', Rule::in(config('media.voices'))],
            'speech_rate' => ['required', 'numeric', 'between:0.5,2'],
            'voice_volume' => ['required', 'numeric', 'between:0,2'],
            'timeout' => ['required', 'integer', 'between:30,300'],
            'auto_processing' => ['sometimes', 'boolean'],
            'worker_idle_seconds' => ['sometimes', 'integer', 'between:15,300'],
            'capability' => ['sometimes', Rule::in(['text', 'speech'])],
        ];
    }
}
