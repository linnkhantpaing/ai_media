<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdjustUsageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('admin')?->is_admin;
    }

    public function rules(): array
    {
        return ['revision' => ['required', 'integer', 'min:1'], 'seconds' => ['required', 'integer', 'min:0', 'max:86400000'], 'videos' => ['required', 'integer', 'min:0', 'max:1000000'], 'reason' => ['required', 'string', 'max:500', 'not_regex:/^\\s*$/u'], 'request_id' => ['required', 'uuid']];
    }
}
