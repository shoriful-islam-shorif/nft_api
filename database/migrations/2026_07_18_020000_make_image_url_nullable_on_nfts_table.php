<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Imported NFTs may genuinely have no resolvable image (broken URI,
// missing "image" field in their off-chain metadata JSON) — the
// frontend already shows a placeholder box when image_url is empty,
// so this shouldn't block the import itself.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('nfts', function (Blueprint $table) {
            $table->string('image_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('nfts', function (Blueprint $table) {
            $table->string('image_url')->nullable(false)->change();
        });
    }
};
