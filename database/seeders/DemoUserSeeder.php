<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Creates one demo user per role for local development. Every account uses the
 * password "password", so this seeder refuses to run outside local and testing.
 */
class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('DemoUserSeeder skipped: demo accounts only exist in local and testing.');

            return;
        }

        $demoUsers = [
            ['Demo Admin', 'admin@example.com', UserRole::Admin],
            ['Demo Organizer', 'organizer@example.com', UserRole::Organizer],
            ['Demo Attendee', 'attendee@example.com', UserRole::Attendee],
        ];

        foreach ($demoUsers as [$name, $email, $role]) {
            $user = User::firstOrNew(['email' => $email]);
            $user->name = $name;
            $user->password = 'password';
            $user->role = $role;
            $user->email_verified_at = now();
            $user->save();
        }
    }
}
