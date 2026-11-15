<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakeAdmin extends Command
{
    protected $signature = 'accounts:make-admin {email} {name?}';

    protected $description = 'Create a system administrator (or promote an existing user)';

    public function handle(): int
    {
        $email = $this->argument('email');
        $user = User::where('email', $email)->first();

        if (! $user) {
            $name = $this->argument('name') ?: $this->ask('Name');
            $password = (string) $this->secret('Password (at least 12 characters)');

            if (strlen($password) < 12) {
                $this->error('The password must be at least 12 characters.');

                return self::FAILURE;
            }

            $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
        }

        $user->forceFill(['is_admin' => true, 'is_active' => true])->save();

        $this->info("{$email} is now a system administrator. Sign in at /system");

        return self::SUCCESS;
    }
}