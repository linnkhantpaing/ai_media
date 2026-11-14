<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMediaScriptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['lines' => ['required', 'array', 'min:1', 'max:500'], 'lines.*.index' => ['required', 'integer', 'min:0', 'distinct'], 'lines.*.text' => ['required', 'string', 'max:1500']];
    }
}
