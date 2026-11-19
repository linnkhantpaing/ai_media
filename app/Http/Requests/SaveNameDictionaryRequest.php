<?php

namespace App\Http\Requests;

use App\Support\NameDictionary;
use Illuminate\Foundation\Http\FormRequest;

class SaveNameDictionaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['sometimes', 'nullable', 'uuid'],
            ...NameDictionary::rules(),
        ];
    }
}
