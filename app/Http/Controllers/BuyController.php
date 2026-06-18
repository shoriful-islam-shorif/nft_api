<?php

namespace App\Http\Controllers;

use App\Models\Nft;
use App\Services\SolanaService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class BuyController extends Controller
{
    private SolanaService $solana;

    public function __construct(SolanaService $solana)
    {
        $this->solana = $solana;
    }

    /**
     * Buy after transaction details 
     * GET /api/buy/prepare/{nft_id}
     */
    public function prepare(int $nftId): JsonResponse
    {
        $nft = Nft::findOrFail($nftId);

        if (!$nft->is_listed) {
            return response()->json(['success' => false, 'message' => 'NFT is not listed for sale.'], 422);
        }

        if ($nft->status !== 'minted') {
            return response()->json(['success' => false, 'message' => 'NFT is not available.'], 422);
        }

        $platformFeePercent = 3;
        $platformFee        = ($nft->list_price * $platformFeePercent) / 100;
        $sellerReceives     = $nft->list_price - $platformFee;

        return response()->json([
            'success' => true,
            'data'    => [
                'nft_id'             => $nft->id,
                'nft_name'           => $nft->name,
                'image_url'          => $nft->image_url,
                'mint_address'       => $nft->mint_address,
                'seller_wallet'      => $nft->wallet_address,
                'platform_wallet'    => config('services.solana.platform_wallet'),
                'list_price'         => $nft->list_price,
                'platform_fee'       => round($platformFee, 9),
                'seller_receives'    => round($sellerReceives, 9),
                'platform_fee_pct'   => $platformFeePercent,
            ],
        ]);
    }

    /**
     * Buy confirm — transaction verify to ownership transfer 
     * POST /api/buy/confirm
     */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate([
            'nft_id'           => 'required|exists:nfts,id',
            'buyer_wallet'     => 'required|string',
            'transaction_sig'  => 'required|string',
        ]);

        $nft = Nft::findOrFail($request->nft_id);

        // Double-check:  listed 
        if (!$nft->is_listed) {
            return response()->json(['success' => false, 'message' => 'NFT is no longer available.'], 422);
        }

        // Buyer own not seller 
        if ($nft->wallet_address === $request->buyer_wallet) {
            return response()->json(['success' => false, 'message' => 'You cannot buy your own NFT.'], 422);
        }

        Log::info('Buy confirm request', [
            'nft_id'          => $request->nft_id,
            'buyer_wallet'    => $request->buyer_wallet,
            'transaction_sig' => $request->transaction_sig,
        ]);

        // Transaction verify 
        $transaction = $this->solana->getTransaction($request->transaction_sig);
        $verified    = $transaction !== null
            || $this->solana->isSignatureConfirmed($request->transaction_sig);

        if (!$verified) {
            return response()->json([
                'success' => false,
                'message' => 'Payment transaction not confirmed yet. Please wait a moment and try again.',
            ], 422);
        }

        // DB to ownership transfer 
        DB::transaction(function () use ($nft, $request) {
            $previousOwner = $nft->wallet_address;

            $nft->update([
                'wallet_address'  => $request->buyer_wallet,
                'is_listed'       => false,
                'list_price'      => null,
                'listed_at'       => null,
                'sold_to'         => $request->buyer_wallet,
                'sold_at'         => now(),
                'sold_tx'         => $request->transaction_sig,
                'previous_owner'  => $previousOwner,
            ]);
        });

        Log::info('NFT sold successfully', [
            'nft_id'       => $nft->id,
            'buyer_wallet' => $request->buyer_wallet,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'NFT purchased successfully! 🎉',
            'data'    => [
                'nft_id'         => $nft->id,
                'nft_name'       => $nft->name,
                'mint_address'   => $nft->mint_address,
                'new_owner'      => $request->buyer_wallet,
                'transaction_sig'=> $request->transaction_sig,
                'explorer_url'   => $this->solana->getExplorerUrl($request->transaction_sig, 'tx'),
            ],
        ]);
    }
}
