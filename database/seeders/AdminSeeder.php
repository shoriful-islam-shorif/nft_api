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
            ['email' => 'admin@nft.bfin.technology'],
            [
                'name'     => 'NFT Admin',
                'password' => Hash::make('Admin@2024!'),
                'is_admin' => true,
            ]
        );

        $this->command->info('✅ Admin user created!');
        $this->command->info('Email: admin@nft.bfin.technology');
        $this->command->info('Password: Admin@2024!');
        $this->command->warn('⚠️  Change password after first login!');
    }
}
