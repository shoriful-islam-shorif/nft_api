<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('nfts', function (Blueprint $table) {
            $table->boolean('is_listed')->default(false)->after('status');
            $table->decimal('list_price', 18, 9)->nullable()->after('is_listed');
            $table->timestamp('listed_at')->nullable()->after('list_price');
        });
    }

    public function down(): void
    {
        Schema::table('nfts', function (Blueprint $table) {
            $table->dropColumn(['is_listed', 'list_price', 'listed_at']);
        });
    }
};
