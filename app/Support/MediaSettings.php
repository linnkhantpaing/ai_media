<?php

namespace App\Support;

use App\Services\Media\ProjectDefaults;

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

    public static function defaults(): array
    {
        return app(ProjectDefaults::class)->resolved();
    }

    public static function normalize(array $settings): array
    {
        $result = array_replace(self::DEFAULTS, array_intersect_key($settings, self::DEFAULTS));
        foreach (['review_before_render', 'keep_background', 'burn_subtitles', 'duck_background'] as $key) {
            $result[$key] = filter_var($result[$key], FILTER_VALIDATE_BOOL);
        }
        foreach (['speech_rate', 'voice_volume', 'background_volume', 'cover_top', 'cover_height', 'subtitle_lift', 'max_video_speed'] as $key) {
            $result[$key] = (float) $result[$key];
        }
        if (in_array($result['output'], ['mp3', 'wav'], true) && $result['audio_track'] === 'narration') {
            $result['keep_background'] = false;
        }
        $result['voice_style'] = (string) ($result['voice_style'] ?? '');
        $result['subtitle_color'] = strtoupper($result['subtitle_color']);

        return $result;
    }
}
