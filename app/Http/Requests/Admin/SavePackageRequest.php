<?php

namespace App\Http\Requests\Admin;

use App\Models\Package;
use App\Models\PackageOperation;
use App\Services\PackageCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SavePackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('admin')?->is_admin;
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'code'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => $field === 'code' ? Str::upper(trim($this->input($field))) : trim($this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        $id = $this->isMethod('PATCH') ? $this->route('package') : null;
        $key = $this->input('request_id');
        if (! $id && is_string($key) && Str::isUuid($key)) {
            $op = PackageOperation::find($key);
            if ($op?->actor_id === $this->user('admin')?->id) {
                $id = $op->result['package']['id'] ?? null;
            }
        }
        $existing = $id ? Package::find($id) : null;
        $uploadSizes = app(PackageCatalog::class)->uploadSizeOptions();
        $savedSize = $existing?->definition['max_upload_mib'] ?? null;
        if (is_int($savedSize) && $savedSize > 0 && $savedSize <= app(PackageCatalog::class)->capabilities()['max_upload_mib']) {
            $uploadSizes[] = $savedSize;
        }
        $required = $this->input('status') === 'active' || ($this->isMethod('PATCH') && Package::whereKey($id)->value('published_version') > 0) ? 'required' : 'nullable';
        $rules = ['name' => ['required', 'string', 'max:100', Rule::unique('packages', 'name')->ignore($id)], 'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/D', Rule::unique('packages', 'code')->ignore($id)], 'type' => ['required', Rule::in(['trial', 'paid'])], 'status' => ['required', Rule::in(['draft', 'active', 'inactive'])], 'request_id' => ['required', 'uuid'], 'description' => ['nullable', 'string', 'max:200'], 'note' => ['nullable', 'string', 'max:500'], 'currency' => [$required, Rule::in(config('packages.currencies'))], 'price' => [$required, 'numeric', 'min:0', 'regex:/^\d{1,9}(\.\d{1,2})?$/D'], 'validity_days' => [$required, 'integer', 'min:1', 'max:3650'], 'included_minutes' => [$required, 'integer', 'min:1', 'max:1000000'], 'video_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'], 'storage_gib' => [$required, 'numeric', 'min:0.001', 'max:1048576'], 'max_video_seconds' => [$required, 'integer', 'min:1', 'max:86400'], 'max_upload_mib' => [$required, 'integer', 'min:1', 'max:1048576', Rule::in($uploadSizes)], 'concurrency' => [$required, 'integer', 'min:1', 'max:100'], 'queue_limit' => [$required, 'integer', 'min:1', 'max:1000'], 'retention_days' => [$required, 'integer', 'min:1', 'max:3650'], 'features' => [$required, 'array:'.implode(',', PackageCatalog::FEATURES)], 'source_id' => ['nullable', 'integer', Rule::exists('packages', 'id')], 'confirm_version' => ['sometimes', 'boolean'], 'reason' => ['nullable', 'string', 'max:500'], 'published_version' => ['prohibited'], 'revision' => $this->isMethod('PATCH') ? ['required', 'integer', 'min:1'] : ['prohibited']];
        foreach (PackageCatalog::FEATURES as $feature) {
            $rules['features.'.$feature] = ['sometimes', 'boolean'];
        }

        return $rules;
    }
}
