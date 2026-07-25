<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Total Supply / Edition fix
 * ──────────────────────────
 * Previously, creating an NFT with edition_type=limited + total_supply=N
 * only ever produced ONE database row (one on-chain token). total_supply
 * and minted_count were saved but never used to actually let N copies be
 * minted — so "Total Supply: 100 copies" never really created 100 copies.
 *
 * This adds an `edition_group_id` that ties together every copy that
 * belongs to the same edition (created together, sharing the same
 * artwork/metadata), and `edition_number` (1..N) so each copy can be
 * identified/displayed (e.g. "#7 of 100"). Supply is now enforced simply
 * by how many rows exist in a group — no separate counter to keep in
 * sync, and no race condition since each row is its own mint slot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfts', function (Blueprint $table) {
            $table->string('edition_group_id')->nullable()->after('total_supply')->index();
            $table->unsignedInteger('edition_number')->nullable()->after('edition_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('nfts', function (Blueprint $table) {
            $table->dropColumn(['edition_group_id', 'edition_number']);
        });
    }
};
