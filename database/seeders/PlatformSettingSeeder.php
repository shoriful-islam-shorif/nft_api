<?php

namespace Database\Seeders;

use App\Models\PlatformSetting;
use Illuminate\Database\Seeder;

class PlatformSettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            ['key' => 'mint_price', 'value' => '10', 'type' => 'number', 'label' => 'Mint Price (SPUMP)'],
            ['key' => 'storage_fee_per_mb_spump', 'value' => '10', 'type' => 'number', 'label' => 'Storage Fee — SPUMP per MB per year'],
            ['key' => 'storage_grace_period_days', 'value' => '14', 'type' => 'number', 'label' => 'Storage Grace Period (days after expiry before hiding from marketplace)'],
            ['key' => 'platform_fee_percent',  'value' => '3',     'type' => 'number',  'label' => 'Platform Fee (%)'],
            ['key' => 'is_free_listing',        'value' => 'true',  'type' => 'boolean', 'label' => 'Free Listing'],
            ['key' => 'mint_discount_percent',  'value' => '15',    'type' => 'number',  'label' => 'Mint Discount (%)'],
            ['key' => 'buyer_discount_percent', 'value' => '10',    'type' => 'number',  'label' => 'Buyer Discount (%)'],
            // ['key' => 'platform_wallet',        'value' => '',      'type' => 'string',  'label' => 'Platform Wallet Address'],
            ['key' => 'platform_name',          'value' => 'Scotty Pumpkin NFT', 'type' => 'string', 'label' => 'Platform Name'],
            ['key' => 'max_file_size_mb',       'value' => '7',    'type' => 'number',  'label' => 'Max Upload Size (MB)'],
            // ['key' => 'nft_require_approval',   'value' => 'false', 'type' => 'boolean', 'label' => 'NFT Requires Admin Approval'],
        ];

        foreach ($settings as $s) {
            PlatformSetting::updateOrCreate(['key' => $s['key']], $s);
        }

        $this->command->info('✅ Platform settings seeded!');
    }
}
