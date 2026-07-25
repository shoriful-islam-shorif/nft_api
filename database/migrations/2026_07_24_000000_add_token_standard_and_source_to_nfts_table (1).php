<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * token_standard and source already exist on the live database (used by
 * NftController::import()/show() and returned in every /api/nft/{mint}
 * response) but no migration in this repo ever created them — someone
 * added them directly on the DB at some point. Without this, running
 * `php artisan migrate` on a fresh environment (e.g. the live server)
 * produces a schema missing these two columns, and NftController::import()
 * fails immediately with "Unknown column 'token_standard'".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfts', function (Blueprint $table) {
            if (!Schema::hasColumn('nfts', 'token_standard')) {
                // mpl_core (this platform's own Studio mints) or
                // legacy_token_metadata (older/imported NFTs) — see
                // BuyController/purchase page, which branch on this to
                // pick the right transfer instruction.
                $table->string('token_standard')->default('mpl_core')->after('mint_address');
            }
            if (!Schema::hasColumn('nfts', 'source')) {
                // 'platform' (minted here) or 'imported' (brought in via
                // /api/nft/import from another marketplace/wallet).
                $table->string('source')->default('platform')->after('token_standard');
            }
        });
    }

    public function down(): void
    {
        Schema::table('nfts', function (Blueprint $table) {
            $table->dropColumn(['token_standard', 'source']);
        });
    }
};
