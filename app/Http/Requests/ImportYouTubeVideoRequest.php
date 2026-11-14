<?php

namespace App\Http\Requests;

use App\Support\YouTubeUrl;
use Illuminate\Foundation\Http\FormRequest;

class ImportYouTubeVideoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        foreach (['url', 'title'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'uuid'],
            'title' => ['nullable', 'string', 'max:250'],
            'url' => ['required', 'string', 'max:2048', function (string $attribute, mixed $value, \Closure $fail): void {
                if (YouTubeUrl::normalize($value) === null) {
                    $fail('Enter a valid YouTube video or Shorts URL.');
                }
            }],
        ];
    }
}
