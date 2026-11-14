<?php

namespace App\Http\Requests;

use App\Services\Media\ProjectDefaults;
use App\Support\MediaSettings;

class SaveProjectDefaultsRequest extends UpdateMediaSettingsRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('admin')?->can('manage-system');
    }

    protected function prepareForValidation(): void
    {
        if ($this->boolean('inherit_voice') && is_array($this->input('settings'))) {
            $this->merge(['settings' => array_replace($this->input('settings'), app(ProjectDefaults::class)->voiceDefaults())]);
        }
    }

    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['title'], $rules['confirm_reanalysis']);
        $rules['revision'] = ['required', 'integer', 'min:0'];
        $rules['inherit_voice'] = ['required', 'boolean'];
        $rules['settings'] = ['required', 'array:'.implode(',', array_keys(MediaSettings::DEFAULTS))];
        foreach (array_keys(MediaSettings::DEFAULTS) as $key) {
            $rules['settings.'.$key] = array_merge([$key === 'voice_style' ? 'present' : 'required'], array_values(array_filter(
                $rules['settings.'.$key], fn ($rule): bool => ! in_array($rule, ['sometimes', 'required_with:settings'], true)
            )));
        }

        return $rules;
    }
}
