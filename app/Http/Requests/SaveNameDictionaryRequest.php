<?php

namespace App\Http\Requests;

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
            'name_dictionary' => ['present', 'array', 'max:50'],
            'name_dictionary.*' => ['array:original,translated,pronunciation'],
            'name_dictionary.*.original' => ['required', 'string', 'max:100'],
            'name_dictionary.*.translated' => ['required', 'string', 'max:100'],
            'name_dictionary.*.pronunciation' => ['nullable', 'string', 'max:100'],
        ];
    }
}
