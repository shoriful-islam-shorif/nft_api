<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\NftController;
use App\Http\Controllers\IpfsController;
use App\Http\Controllers\WalletController;

// ── Health Check ──────────────────────────
Route::get('/health', fn() => response()->json(['status' => 'ok', 'message' => 'NFT API Running']));

// ── Wallet ────────────────────────────────
Route::prefix('wallet')->group(function () {
    Route::post('/verify',        [WalletController::class, 'verify']);    // Wallet + balance check
    Route::get('/nfts/{address}', [WalletController::class, 'getNfts']);   // Chain NFT list
});

// ── IPFS / Pinata ─────────────────────────
Route::prefix('ipfs')->group(function () {
    Route::post('/upload-image',    [IpfsController::class, 'uploadImage']);
    Route::post('/upload-metadata', [IpfsController::class, 'uploadMetadata']);
    Route::delete('/unpin/{hash}',  [IpfsController::class, 'unpin']);
});

// ── NFT ───────────────────────────────────
Route::prefix('nft')->group(function () {
    Route::post('/create',       [NftController::class, 'create']);        // NFT Create
    Route::post('/mint',         [NftController::class, 'mint']);          // Mint confirm
    Route::post('/calculate',    [NftController::class, 'calculate']);     // Price calculate
    Route::get('/list/{wallet}', [NftController::class, 'listByWallet']); // Wallet NFT list
    Route::get('/{mint_address}',[NftController::class, 'show']);          // Single NFT
});

// ── Collections ───────────────────────────
Route::get('/collections', [NftController::class, 'collections']); // Collection list
