<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CreateOwner extends Command
{
    protected $signature = 'app:create-owner {email? : Owner email address}';

    protected $description = 'Create the first private workspace owner using a hidden password prompt';

    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->error('An account already exists. Use password recovery for the existing owner.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Owner name', 'Studio Owner'));
        $email = Str::lower(trim((string) ($this->argument('email') ?: $this->ask('Owner email'))));
        $password = $this->secret('Password (12+ characters, upper/lower case, number and symbol; max 72 bytes)');
        $confirmation = $this->secret('Confirm password');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation],
            ['name' => ['required', 'string', 'max:100'], 'email' => ['required', 'email:rfc', 'max:254'],
                'password' => ['required', 'string', 'confirmed', Password::defaults(),
                    function (string $attribute, mixed $value, \Closure $fail): void {
                        if (is_string($value) && strlen($value) > 72) {
                            $fail('The password must not exceed 72 bytes.');
                        }
                    }]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $owner = new User(['name' => $name, 'email' => $email, 'password' => $password, 'preferred_locale' => 'my']);
        $owner->forceFill(['is_admin' => true])->save();
        $this->info('Owner created. Sign in using your email address and chosen password.');

        return self::SUCCESS;
    }
}
