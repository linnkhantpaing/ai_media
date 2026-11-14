<?php

namespace Database\Factories;

use App\Models\MediaProject;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MediaTaskFactory extends Factory
{
    public function definition(): array
    {
        return ['source_key' => 'test:'.Str::uuid(), 'media_project_id' => MediaProject::factory(),
            'type' => 'analysis', 'mode' => 'subs', 'status' => 'queued', 'stage' => 'prepare',
            'progress' => 0, 'stages' => [], 'queued_at' => now(), 'ready_at' => now(), 'history_available' => true];
    }
}
