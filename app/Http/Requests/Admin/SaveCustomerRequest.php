<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class SaveCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('admin')?->is_admin;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $existingId = $this->route('customer');
        $requestId = $this->input('request_id');
        if (! $existingId && is_string($requestId) && Str::isUuid($requestId)) {
            $existingId = User::where('account_creation_key', $requestId)->where('is_admin', false)->value('id');
        }
        $assignmentFields = ['package_id', 'package_version', 'validity_mode', 'starts_on', 'ends_on'];
        $requiredAssignment = $creating ? 'required' : 'required_with:'.implode(',', $assignmentFields);
        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:254', Rule::unique(User::class)->ignore($existingId)],
            'preferred_locale' => ['required', Rule::in(['my', 'en', 'th'])],
            'note' => ['nullable', 'string', 'max:500'],
            'request_id' => ['required', 'uuid'],
            'package_id' => [$requiredAssignment, 'integer', Rule::exists('packages', 'id')],
            'package_version' => [$requiredAssignment, 'integer', 'min:1'],
            'validity_mode' => [$requiredAssignment, Rule::in(['package', 'custom'])],
            'starts_on' => [$requiredAssignment, 'date_format:Y-m-d'],
            'ends_on' => [$requiredAssignment, 'date_format:Y-m-d', 'after:starts_on'],
            'is_admin' => ['prohibited'], 'auth_version' => ['prohibited'], 'balance' => ['prohibited'], 'starts_at' => ['prohibited'], 'ends_at' => ['prohibited'],
        ];
        if ($creating) {
            return [...$rules,
                'password' => ['required', 'string', 'confirmed', Password::defaults(), function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && strlen($value) > 72) {
                        $fail(__('auth.password_bytes'));
                    }
                }],
                'status' => ['required', Rule::in(['active', 'suspended'])], 'send_login' => ['required', 'boolean'],

            ];
        }

        return [...$rules, 'revision' => ['required', 'integer', 'min:1'], 'password' => ['prohibited'], 'status' => ['prohibited']];
    }
}
