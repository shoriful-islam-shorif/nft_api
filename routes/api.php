<?php

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
    Route::get('/',    [CollectionController::class, 'index']);  // list
    Route::post('/',   [CollectionController::class, 'store']);  // create
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
