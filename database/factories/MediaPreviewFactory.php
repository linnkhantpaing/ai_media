<?php

namespace Database\Factories;

use App\Models\MediaProject;
use App\Support\MediaSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

class MediaPreviewFactory extends Factory
{
    public function definition(): array
    {
        return ['media_project_id' => MediaProject::factory(), 'kind' => 'voice', 'status' => 'queued', 'cache_key' => hash('sha256', fake()->uuid()), 'settings' => MediaSettings::DEFAULTS, 'lines' => [], 'options' => []];
    }
}
