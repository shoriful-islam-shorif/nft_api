<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Collections Table ────────────────────────
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('image_url')->nullable();
            $table->string('wallet_address');
            $table->string('symbol')->nullable();
            $table->timestamps();
        });

        // ── NFTs Table ───────────────────────────────
        Schema::create('nfts', function (Blueprint $table) {
            $table->id();

            // Basic Info
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('symbol')->default('NFT');
            $table->string('image_url');
            $table->string('image_hash');
            $table->string('metadata_uri');
            $table->string('metadata_hash');

            // Collection & Category
            $table->foreignId('collection_id')->nullable()->constrained('collections')->nullOnDelete();
            $table->string('category')->default('art');

            // Edition / Supply
            $table->enum('edition_type', ['unlimited', 'limited'])->default('unlimited');
            $table->unsignedInteger('total_supply')->nullable();
            $table->unsignedInteger('minted_count')->default(0);

            // Pricing
            $table->decimal('mint_price', 18, 9)->default(0);
            $table->boolean('is_free_listing')->default(false);

            // Mint Discount
            $table->boolean('has_mint_discount')->default(false);
            $table->decimal('mint_discount_percent', 5, 2)->default(0);
            $table->decimal('price_after_discount', 18, 9)->default(0);

            // Buyer Discount
            $table->boolean('has_buyer_discount')->default(false);
            $table->decimal('buyer_discount_percent', 5, 2)->default(0);
            $table->unsignedInteger('buyer_discount_max_uses')->nullable();

            // Royalty & Network
            $table->decimal('royalty', 5, 2)->default(5.00);
            $table->string('network')->default('devnet');
            $table->decimal('network_fee', 18, 9)->default(0.00001);

            // Wallet & Blockchain
            $table->string('wallet_address');
            $table->string('mint_address')->nullable()->unique();
            $table->string('transaction_sig')->nullable();

            // Status
            $table->enum('status', ['draft', 'pending','rejected', 'minted', 'failed'])->default('pending');
            $table->timestamp('minted_at')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('wallet_address');
            $table->index('status');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfts');
        Schema::dropIfExists('collections');
    }
};
