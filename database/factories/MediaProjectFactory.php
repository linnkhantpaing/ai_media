<?php

namespace Database\Factories;

use App\Models\User;
use App\Support\MediaSettings;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MediaProjectFactory extends Factory
{
    public function definition(): array
    {
        $id = (string) Str::uuid();

        return [
            'id' => $id,
            'user_id' => User::factory(), 'client_id' => (string) Str::uuid(),
            'title' => 'Example recap', 'source_name' => 'example.mp4', 'source_extension' => 'mp4',
            'source_mime' => 'video/mp4', 'source_path' => 'media/'.$id.'/source.mp4',
            'expected_bytes' => 1024, 'uploaded_bytes' => 1024,
            'status' => 'ready', 'metadata' => ['duration' => 4.0, 'width' => 320, 'height' => 240, 'aspect' => '4:3', 'audio' => true],
            'settings' => MediaSettings::DEFAULTS, 'lines' => [],
        ];
    }
}
