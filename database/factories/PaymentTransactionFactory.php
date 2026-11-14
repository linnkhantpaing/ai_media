<?php

namespace Database\Factories;

use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentTransaction> */
class PaymentTransactionFactory extends Factory
{
    public function definition(): array
    {
        $package = Package::factory()->create(['status' => 'active', 'published_version' => 1]);
        $version = PackageVersion::create(['package_id' => $package->id, 'number' => 1, 'definition' => $package->definition]);
        $method = PaymentMethod::factory()->create(['currency' => 'USD']);

        return ['user_id' => User::factory()->state(['is_admin' => false]), 'package_version_id' => $version->id, 'payment_method_id' => $method->id,
            'package_snapshot' => ['id' => $package->id, 'name' => $package->name, 'code' => $package->code, 'type' => 'paid', 'version' => 1, 'definition' => $version->definition, 'validity_mode' => 'package'],
            'receiving_snapshot' => ['receiving' => ['name' => $method->name, 'provider' => $method->provider, 'type' => 'bank', 'details' => $method->details, 'instructions' => $method->instructions, 'qr_path' => null], 'rules' => ['evidence_required' => false, 'reference_required' => false, 'sender_required' => false, 'transfer_date_required' => false, 'expiry_hours' => 24, 'instructions' => ['en' => '', 'my' => '', 'th' => '']]],
            'currency' => 'USD', 'expected_minor' => 999, 'received_minor' => 999, 'purpose' => 'new', 'status' => 'pending_review', 'revision' => 1, 'transfer' => ['reference' => fake()->uuid(), 'sender' => fake()->name(), 'transfer_date' => now()->subMinute()->toIso8601String()], 'expires_at' => now()->addDay(), 'submitted_at' => now()];
    }
}
