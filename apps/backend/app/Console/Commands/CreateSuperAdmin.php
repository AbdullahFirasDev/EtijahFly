<?php

namespace App\Console\Commands;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CreateSuperAdmin extends Command
{
    protected $signature = 'platform:create-super-admin {email} {--name=Super Admin}';

    protected $description = 'Create the first platform super admin (prints a one-time random password)';

    public function handle(): int
    {
        $email = mb_strtolower((string) $this->argument('email'));
        $validator = Validator::make(['email' => $email], ['email' => ['required', 'email', 'unique:users,email']]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        do {
            $password = Str::password(20);
        } while (Validator::make(['p' => $password], ['p' => Password::defaults()])->fails());

        $user = User::query()->withoutGlobalScopes()->create([
            'tenant_id' => null,
            'is_platform' => true,
            'name' => (string) $this->option('name'),
            'email' => $email,
            'password' => $password,
            'password_changed_at' => now(),
            'email_verified_at' => now(),
            'status' => UserStatus::Active,
        ]);
        $user->assignRole('super_admin');

        $this->info("Super admin {$email} created.");
        $this->warn("One-time password (change it after first login): {$password}");

        return self::SUCCESS;
    }
}
