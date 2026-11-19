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
            'settings' => ['sometimes', 'array:'.implode(',', MediaSettings::keys())],
            'settings.workflow' => ['sometimes', Rule::in(['movie', 'translate', 'subtitles'])],
            'settings.narration_lang' => ['sometimes', Rule::in(array_diff(MediaSettings::LANGUAGES, ['auto']))],
            'settings.subtitle_lang' => ['sometimes', Rule::in([...array_diff(MediaSettings::LANGUAGES, ['auto']), 'same'])],
            'settings.target_seconds' => ['sometimes', 'integer', 'between:30,3600'],
            'settings.narration_style' => ['sometimes', Rule::in(['standard', 'storyteller', 'suspense', 'punchy', 'emotional', 'funny', 'documentary', 'custom'])],
            'settings.story_order' => ['sometimes', Rule::in(['chronological', 'hook', 'character'])],
            'settings.spoilers' => ['sometimes', Rule::in(['include', 'hide'])],
            'settings.custom_style' => ['required_if:settings.narration_style,custom', 'nullable', 'string', 'max:1000'],
            'settings.character_focus' => ['required_if:settings.story_order,character', 'nullable', 'string', 'max:200'],
            'settings.instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'settings.name_dictionary' => ['sometimes', 'array', 'max:50'],
            'settings.name_dictionary.*' => ['array:original,translated,pronunciation'],
            'settings.name_dictionary.*.original' => ['required', 'string', 'max:100'],
            'settings.name_dictionary.*.translated' => ['required', 'string', 'max:100'],
            'settings.name_dictionary.*.pronunciation' => ['nullable', 'string', 'max:100'],
            'settings.voice_delivery' => ['sometimes', Rule::in(['natural', 'calm', 'storyteller', 'dramatic', 'energetic', 'serious', 'warm', 'custom'])],
            'settings.voice_pitch' => ['sometimes', 'numeric', 'between:-4,4'],
            'settings.subtitle_font' => ['sometimes', Rule::in(['Padauk', 'Arial', 'Tahoma'])],
            'settings.subtitle_size' => ['sometimes', 'numeric', 'between:0,96'],
            'settings.subtitle_bold' => ['sometimes', 'boolean'],
            'settings.subtitle_outline' => ['sometimes', 'numeric', 'between:0,8'],
            'settings.subtitle_shadow' => ['sometimes', 'numeric', 'between:0,8'],
            'settings.subtitle_background' => ['sometimes', 'boolean'],
            'settings.subtitle_position' => ['sometimes', Rule::in(['top', 'middle', 'bottom'])],
            'settings.subtitle_opacity' => ['sometimes', 'numeric', 'between:0,1'],
            'settings.quality' => ['sometimes', Rule::in(['source', '720p', '1080p'])],
            'settings.mode' => ['required_with:settings', Rule::in(['subs', 'dub'])],
            'settings.source_lang' => ['required_with:settings', Rule::in(MediaSettings::LANGUAGES)],
            'settings.output' => ['required_with:settings', Rule::in(['burn', 'srt', 'mp3', 'wav'])],
            'settings.review_before_render' => ['required_with:settings', 'boolean'],
            'settings.aspect' => ['required_with:settings', Rule::in(['original', '9:16', '16:9', '1:1'])],
            'settings.fill' => ['required_with:settings', Rule::in(['auto', 'blur', 'crop', 'pad'])],
            'settings.voice' => ['sometimes', Rule::in(config('media.voices'))],
            'settings.voice_style' => ['sometimes', 'nullable', 'string', 'max:300'],
            'settings.fit_script_to_speed' => ['sometimes', 'boolean'],
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
            if (($settings['workflow'] ?? '') === 'movie' && ($settings['mode'] ?? '') !== 'dub') {
                $validator->errors()->add('settings.mode', 'Narrated recap requires narration mode.');
            }
            if (($settings['workflow'] ?? '') === 'subtitles' && ($settings['mode'] ?? '') !== 'subs') {
                $validator->errors()->add('settings.mode', 'Subtitles mode keeps original audio.');
            }
            if ($this->route('mediaProject') && $validator->errors()->isEmpty()) {
                $project = MediaProject::whereKey($this->route('mediaProject'))->where('user_id', $this->user()->id)->first();
                if ($project) {
                    if (($settings['workflow'] ?? '') === 'movie' && (float) ($settings['target_seconds'] ?? 0) > (float) ($project->metadata['duration'] ?? 0)) {
                        $validator->errors()->add('settings.target_seconds', 'Target recap duration cannot exceed the source video. Choose a shorter custom duration or Translate an Existing Recap.');
                    }
                    foreach (app(UsageLedger::class)->featureErrors($project, $settings) as $field => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add($field, $message);
                        }
                    }
                }
            }
            if (($settings['mode'] ?? '') === 'dub' && ($settings['output'] ?? '') === 'srt') {
                $validator->errors()->add('settings.output', 'Narration requires MP4 or audio output.');
            }
            if (in_array($settings['output'] ?? '', ['mp3', 'wav'], true) && ($settings['mode'] ?? '') !== 'dub') {
                $validator->errors()->add('settings.output', 'Narration audio export requires narration mode.');
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
