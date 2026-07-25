<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Safe/idempotent version — only adds the column if it doesn't already
// exist. The earlier migration that was supposed to add this column
// apparently never ran on this database, which is why a later
// migration failed with "Unknown column 'list_currency'".
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('nfts', 'list_currency')) {
            Schema::table('nfts', function (Blueprint $table) {
                $table->string('list_currency', 10)->default('spump')->after('list_price');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('nfts', 'list_currency')) {
            Schema::table('nfts', function (Blueprint $table) {
                $table->dropColumn('list_currency');
            });
        }
    }
};
