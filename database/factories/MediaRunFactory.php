<?php

namespace Database\Factories;

use App\Models\MediaProject;
use App\Support\MediaSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

class MediaRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'media_project_id' => MediaProject::factory(), 'phase' => 'analysis',
            'status' => 'queued', 'stage' => 'preparing', 'settings' => MediaSettings::DEFAULTS,
            'lines' => [], 'stages' => [], 'artifacts' => [],
        ];
    }
}
