<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Platform fee is now a flat SOL amount instead of a percentage
        // of the sale price, so admin revenue can no longer be derived
        // as "volume * fee%" (see AdminController::revenueByCurrency()).
        // Store what was ACTUALLY charged, per sale, in the currency it
        // was charged in — same "historical record of the deal" pattern
        // already used for storage_fee_spump / list_currency.
        Schema::table('nfts', function (Blueprint $table) {
            if (!Schema::hasColumn('nfts', 'platform_fee_charged')) {
                $table->decimal('platform_fee_charged', 18, 9)->nullable()->after('sold_currency');
            }
        });

        // ── platform_settings: move mint_price/platform fee/storage
        // fee onto their new SOL/USD base. Old rows (percent-based
        // platform fee, SPUMP-per-MB storage fee) are removed since
        // their unit no longer applies; mint_price keeps its key but
        // its VALUE and LABEL are reset since the unit changed from
        // SPUMP to SOL.
        DB::table('platform_settings')->where('key', 'platform_fee_percent')->delete();
        DB::table('platform_settings')->where('key', 'storage_fee_per_mb_spump')->delete();

        $now = now();

        DB::table('platform_settings')->updateOrInsert(
            ['key' => 'mint_price'],
            ['value' => '0', 'type' => 'number', 'label' => 'Mint Price (SOL)', 'updated_at' => $now, 'created_at' => $now]
        );
        DB::table('platform_settings')->updateOrInsert(
            ['key' => 'platform_fee_amount_sol'],
            ['value' => '0', 'type' => 'number', 'label' => 'Platform Fee (SOL, flat amount per sale)', 'updated_at' => $now, 'created_at' => $now]
        );
        DB::table('platform_settings')->updateOrInsert(
            ['key' => 'storage_fee_per_mb_usd'],
            ['value' => '0', 'type' => 'number', 'label' => 'Storage Fee — USD per MB per year', 'updated_at' => $now, 'created_at' => $now]
        );
    }

    public function down(): void
    {
        Schema::table('nfts', function (Blueprint $table) {
            if (Schema::hasColumn('nfts', 'platform_fee_charged')) {
                $table->dropColumn('platform_fee_charged');
            }
        });

        DB::table('platform_settings')->where('key', 'platform_fee_amount_sol')->delete();
        DB::table('platform_settings')->where('key', 'storage_fee_per_mb_usd')->delete();

        $now = now();
        DB::table('platform_settings')->updateOrInsert(
            ['key' => 'mint_price'],
            ['value' => '10', 'type' => 'number', 'label' => 'Mint Price (SPUMP)', 'updated_at' => $now, 'created_at' => $now]
        );
        DB::table('platform_settings')->updateOrInsert(
            ['key' => 'platform_fee_percent'],
            ['value' => '3', 'type' => 'number', 'label' => 'Platform Fee (%)', 'updated_at' => $now, 'created_at' => $now]
        );
        DB::table('platform_settings')->updateOrInsert(
            ['key' => 'storage_fee_per_mb_spump'],
            ['value' => '10', 'type' => 'number', 'label' => 'Storage Fee — SPUMP per MB per year', 'updated_at' => $now, 'created_at' => $now]
        );
    }
};
