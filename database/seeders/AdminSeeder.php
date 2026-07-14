<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@bfin.it'],
            [
                'name'     => 'Admin',
                'password' => Hash::make('Bfin@2024!'),
                'is_admin' => true,
            ]
        );

        $this->command->info('✅ Admin user created!');
        $this->command->info('Email: admin@bfin.it');
        $this->command->info('Password: Bfin@2024!');
        $this->command->warn('⚠️  Change password after first login!');
    }
}
