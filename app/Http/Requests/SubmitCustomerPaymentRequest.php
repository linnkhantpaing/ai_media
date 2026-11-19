<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class SubmitCustomerPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && ! $this->user()->is_admin && $this->user()->account_status === 'active';
    }

    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'config_revision' => ['required', 'integer', 'min:0'],
            'revision' => [$this->route('payment') ? 'required' : 'nullable', 'integer', 'min:1'],
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'package_version' => ['required', 'integer', 'min:1'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'received' => ['required', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D'],
            'reference' => ['nullable', 'string', 'max:150', 'not_regex:/[\x00-\x1F\x7F]/'],
            'sender' => ['nullable', 'string', 'max:150', 'not_regex:/[\x00-\x1F\x7F]/'],
            'transfer_date' => ['nullable', 'date', 'before_or_equal:now'],
            'receipt' => ['nullable', File::types(['png', 'jpg', 'jpeg', 'webp', 'pdf'])->max(10240), function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value && str_starts_with((string) $value->getMimeType(), 'image/')) {
                    $size = @getimagesize($value->getRealPath());
                    if (! $size || $size[0] > 6000 || $size[1] > 6000) {
                        $fail('The receipt image must be at most 6000 × 6000 pixels.');
                    }
                }
            }],
            'user_id' => ['prohibited'], 'purpose' => ['prohibited'], 'entry' => ['prohibited'],
            'action' => ['prohibited'], 'internal_note' => ['prohibited'], 'expected_minor' => ['prohibited'],
        ];
    }
}
