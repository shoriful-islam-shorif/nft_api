<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\BuyController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\NftController;
use App\Http\Controllers\IpfsController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\MarketplaceController;

// Health
Route::get('/health', fn() => response()->json(['status' => 'ok', 'message' => 'NFT API Running']));

// Wallet
Route::prefix('wallet')->group(function () {
    Route::post('/verify',        [WalletController::class, 'verify']);
    Route::get('/nfts/{address}', [WalletController::class, 'getNfts']);
});

// IPFS
Route::prefix('ipfs')->group(function () {
    Route::post('/upload-image',    [IpfsController::class, 'uploadImage']);
    Route::post('/upload-metadata', [IpfsController::class, 'uploadMetadata']);
    Route::delete('/unpin/{hash}',  [IpfsController::class, 'unpin']);
});

// Collections
Route::prefix('collections')->group(function () {
    Route::get('/',    [CollectionController::class, 'index']);     // list
    Route::post('/',   [CollectionController::class, 'store']);     // create
    Route::get('/{id}',  [CollectionController::class, 'show']);    // ← single collection + NFTs
    Route::put('/{id}',  [CollectionController::class, 'update']); //update collection
});

// NFT
Route::prefix('nft')->group(function () {
    Route::post('/create',        [NftController::class, 'create']);
    Route::post('/mint',          [NftController::class, 'mint']);
    Route::post('/calculate',     [NftController::class, 'calculate']);
    Route::get('/list/{wallet}',  [NftController::class, 'listByWallet']);
    Route::get('/{mint_address}', [NftController::class, 'show']);
});

// Marketplace
Route::prefix('marketplace')->group(function () {
    Route::get('/',                 [MarketplaceController::class, 'index']);
    Route::post('/list',            [MarketplaceController::class, 'list']);
    Route::post('/unlist',          [MarketplaceController::class, 'unlist']);
    Route::get('/my-nfts/{wallet}', [MarketplaceController::class, 'myNfts']);
});

// Buy
Route::prefix('buy')->group(function () {
    Route::get('/prepare/{nft_id}', [BuyController::class, 'prepare']);
    Route::post('/confirm',         [BuyController::class, 'confirm']);
    Route::get('/spump-price', [BuyController::class, 'spumpPrice']);
});

Route::get('/config', function () {
    return response()->json([
        'success' => true,
        'data'    => [
            'platform_fee_percent' => config('services.platform.fee_percent', 3),
            'network'              => config('services.solana.network', 'devnet'),
        ],
    ]);
});

// Admin Routes
Route::prefix('admin')->group(function () {

    // Public — login only
    Route::post('/login', [AdminController::class, 'login']);

    // Protected — Sanctum token 
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('/logout',               [AdminController::class, 'logout']);
        Route::get('/stats',                 [AdminController::class, 'stats']);
        Route::get('/nfts',                  [AdminController::class, 'nfts']);
        Route::get('/nfts/pending',          [AdminController::class, 'pendingNfts']);
        Route::post('/nfts/{id}/approve',    [AdminController::class, 'approveNft']);
        Route::post('/nfts/{id}/reject',     [AdminController::class, 'rejectNft']);
        Route::post('/nfts/{id}/unlist',     [AdminController::class, 'unlistNft']);
        Route::delete('/nfts/{id}',          [AdminController::class, 'deleteNft']);
        Route::get('/sales',                 [AdminController::class, 'sales']);
        Route::get('/users',                 [AdminController::class, 'users']);
    });
});


