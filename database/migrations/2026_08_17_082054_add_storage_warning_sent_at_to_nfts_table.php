<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfts', function (Blueprint $table) {
            if (!Schema::hasColumn('nfts', 'storage_warning_sent_at')) {
                // Set when the "storage expires in 30 days" warning is
                // sent, so storage:send-warnings doesn't re-send it
                // every time it runs. Cleared back to null on renewal
                // (see NftController::renewStorage) so a future expiry
                // warns again.
                $table->timestamp('storage_warning_sent_at')->nullable()->after('storage_paid_until');
            }
        });
    }

    public function down(): void
    {
        Schema::table('nfts', function (Blueprint $table) {
            $table->dropColumn(['storage_warning_sent_at']);
        });
    }
};
