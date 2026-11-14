<?php

namespace App\Http\Requests;

use App\Models\MediaProject;
use App\Services\Media\MediaBackground;
use App\Services\UsageLedger;
use App\Support\MediaSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMediaSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('title'))) {
            $this->merge(['title' => trim($this->input('title'))]);
        }
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:250'],
            'confirm_reanalysis' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array:'.implode(',', array_keys(MediaSettings::DEFAULTS))],
            'settings.mode' => ['required_with:settings', Rule::in(['subs', 'dub'])],
            'settings.source_lang' => ['required_with:settings', Rule::in(MediaSettings::LANGUAGES)],
            'settings.output' => ['required_with:settings', Rule::in(['burn', 'srt', 'mp3', 'wav'])],
            'settings.review_before_render' => ['required_with:settings', 'boolean'],
            'settings.aspect' => ['required_with:settings', Rule::in(['original', '9:16', '16:9', '1:1'])],
            'settings.fill' => ['required_with:settings', Rule::in(['auto', 'blur', 'crop', 'pad'])],
            'settings.voice' => ['sometimes', Rule::in(config('media.voices'))],
            'settings.voice_style' => ['sometimes', 'nullable', 'string', 'max:300'],
            'settings.speech_rate' => ['sometimes', 'numeric', 'between:0.5,2'],
            'settings.voice_volume' => ['sometimes', 'numeric', 'between:0,2'],
            'settings.keep_background' => ['sometimes', 'boolean'],
            'settings.burn_subtitles' => ['sometimes', 'boolean'],
            'settings.duck_background' => ['sometimes', 'boolean'],
            'settings.audio_track' => ['sometimes', Rule::in(['mix', 'narration'])],
            'settings.background_volume' => ['sometimes', 'numeric', 'between:0,1.5'],
            'settings.cover_mode' => ['sometimes', Rule::in(['off', 'manual'])],
            'settings.cover_style' => ['sometimes', Rule::in(['smart_blur', 'dark_box', 'blur_strip'])],
            'settings.cover_top' => ['sometimes', 'numeric', 'between:0,1'],
            'settings.cover_height' => ['sometimes', 'numeric', 'gt:0', 'max:1'],
            'settings.subtitle_color' => ['sometimes', 'regex:/^#[a-fA-F0-9]{6}$/'],
            'settings.subtitle_lift' => ['sometimes', 'numeric', 'between:0,0.5'],
            'settings.max_video_speed' => ['sometimes', 'numeric', 'between:1,2'],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            $settings = $this->input('settings');
            if (! is_array($settings)) {
                return;
            }
            if ($this->route('mediaProject') && $validator->errors()->isEmpty()) {
                $project = MediaProject::whereKey($this->route('mediaProject'))->where('user_id', $this->user()->id)->first();
                if ($project) {
                    foreach (app(UsageLedger::class)->featureErrors($project, $settings) as $field => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add($field, $message);
                        }
                    }
                }
            }
            if (($settings['mode'] ?? '') === 'dub' && ($settings['output'] ?? '') === 'srt') {
                $validator->errors()->add('settings.output', 'Burmese dubbing requires MP4 + SRT output.');
            }
            if (in_array($settings['output'] ?? '', ['mp3', 'wav'], true) && ($settings['mode'] ?? '') !== 'dub') {
                $validator->errors()->add('settings.output', 'Narration audio export requires Burmese dub mode.');
            }
            if (($settings['cover_mode'] ?? '') === 'manual' && ((float) ($settings['cover_top'] ?? 0) + (float) ($settings['cover_height'] ?? 0)) > 1) {
                $validator->errors()->add('settings.cover_height', 'The cover area must stay inside the video frame.');
            }
            if (($settings['mode'] ?? '') === 'dub' && ($settings['keep_background'] ?? false) && ! (in_array($settings['output'] ?? '', ['mp3', 'wav'], true) && ($settings['audio_track'] ?? 'mix') === 'narration') && ! app(MediaBackground::class)->available()) {
                $validator->errors()->add('settings.keep_background', 'Background audio separation is not available in this workspace.');
            }
        }];
    }
}
