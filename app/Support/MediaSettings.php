<?php

namespace App\Support;

use App\Models\User;
use App\Services\Media\ProjectDefaults;
use App\Services\StudioPreferences;

final class MediaSettings
{
    public const LANGUAGES = ['auto', 'zh', 'en', 'hi', 'ko', 'ja', 'th', 'es', 'id', 'vi', 'my'];

    public const DEFAULTS = [
        'mode' => 'subs', 'source_lang' => 'auto', 'output' => 'burn',
        'burn_subtitles' => true, 'audio_track' => 'mix', 'duck_background' => true,
        'review_before_render' => true, 'aspect' => 'original', 'fill' => 'auto',
        'voice' => 'Kore', 'voice_style' => '', 'speech_rate' => 1.0,
        'voice_volume' => 1.0, 'keep_background' => false, 'background_volume' => 0.35,
        'cover_mode' => 'off', 'cover_style' => 'smart_blur',
        'cover_top' => 0.78, 'cover_height' => 0.16,
        'subtitle_color' => '#FFFFFF', 'subtitle_lift' => 0.08, 'max_video_speed' => 1.35,
    ];

    public const STUDIO_DEFAULTS = [
        'workflow' => 'translate', 'narration_lang' => 'my', 'subtitle_lang' => 'same',
        'target_seconds' => 600, 'narration_style' => 'standard', 'story_order' => 'chronological',
        'spoilers' => 'include', 'custom_style' => '', 'character_focus' => '', 'instructions' => '',
        'name_dictionary' => [], 'voice_delivery' => 'natural', 'voice_pitch' => 0,
        'subtitle_font' => 'Padauk', 'subtitle_size' => 0, 'subtitle_bold' => false,
        'subtitle_outline' => 2, 'subtitle_shadow' => 1, 'subtitle_background' => false,
        'subtitle_position' => 'bottom', 'subtitle_opacity' => 0.5, 'quality' => 'source', 'fit_script_to_speed' => false,
    ];

    public static function keys(): array
    {
        return array_keys([...self::DEFAULTS, ...self::STUDIO_DEFAULTS]);
    }

    public static function defaults(?int $userId = null): array
    {
        $settings = app(ProjectDefaults::class)->resolved();
        if ($userId !== null) {
            $user = User::findOrFail($userId);
            $settings = self::normalize(array_replace($settings, app(StudioPreferences::class)->overrides($user)));
            $settings['name_dictionary'] = $user->recap_name_dictionary ?? [];
        }

        return $settings;
    }

    public static function normalize(array $settings): array
    {
        $result = array_replace([...self::DEFAULTS, ...self::STUDIO_DEFAULTS], array_intersect_key($settings, [...self::DEFAULTS, ...self::STUDIO_DEFAULTS]));
        foreach (['review_before_render', 'keep_background', 'burn_subtitles', 'duck_background', 'subtitle_bold', 'subtitle_background', 'fit_script_to_speed'] as $key) {
            $result[$key] = filter_var($result[$key], FILTER_VALIDATE_BOOL);
        }
        foreach (['speech_rate', 'voice_volume', 'background_volume', 'cover_top', 'cover_height', 'subtitle_lift', 'max_video_speed', 'voice_pitch', 'subtitle_size', 'subtitle_outline', 'subtitle_shadow', 'subtitle_opacity'] as $key) {
            $result[$key] = (float) $result[$key];
        }
        if (in_array($result['output'], ['mp3', 'wav'], true) && $result['audio_track'] === 'narration') {
            $result['keep_background'] = false;
        }
        $result['target_seconds'] = (int) $result['target_seconds'];
        foreach (['custom_style', 'character_focus', 'instructions'] as $key) {
            $result[$key] = (string) ($result[$key] ?? '');
        }
        $result['voice_style'] = (string) ($result['voice_style'] ?? '');
        $result['subtitle_color'] = strtoupper($result['subtitle_color']);

        return $result;
    }
}
