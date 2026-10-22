<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Creates (or resets) the admin account from .env values, so no credentials live in the repo.
 *
 * .env:
 *   ADMIN_NAME="Admin"
 *   ADMIN_EMAIL=admin@example.com
 *   ADMIN_PASSWORD=choose-a-strong-password
 *
 * Run: php artisan db:seed --class=AdminUserSeeder
 * Running it again resets the admin's password to the .env value.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (! $email || ! $password) {
            $this->command->error('Set ADMIN_EMAIL and ADMIN_PASSWORD in .env first.');

            return;
        }

        $admin = User::query()->firstOrNew(['email' => Str::lower($email)]);

        $admin->forceFill([
            'name' => env('ADMIN_NAME', 'Admin'),
            'password' => $password,
            'role' => UserRole::Admin,
            'email_verified_at' => now(),
        ])->save();

        $this->command->info("Admin account ready: {$admin->email}");
    }
}
