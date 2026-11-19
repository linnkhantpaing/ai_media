<?php

namespace App\Http\Requests;

use App\Services\Media\StorageSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveStorageSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('admin')?->can('manage-system');
    }

    public function rules(): array
    {
        return [
            'revision' => ['required', 'integer', 'min:0'], 'connection' => ['required', 'array:name,endpoint,bucket,prefix,key,secret'],
            'connection.name' => ['required', 'string', 'max:80'],
            'connection.endpoint' => [Rule::requiredIf(fn () => app(StorageSettings::class)->connection()['disk'] === 'r2'), 'nullable', 'regex:~^https://[a-f0-9]{32}\.r2\.cloudflarestorage\.com/?$~'],
            'connection.bucket' => [Rule::requiredIf(fn () => app(StorageSettings::class)->connection()['disk'] === 'r2'), 'nullable', 'regex:/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', 'not_regex:/\.\./'],
            'connection.prefix' => ['nullable', 'string', 'max:200', 'regex:~^(?:[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*)?$~'],
            'connection.key' => ['nullable', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/'], 'connection.secret' => ['nullable', 'string', 'min:16', 'max:256', 'regex:~^[A-Za-z0-9_+/=.-]+$~'],
            'policy' => ['required', 'array:max_bytes,max_duration,budget,retention_enabled,keep_days,source_days,artifact_days,cache_hours,cleanup_cache'],
            'policy.max_bytes' => ['required', 'integer', 'between:1048576,2147483648'], 'policy.max_duration' => ['required', 'integer', 'between:10,86400'], 'policy.budget' => ['required', 'integer', 'between:1073741824,1099511627776', 'gte:policy.max_bytes'],
            'policy.retention_enabled' => ['required', 'boolean'], 'policy.keep_days' => ['required', 'integer', 'between:1,365'], 'policy.source_days' => ['required', 'integer', 'between:1,365', 'gte:policy.keep_days'], 'policy.artifact_days' => ['required', 'integer', 'between:1,365', 'gte:policy.source_days'], 'policy.cache_hours' => ['required', 'integer', 'between:1,168'], 'policy.cleanup_cache' => ['required', 'boolean'],
            'apply_existing' => ['sometimes', 'boolean'], 'confirm_existing' => ['required_if:apply_existing,true', 'accepted_if:apply_existing,true'], 'confirm_over_budget' => ['sometimes', 'boolean']];
    }
}
