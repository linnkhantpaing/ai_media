<?php

namespace Database\Factories;

use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentMethod> */
class PaymentMethodFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->company(), 'type' => 'bank', 'currency' => 'MMK', 'provider' => 'Example Bank', 'status' => 'inactive', 'details' => ['holder' => fake()->name(), 'identifier' => '00123456789', 'branch' => '', 'routing' => ''], 'instructions' => ['my' => '', 'en' => 'Transfer the exact amount and keep the receipt.', 'th' => '']];
    }
}
