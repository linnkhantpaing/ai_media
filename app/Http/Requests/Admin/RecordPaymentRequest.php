<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class RecordPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('admin')?->is_admin;
    }

    public function rules(): array
    {
        return [
            'revision' => [Rule::excludeIf($this->route('payment') === null), 'required', 'integer', 'min:1'],
            'reason' => [Rule::excludeIf($this->route('payment') === null), 'required', 'string', 'max:500', 'not_regex:/^\\s*$/u'],
            'request_id' => ['required', 'uuid'], 'config_revision' => ['required', 'integer', 'min:0'], 'user_id' => ['required', 'integer', 'exists:users,id'], 'package_id' => ['required', 'integer', 'exists:packages,id'], 'package_version' => ['required', 'integer', 'min:1'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'], 'purpose' => ['required', Rule::in(['new', 'renewal', 'change'])], 'entry' => ['required', Rule::in(['request', 'received'])],
            'received' => ['nullable', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'], 'reference' => ['nullable', 'string', 'max:150', 'not_regex:/[\x00-\x1F\x7F]/u'], 'sender' => ['nullable', 'string', 'max:150'],
            'transfer_date' => ['nullable', 'date', 'before_or_equal:now'], 'internal_note' => ['nullable', 'string', 'max:1500'],
            'receipt' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp,pdf', 'max:10240', Rule::when($this->file('receipt') instanceof UploadedFile && str_starts_with((string) $this->file('receipt')->getMimeType(), 'image/'), ['image', 'dimensions:max_width=6000,max_height=6000'])],
        ];
    }
}
