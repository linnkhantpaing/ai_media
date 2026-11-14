<?php

namespace Database\Factories;

use App\Models\MediaTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MediaTaskActionFactory extends Factory
{
    public function definition(): array
    {
        return ['media_task_id' => MediaTask::factory(), 'actor_id' => User::factory()->state(['is_admin' => true]),
            'request_id' => (string) Str::uuid(), 'action' => 'cancel', 'fingerprint' => hash('sha256', Str::uuid()),
            'reason' => 'Administrator requested cancellation', 'result' => []];
    }
}
