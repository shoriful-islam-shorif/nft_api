<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfts', function (Blueprint $table) {
            // External links — all optional, shown publicly on the NFT
            // details page (same trust level as name/description).
            if (!Schema::hasColumn('nfts', 'external_website')) {
                $table->string('external_website')->nullable()->after('attributes');
            }
            if (!Schema::hasColumn('nfts', 'external_discord')) {
                $table->string('external_discord')->nullable()->after('external_website');
            }
            if (!Schema::hasColumn('nfts', 'external_twitter')) {
                $table->string('external_twitter')->nullable()->after('external_discord');
            }
            if (!Schema::hasColumn('nfts', 'external_sosay')) {
                $table->string('external_sosay')->nullable()->after('external_twitter');
            }

            // Unlockable content — e.g. a download link, redeem code,
            // or private message. Stored as plain text; NEVER returned
            // to anyone except the current owner (enforced in
            // NftController@show / index-type queries hide it by
            // default) — this is NOT encrypted at rest, so don't store
            // anything more sensitive than "a link only the owner
            // should see" here without adding encryption first.
            if (!Schema::hasColumn('nfts', 'unlockable_content')) {
                $table->text('unlockable_content')->nullable()->after('external_twitter');
            }

            // Explicit/sensitive content flag — shown as a warning gate
            // on the details/marketplace pages, and usable later to
            // exclude from default marketplace browsing/search if you
            // want an age-gate or "hide mature content" toggle.
            if (!Schema::hasColumn('nfts', 'is_explicit')) {
                $table->boolean('is_explicit')->default(false)->after('unlockable_content');
            }
        });
    }

    public function down(): void
    {
        Schema::table('nfts', function (Blueprint $table) {
            $table->dropColumn([
                'external_website', 'external_discord', 'external_twitter','external_sosay',
                'unlockable_content', 'is_explicit',
            ]);
        });
    }
};
