<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;

class MakeAdmin extends Command
{
    protected $signature = 'users:make-admin {email : Email of an existing user}';

    protected $description = 'Promote an existing user to the admin role';

    public function handle(): int
    {
        $email = mb_strtolower(trim($this->argument('email')));
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error("No user found with email {$email}. Register the account first.");

            return self::FAILURE;
        }

        $user->role = UserRole::Admin;
        $user->save();

        $this->info("{$user->email} is now an admin.");

        return self::SUCCESS;
    }
}
