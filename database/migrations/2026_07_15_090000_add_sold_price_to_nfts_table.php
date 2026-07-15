<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// NOTE: CollectionController already queries ->sum('sold_price') for
// collection volume/floor stats, but no migration ever created this
// column — every call to GET /api/collections or /api/collections/{id}
// was throwing a "column not found" SQL error. list_price also gets
// nulled out by BuyController::confirm() the moment an NFT sells, so
// the sale price needs its own permanent column captured at sale time.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('nfts', function (Blueprint $table) {
            $table->decimal('sold_price', 18, 9)->nullable()->after('sold_tx');
        });
    }

    public function down(): void
    {
        Schema::table('nfts', function (Blueprint $table) {
            $table->dropColumn('sold_price');
        });
    }
};
