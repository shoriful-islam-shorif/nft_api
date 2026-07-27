<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfts', function (Blueprint $table) {
            // `sold_price` was always being stored without recording WHICH
            // currency it's denominated in (spump or usdc) — meaning admin
            // stats had no way to correctly separate/sum volume per
            // currency, and any attempt to blend spump+usdc sums together
            // as one number is meaningless (they're different tokens).
            $table->string('sold_currency', 10)->nullable()->after('sold_price');
        });
    }

    public function down(): void
    {
        Schema::table('nfts', function (Blueprint $table) {
            $table->dropColumn('sold_currency');
        });
    }
};