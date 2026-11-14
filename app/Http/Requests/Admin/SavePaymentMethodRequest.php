<?php

namespace App\Http\Requests\Admin;

use App\Services\PaymentConfiguration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('admin')?->is_admin;
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'provider', 'internal_label', 'reason'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
        foreach (['details', 'instructions'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $this->merge([$field => $decoded]);
                }
            }
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'], 'provider' => ['required', 'string', 'max:100'],
            'internal_label' => ['nullable', 'string', 'max:100'], 'type' => ['required', Rule::in(['wallet', 'bank', 'other'])],
            'currency' => ['required', Rule::in(PaymentConfiguration::CURRENCIES)], 'status' => ['required', Rule::in(['active', 'inactive'])],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'details' => ['required', 'array:holder,identifier,branch,routing'],
            'details.holder' => ['present', 'nullable', 'string', 'max:150', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'details.identifier' => ['present', 'nullable', 'string', 'max:150', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'details.branch' => ['present', 'nullable', 'string', 'max:150'], 'details.routing' => ['present', 'nullable', 'string', 'max:150'],
            'instructions' => ['required', 'array:en,my,th'], 'instructions.en' => ['present', 'nullable', 'string', 'max:1500'],
            'instructions.my' => ['present', 'nullable', 'string', 'max:1500'], 'instructions.th' => ['present', 'nullable', 'string', 'max:1500'],
            'make_default' => ['required', 'boolean'], 'enable_currency' => ['required', 'boolean'],
            'disable_currency' => ['sometimes', 'boolean'], 'replacement_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'qr_file' => ['nullable', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120', 'dimensions:max_width=4096,max_height=4096'],
            'remove_qr' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:500'],
            'config_revision' => ['required', 'integer', 'min:0'], 'revision' => $this->isMethod('PATCH') ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'request_id' => ['required', 'uuid'], 'qr_path' => ['prohibited'], 'verification' => ['prohibited'],
        ];
    }
}
