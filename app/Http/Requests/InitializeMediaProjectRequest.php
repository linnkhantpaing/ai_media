<?php

namespace App\Http\Requests;

use App\Models\MediaProject;
use App\Services\Media\StorageSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class InitializeMediaProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $maximum = app(StorageSettings::class)->limits()['max_bytes'];
        $client = $this->input('client_id');
        if (is_string($client) && Str::isUuid($client) && $this->user()) {
            $existing = MediaProject::where('user_id', $this->user()->id)->where('client_id', $client)->first();
            $maximum = $existing?->storage_policy['max_bytes'] ?? $maximum;
        }

        return [
            'client_id' => ['required', 'uuid'],
            'title' => ['nullable', 'string', 'max:250'],
            'filename' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                if (basename(str_replace('\\', '/', $value)) !== $value || preg_match('/[\x00-\x1F]/', $value)) {
                    $fail('Invalid filename.');
                }
                if (! in_array(strtolower(pathinfo($value, PATHINFO_EXTENSION)), ['mp4', 'mov', 'mkv', 'webm', 'm4v'], true)) {
                    $fail('Choose an MP4, MOV, MKV, WEBM or M4V video.');
                }
            }],
            'size' => ['required', 'integer', 'min:1', 'max:'.$maximum],
            'fingerprint' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ];
    }
}
