<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class UsageRecordFactory extends Factory
{
    public function definition(): array
    {
        return ['operation_key' => 'test:'.Str::uuid(), 'user_id' => User::factory(), 'kind' => 'processing', 'status' => 'charged', 'customer_snapshot' => ['name' => 'Example customer', 'email' => 'customer@example.test'], 'project_snapshot' => ['title' => 'Example recap'], 'reserved_seconds' => 0, 'charged_seconds' => 60, 'charged_videos' => 1];
    }
}
