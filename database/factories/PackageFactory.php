<?php

namespace Database\Factories;

use App\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Package> */
class PackageFactory extends Factory
{
    public function trial(): static
    {
        return $this->state(fn (array $attributes): array => ['type' => 'trial', 'definition' => [...$attributes['definition'], 'type' => 'trial', 'price' => '0', 'price_minor' => 0]]);
    }

    public function definition(): array
    {
        return ['name' => fake()->unique()->words(3, true), 'code' => 'PKG-'.strtoupper(fake()->unique()->bothify('????####')), 'type' => 'paid', 'status' => 'draft', 'definition' => ['currency' => 'USD', 'price' => '9.99', 'price_minor' => 999, 'validity_days' => 30, 'included_minutes' => 60, 'included_seconds' => 3600, 'video_limit' => 10, 'storage_gib' => 1, 'storage_bytes' => 1073741824, 'max_video_seconds' => 300, 'max_upload_mib' => 100, 'max_upload_bytes' => 104857600, 'concurrency' => 1, 'queue_limit' => 3, 'retention_days' => 7, 'description' => '', 'note' => '', 'features' => ['local' => true, 'youtube' => true, 'subs' => true, 'dub' => true, 'mp4' => true, 'srt' => true, 'audio' => false, 'script_edit' => true, 'voice_preview' => true, 'video_preview' => true, 'subtitle_style' => true, 'cover_subtitles' => true, 'layout' => true, 'keep_background' => false]]];
    }
}
