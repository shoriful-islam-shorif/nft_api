<?php

namespace App\Http\Controllers;

use App\Models\Nft;
use App\Services\SolanaService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

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
                // 'platform_wallet'    => config('services.solana.platform_wallet'),
                'platform_wallet'   => config('services.solana.platform_wallet') ?: null,
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

    public function spumpPrice(): JsonResponse
    {
        try {

            $data = Cache::remember('spump_price_data', 60, function () {

                $spumpMint = env('SPUMP_MINT_ADDRESS');
                $solMint   = 'So11111111111111111111111111111111111111112';

                $response = Http::timeout(10)->get(
                    'https://lite-api.jup.ag/price/v3',
                    [
                        'ids' => "{$spumpMint},{$solMint}"
                    ]
                );

                if (!$response->successful()) {
                    return null;
                }

                $prices = $response->json();

                if (
                    !isset($prices[$spumpMint]['usdPrice']) ||
                    !isset($prices[$solMint]['usdPrice'])
                ) {
                    return null;
                }

                $spumpUsd = (float) $prices[$spumpMint]['usdPrice'];
                $solUsd   = (float) $prices[$solMint]['usdPrice'];

                if ($spumpUsd <= 0) {
                    return null;
                }

                return [
                    'spump_per_sol' => round($solUsd / $spumpUsd),
                    'spump_usd'     => $spumpUsd,
                    'sol_usd'       => $solUsd,
                    'decimals'      => (int) ($prices[$spumpMint]['decimals'] ?? 6),
                    'updated_at'    => now()->toDateTimeString(),
                ];
            });

            if (!$data) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to fetch SPUMP price.'
                ], 500);
            }

            return response()->json([
                'success' => true,
                'spump_per_sol' => $data['spump_per_sol'],
                'spump_usd' => $data['spump_usd'],
                'sol_usd' => $data['sol_usd'],
                'decimals' => $data['decimals'],
                'updated_at' => $data['updated_at'],
            ]);

        } catch (\Throwable $e) {

            Log::error('SPUMP Price Error', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch SPUMP price.'
            ], 500);
        }
    }
}
