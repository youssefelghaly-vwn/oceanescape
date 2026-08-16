<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the first admin.
 *
 *   php artisan db:seed --class=AdminUserSeeder
 *
 * Credentials come from .env so no password is ever committed:
 *   ADMIN_EMAIL=you@example.com
 *   ADMIN_PASSWORD=a-long-random-string
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email    = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (!$email || !$password) {
            $this->command->error('Set ADMIN_EMAIL and ADMIN_PASSWORD in .env first.');
            return;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name'              => env('ADMIN_NAME', 'Site Admin'),
                'password'          => Hash::make($password),
                'is_admin'          => true,
                'email_verified_at' => now(),
            ]
        );

        $this->command->info("Admin ready: {$user->email}");
    }
}
