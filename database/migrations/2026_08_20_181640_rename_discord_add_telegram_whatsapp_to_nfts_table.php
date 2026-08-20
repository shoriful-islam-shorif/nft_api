<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // NOTE: uses add-new-column + copy-data + drop-old-column instead
        // of Schema::renameColumn(), because that requires the
        // doctrine/dbal package, which isn't installed in this project —
        // this approach needs nothing extra and still preserves every
        // existing NFT's already-saved link.
        Schema::table('nfts', function (Blueprint $table) {
            // "Discord" was too narrow — creators want to link ANY social
            // profile (Instagram, TikTok, YouTube, a personal Discord
            // invite, etc.), not just Discord specifically.
            if (!Schema::hasColumn('nfts', 'external_social')) {
                $table->string('external_social')->nullable()->after('external_website');
            }
            if (!Schema::hasColumn('nfts', 'external_telegram')) {
                $table->string('external_telegram')->nullable()->after('external_social');
            }
            if (!Schema::hasColumn('nfts', 'external_whatsapp')) {
                $table->string('external_whatsapp')->nullable()->after('external_telegram');
            }
        });

        if (Schema::hasColumn('nfts', 'external_discord') && Schema::hasColumn('nfts', 'external_social')) {
            DB::table('nfts')->whereNotNull('external_discord')->update([
                'external_social' => DB::raw('external_discord'),
            ]);

            Schema::table('nfts', function (Blueprint $table) {
                $table->dropColumn('external_discord');
            });
        }
    }

    public function down(): void
    {
        Schema::table('nfts', function (Blueprint $table) {
            if (!Schema::hasColumn('nfts', 'external_discord')) {
                $table->string('external_discord')->nullable()->after('external_website');
            }
        });

        if (Schema::hasColumn('nfts', 'external_social')) {
            DB::table('nfts')->whereNotNull('external_social')->update([
                'external_discord' => DB::raw('external_social'),
            ]);
        }

        Schema::table('nfts', function (Blueprint $table) {
            $table->dropColumn(['external_social', 'external_telegram', 'external_whatsapp']);
        });
    }
};