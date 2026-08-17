<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        // Reads from .env instead of a hardcoded literal — keeps the real
        // admin password out of source control. Set ADMIN_EMAIL and
        // ADMIN_PASSWORD in your production .env before seeding; if unset,
        // falls back to the old dev defaults (fine for local dev only).
        $email    = env('ADMIN_EMAIL', 'admin@bfin.it');
        $password = env('ADMIN_PASSWORD', 'password');

        User::updateOrCreate(
            ['email' => $email],
            [
                'name'     => 'Admin',
                'password' => Hash::make($password),
                'is_admin' => true,
            ]
        );

        $this->command->info('✅ Admin user created!');
        $this->command->info("Email: {$email}");
        if (!env('ADMIN_PASSWORD')) {
            $this->command->warn('⚠️  ADMIN_PASSWORD not set in .env — used the dev default. Set it and re-seed before going live!');
        }
        $this->command->warn('⚠️  Change password after first login!');
    }
}
