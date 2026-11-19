<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Admin subscription actions are limited to closing access. Paid periods are
 * created, renewed or changed only by approving a payment in Payments.
 */
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
        return [
            'action' => ['required', Rule::in(['cancel', 'end'])],
            'request_id' => ['required', 'uuid'], 'revision' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'], 'note' => ['nullable', 'string', 'max:500'],
            'confirm_end' => [$this->input('action') === 'end' ? 'required' : 'sometimes', 'accepted'],
            'package_id' => ['prohibited'], 'package_version' => ['prohibited'], 'validity_mode' => ['prohibited'],
            'starts_on' => ['prohibited'], 'ends_on' => ['prohibited'], 'timing' => ['prohibited'],
            'snapshot' => ['prohibited'], 'closed_kind' => ['prohibited'], 'user_id' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['action.in' => 'Paid periods are created only by approving a payment. Use Payments to renew or change a package.'];
    }
}
