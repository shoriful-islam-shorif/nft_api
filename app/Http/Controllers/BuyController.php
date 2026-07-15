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
     * Single source of truth for buy-side pricing: buyer discount →
     * platform fee → royalty → seller receives. Both `prepare()` (what
     * the buyer previews) and `confirm()` (what actually gets verified
     * on-chain) must use this exact same method, or a buyer could be
     * shown one price and charged/verified against another.
     *
     * Order matters: discount is applied to list price first, then
     * platform fee and royalty are both taken from the *discounted*
     * price — matching the frontend's calculation exactly.
     */
    private function calculatePricing(Nft $nft): array
    {
        $listPrice = (float) $nft->list_price;

        $hasBuyerDiscount     = (bool) $nft->has_buyer_discount;
        $buyerDiscountPercent = (float) ($nft->buyer_discount_percent ?? 0);
        $buyerDiscountMaxUses = (int) ($nft->buyer_discount_max_uses ?? 0);

        // "First N buyers" only makes sense counted across the whole
        // collection/drop — a single NFT row's own minted_count is
        // always 0 or 1 and can never reflect "buyer #37 of 50".
        // Fall back to this NFT's own sold state only when it isn't
        // part of any collection.
        $buyersSoFar = $nft->collection_id
            ? Nft::where('collection_id', $nft->collection_id)->whereNotNull('sold_to')->count()
            : ($nft->sold_to ? 1 : 0);

        $isDiscountEligible = $hasBuyerDiscount
            && $buyerDiscountMaxUses > 0
            && $buyersSoFar < $buyerDiscountMaxUses;

        $buyerDiscountAmount = $isDiscountEligible
            ? round($listPrice * $buyerDiscountPercent / 100, 6)
            : 0;

        $priceAfterDiscount = round($listPrice - $buyerDiscountAmount, 6);

        $platformFeePercent = $this->solana->getPlatformFeePercent();
        $platformFee        = round($priceAfterDiscount * $platformFeePercent / 100, 6);

        // Royalty only applies on a resale (current owner !== original creator).
        $creatorWallet = $nft->creator_wallet;
        $sellerWallet  = $nft->wallet_address;
        $isResale      = $creatorWallet && $sellerWallet && $creatorWallet !== $sellerWallet;

        $royaltyPercent = (float) ($nft->royalty ?? 0);
        $royaltyAmount  = $isResale ? round($priceAfterDiscount * $royaltyPercent / 100, 6) : 0;

        $sellerReceives = round($priceAfterDiscount - $platformFee - $royaltyAmount, 6);

        return [
            'list_price'             => $listPrice,
            'is_discount_eligible'   => $isDiscountEligible,
            'buyer_discount_percent' => $buyerDiscountPercent,
            'buyer_discount_amount'  => $buyerDiscountAmount,
            'buyer_discount_max_uses'=> $buyerDiscountMaxUses,
            'buyers_so_far'          => $buyersSoFar,
            'price_after_discount'  => $priceAfterDiscount,
            'platform_fee_percent'  => $platformFeePercent,
            'platform_fee'          => $platformFee,
            'is_resale'             => $isResale,
            'royalty_percent'       => $royaltyPercent,
            'royalty_amount'        => $royaltyAmount,
            'seller_receives'       => $sellerReceives,
            'seller_wallet'         => $sellerWallet,
            'creator_wallet'        => $creatorWallet,
        ];
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

        $pricing = $this->calculatePricing($nft);

        return response()->json([
            'success' => true,
            'data'    => array_merge($pricing, [
                'nft_id'       => $nft->id,
                'nft_name'     => $nft->name,
                'image_url'    => $nft->image_url,
                'mint_address' => $nft->mint_address,
                'platform_wallet' => $this->solana->getTreasuryWallet(),
                // Back-compat aliases for existing frontend fields
                'platform_fee_pct' => $pricing['platform_fee_percent'],
            ]),
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
            'payment_method'   => 'nullable|string|in:sol,spump',
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

        // ── Payment Verification ─────────────────────────────
        // As with minting, a confirmed signature only proves *some*
        // transaction landed — not that the seller, creator, and
        // platform were actually paid the correct amounts. Recompute
        // the exact same discount → fee → royalty breakdown the
        // frontend used, then check each transfer really happened.
        $paymentMethod = $request->input('payment_method', 'sol');
        $pricing       = $this->calculatePricing($nft);
        $treasuryWallet = $this->solana->getTreasuryWallet();

        if ($paymentMethod === 'sol') {
            $sellerPaid = $this->solana->verifyPayment(
                $request->transaction_sig, $pricing['seller_wallet'], $pricing['seller_receives']
            );

            $platformPaid = $pricing['platform_fee'] <= 0 || (
                $treasuryWallet && $this->solana->verifyPayment(
                    $request->transaction_sig, $treasuryWallet, $pricing['platform_fee']
                )
            );

            $royaltyPaid = $pricing['royalty_amount'] <= 0 || (
                $pricing['creator_wallet'] && $this->solana->verifyPayment(
                    $request->transaction_sig, $pricing['creator_wallet'], $pricing['royalty_amount']
                )
            );

            if (!$sellerPaid || !$platformPaid || !$royaltyPaid) {
                Log::warning('Buy confirm blocked: payment not found in transaction', [
                    'nft_id'          => $nft->id,
                    'transaction_sig' => $request->transaction_sig,
                    'pricing'         => $pricing,
                    'treasury_wallet' => $treasuryWallet,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Payment not found in this transaction. Expected '
                        . $pricing['seller_receives'] . ' SOL to the seller, '
                        . $pricing['platform_fee'] . ' SOL to the platform'
                        . ($pricing['royalty_amount'] > 0 ? ', and ' . $pricing['royalty_amount'] . ' SOL royalty to the creator.' : '.'),
                ], 422);
            }
        } else {
            // SPUMP payments transfer an SPL-token amount computed from
            // a live SOL/SPUMP rate. Re-fetch that same rate now and
            // verify each recipient's token account actually received
            // the expected converted amount — with a tolerance band to
            // absorb rate drift between the buyer's quote and this
            // confirmation (the rate itself refreshes every 60s).
            $spumpMint = env('SPUMP_MINT_ADDRESS');
            $rateData  = $this->getSpumpRateData();

            if (!$spumpMint || !$rateData) {
                Log::error('Buy confirm blocked: SPUMP rate unavailable for verification', [
                    'nft_id' => $nft->id,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Unable to verify SPUMP payment right now. Please try again shortly.',
                ], 422);
            }

            $spumpPerSol  = (float) $rateData['spump_per_sol'];
            $spumpTotal   = ceil($pricing['price_after_discount'] * $spumpPerSol);
            $spumpFee     = ceil($spumpTotal * $pricing['platform_fee_percent'] / 100);
            $spumpRoyalty = $pricing['is_resale'] ? ceil($spumpTotal * $pricing['royalty_percent'] / 100) : 0;
            $spumpSeller  = $spumpTotal - $spumpFee - $spumpRoyalty;

            // 5% covers normal rate volatility in that window without
            // being loose enough for meaningful underpayment.
            $tolerance = max($spumpTotal * 0.05, 1);

            $sellerPaid = $this->solana->verifyTokenPayment(
                $request->transaction_sig, $spumpMint, $pricing['seller_wallet'], $spumpSeller, $tolerance
            );
            $platformPaid = $spumpFee <= 0 || (
                $treasuryWallet && $this->solana->verifyTokenPayment(
                    $request->transaction_sig, $spumpMint, $treasuryWallet, $spumpFee, $tolerance
                )
            );
            $royaltyPaid = $spumpRoyalty <= 0 || (
                $pricing['creator_wallet'] && $this->solana->verifyTokenPayment(
                    $request->transaction_sig, $spumpMint, $pricing['creator_wallet'], $spumpRoyalty, $tolerance
                )
            );

            if (!$sellerPaid || !$platformPaid || !$royaltyPaid) {
                Log::warning('Buy confirm blocked: SPUMP payment not found in transaction', [
                    'nft_id'           => $nft->id,
                    'transaction_sig'  => $request->transaction_sig,
                    'expected_seller'  => $spumpSeller,
                    'expected_fee'     => $spumpFee,
                    'expected_royalty' => $spumpRoyalty,
                    'spump_per_sol'    => $spumpPerSol,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'SPUMP payment not found in this transaction. Expected ~' . $spumpSeller . ' SPUMP to the seller, ~' . $spumpFee . ' SPUMP to the platform'
                        . ($spumpRoyalty > 0 ? ', and ~' . $spumpRoyalty . ' SPUMP royalty to the creator.' : '.'),
                ], 422);
            }
        }

        $salePrice = $pricing['price_after_discount'];

        // DB to ownership transfer 
        DB::transaction(function () use ($nft, $request, $salePrice) {
            $previousOwner = $nft->wallet_address;

            $nft->update([
                'wallet_address'  => $request->buyer_wallet,
                'is_listed'       => false,
                'sold_price'      => $salePrice,
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

    private function getSpumpRateData(): ?array
    {
        return Cache::remember('spump_price_data', 60, function () {

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
    }

    public function spumpPrice(): JsonResponse
    {
        try {
            $data = $this->getSpumpRateData();

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
