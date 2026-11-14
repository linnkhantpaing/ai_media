<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubscriptionActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('admin')?->is_admin;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    public function rules(): array
    {
        $period = in_array($this->input('action'), ['renew', 'change'], true);

        return [
            'action' => ['required', Rule::in(['renew', 'extend', 'change', 'cancel', 'end'])],
            'request_id' => ['required', 'uuid'], 'revision' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'], 'note' => ['nullable', 'string', 'max:500'],
            'package_id' => [$period ? 'required' : 'prohibited', 'integer', 'exists:packages,id'],
            'package_version' => [$period ? 'required' : 'prohibited', 'integer', 'min:1'],
            'validity_mode' => [$period ? 'required' : 'prohibited', Rule::in(['package', 'custom'])],
            'starts_on' => [$period ? 'required' : 'prohibited', 'date_format:Y-m-d'],
            'ends_on' => [$period || $this->input('action') === 'extend' ? 'required' : 'prohibited', 'date_format:Y-m-d'],
            'timing' => [$period ? 'required' : 'prohibited', Rule::in(['after', 'now', 'custom'])],
            'confirm_end' => [$this->input('action') === 'end' ? 'required' : 'sometimes', 'accepted'],
            'replace_upcoming' => ['sometimes', 'boolean'], 'replace_current' => ['sometimes', 'boolean'],
            'snapshot' => ['prohibited'], 'closed_kind' => ['prohibited'], 'user_id' => ['prohibited'],
        ];
    }
}
