<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfts', function (Blueprint $table) {
            if (!Schema::hasColumn('nfts', 'image_size_bytes')) {
                $table->unsignedBigInteger('image_size_bytes')->nullable()->after('image_hash');
            }
            // Cached at mint time (and refreshed on renewal) so a later
            // admin rate change doesn't retroactively change what THIS
            // NFT owes — same reasoning as list_currency never getting
            // cleared: it's a historical record of the deal that was
            // actually paid for.
            if (!Schema::hasColumn('nfts', 'storage_fee_spump')) {
                $table->decimal('storage_fee_spump', 18, 9)->nullable()->after('image_size_bytes');
            }
            // Storage is considered active while now() < storage_paid_until.
            // Null means never paid (shouldn't happen post-mint, since
            // mint payment always includes the first year — but kept
            // nullable for pre-existing rows created before this feature).
            if (!Schema::hasColumn('nfts', 'storage_paid_until')) {
                $table->timestamp('storage_paid_until')->nullable()->after('storage_fee_spump');
            }
        });
    }

    public function down(): void
    {
        Schema::table('nfts', function (Blueprint $table) {
            $table->dropColumn(['image_size_bytes', 'storage_fee_spump', 'storage_paid_until']);
        });
    }
};