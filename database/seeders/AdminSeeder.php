<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Development admin seeding is only available in local/testing environments.');
        }

        if (! config('admin.seed.enabled')) {
            return;
        }

        $settings = Validator::make([
            'name' => config('admin.seed.name'),
            'email' => Str::lower(trim((string) config('admin.seed.email'))),
        ], [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:254'],
        ])->validate();

        DB::transaction(function () use ($settings): void {
            $admin = User::query()->firstOrNew(['email' => $settings['email']]);

            if (! $admin->exists) {
                $password = config('admin.seed.password');

                if (! is_string($password) || $password === '' || strlen($password) > 72) {
                    throw new RuntimeException('Set ADMIN_SEED_PASSWORD in the local environment before creating the admin.');
                }

                $admin->password = Hash::make($password);
                $admin->preferred_locale = 'my';
            }

            $admin->forceFill([
                'name' => $settings['name'],
                'is_admin' => true,
            ])->save();
        });
    }
}
