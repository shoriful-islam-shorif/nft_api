<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// image_hash / metadata_hash are used to verify IPFS pinning integrity
// for NFTs minted through this platform's own Studio — they have no
// real meaning for imported NFTs (minted elsewhere, we don't control
// or re-pin their files). Both were NOT NULL with no default, which
// made NftController::import() fail with a SQL error since it never
// sets them.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('nfts', function (Blueprint $table) {
            $table->string('image_hash')->nullable()->change();
            $table->string('metadata_hash')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('nfts', function (Blueprint $table) {
            $table->string('image_hash')->nullable(false)->change();
            $table->string('metadata_hash')->nullable(false)->change();
        });
    }
};
