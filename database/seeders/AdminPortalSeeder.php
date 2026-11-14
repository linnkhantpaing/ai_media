<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

class AdminPortalSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Admin portal development seeding is only available in local/testing environments.');
        }

        $settings = Validator::make([
            'name' => config('admin.portal_seed.name'),
            'email' => Str::lower(trim((string) config('admin.portal_seed.email'))),
        ], [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:254'],
        ])->validate();

        DB::transaction(function () use ($settings): void {
            $existing = User::query()->where('email', $settings['email'])->lockForUpdate()->first();
            if ($existing) {
                if (! $existing->is_admin) {
                    throw new RuntimeException('This email belongs to a user account. No account permissions were changed.');
                }

                return;
            }

            $password = config('admin.portal_seed.password');
            if (! is_string($password) || $password === '' || strlen($password) > 72) {
                throw new RuntimeException('Set ADMIN_PORTAL_SEED_PASSWORD before seeding the admin portal account.');
            }

            $admin = new User;
            $admin->forceFill([
                'name' => $settings['name'],
                'email' => $settings['email'],
                'password' => Hash::make($password),
                'is_admin' => true,
                'preferred_locale' => 'en',
                'email_verified_at' => now(),
            ])->save();
        });
    }
}
