<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('symbol')->default('NFT');
            $table->string('image_url');
            $table->string('image_hash');          // Pinata IPFS hash
            $table->string('metadata_uri');         // Pinata metadata URL
            $table->string('metadata_hash');        // Pinata metadata hash
            $table->string('wallet_address');       // Owner wallet
            $table->string('mint_address')->nullable()->unique(); // Solana mint address
            $table->string('transaction_sig')->nullable();        // Solana tx signature
            $table->integer('royalty')->default(5); // Royalty %
            $table->string('network')->default('devnet');
            $table->enum('status', ['pending', 'minted', 'failed'])->default('pending');
            $table->timestamp('minted_at')->nullable();
            $table->timestamps();

            $table->index('wallet_address');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfts');
    }
};
