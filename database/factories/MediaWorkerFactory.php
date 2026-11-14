<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class MediaWorkerFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => 'Media Worker', 'queues' => ['media-inspect', 'media'], 'capacity' => 1, 'heartbeat_at' => now()];
    }
}
