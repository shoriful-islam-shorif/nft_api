<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('nfts', function (Blueprint $table) {
            $table->string('sold_to')->nullable()->after('listed_at');
            $table->timestamp('sold_at')->nullable()->after('sold_to');
            $table->string('sold_tx')->nullable()->after('sold_at');
            $table->string('previous_owner')->nullable()->after('sold_tx');
        });
    }

    public function down(): void
    {
        Schema::table('nfts', function (Blueprint $table) {
            $table->dropColumn(['sold_to', 'sold_at', 'sold_tx', 'previous_owner']);
        });
    }
};
