<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveRecapScenesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'revision' => ['required', 'string', 'size:64'], 'lines' => ['required', 'array', 'min:1', 'max:6000'],
            'lines.*' => ['array:scene_id,index,start,end,source_text,text,subtitle_text,planned_duration,included,locked,note,audio_muted,subtitle_hidden'],
            'lines.*.scene_id' => ['sometimes', 'required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_-]+$/', 'distinct'],
            'lines.*.index' => ['required', 'integer', 'min:0', 'distinct'],
            'lines.*.start' => ['required', 'numeric', 'min:0'], 'lines.*.end' => ['required', 'numeric', 'gt:0'],
            'lines.*.text' => ['required', 'string', 'max:1500'], 'lines.*.source_text' => ['required', 'string', 'max:1500'],
            'lines.*.subtitle_text' => ['sometimes', 'required', 'string', 'max:1500'],
            'lines.*.planned_duration' => ['sometimes', 'numeric', 'gt:0', 'max:180'],
            'lines.*.included' => ['required', 'boolean'], 'lines.*.locked' => ['required', 'boolean'],
            'lines.*.audio_muted' => ['sometimes', 'boolean'],
            'lines.*.subtitle_hidden' => ['sometimes', 'boolean'],
            'lines.*.note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
