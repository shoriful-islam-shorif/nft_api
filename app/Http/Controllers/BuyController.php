<?php

namespace App\Http\Controllers;

use App\Models\Nft;
use App\Services\SolanaService;
use App\Services\SolanaSignerService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class BuyController extends Controller
{
    private SolanaService $solana;
    private SolanaSignerService $signer;

    public function __construct(SolanaService $solana, SolanaSignerService $signer)
    {
        $this->solana = $solana;
        $this->signer = $signer;
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
            'list_currency'          => $nft->list_currency ?: 'spump',
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
                'delegate_wallet' => $this->solana->getDelegateWallet(),
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
            'signed_tx'        => 'required|string',
            'payment_currency' => 'nullable|string|in:spump,usdc',
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
            'nft_id'       => $request->nft_id,
            'buyer_wallet' => $request->buyer_wallet,
        ]);

        // ── Co-sign + broadcast ──────────────────────────────
        // The buyer has already signed this transaction (payment
        // instructions + the mpl-core NFT-transfer instruction, which
        // names the platform as delegate authority). We add the
        // platform's signature and submit it ourselves — this is what
        // actually moves NFT ownership on-chain, not just a DB update.
        $submission = $this->signer->coSignAndSubmit($request->signed_tx);

        if (!($submission['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $submission['error'] ?? 'Transaction failed to submit. The NFT was not transferred and you were not charged.',
            ], 422);
        }

        $signature = $submission['signature'];

        // ── Race-condition fix: "first N buyers" discount ────────────
        // calculatePricing() decides eligibility by COUNTING already-sold
        // NFTs in this collection. Without a lock, two buyers confirming
        // at nearly the same moment can both read the same "buyersSoFar"
        // value, both get judged eligible, and both then write sold_to —
        // letting more than buyer_discount_max_uses people get the
        // discount. A per-collection lock forces confirm() calls for the
        // same collection to run one-at-a-time for this section, so the
        // count each buyer sees is always up to date with any sale that
        // already committed. Items with no collection have nothing to
        // race over (their own sold_to state is checked via is_listed
        // above), so no lock is needed for those.
        $discountLock = $nft->collection_id
            ? Cache::lock("buy-confirm:collection:{$nft->collection_id}", 20)
            : null;

        try {
            if ($discountLock) {
                try {
                    $discountLock->block(10);
                } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
                    Log::warning('Buy confirm blocked: could not acquire discount lock in time', [
                        'nft_id'        => $nft->id,
                        'collection_id' => $nft->collection_id,
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'This item is in high demand right now — please try confirming again in a few seconds.',
                    ], 429);
                }
            }

            // ── Payment Verification ─────────────────────────────
            // Broadcasting successfully only proves the transaction (which
            // includes both the payment AND the NFT transfer instruction)
            // executed — but recompute the exact same discount → fee →
            // royalty breakdown the frontend used and double-check each
            // transfer amount, as defense in depth.
            //
            // list_price (and everything calculatePricing() derives from
            // it) is denominated in whatever currency the seller listed
            // in (list_currency: spump or usdc). The buyer can pay in
            // either — if it matches list_currency, verify those exact
            // amounts directly; if it differs, convert via the live
            // SPUMP/USDC rate first (with a tolerance band for rate drift
            // between the buyer's quote and this confirmation). Both are
            // SPL tokens, so verification always goes through
            // verifyTokenPayment() — there's no native-SOL payment path.
            //
            // Recomputed *inside* the lock so buyersSoFar reflects any
            // sale that just committed a moment ago in another request.
            $pricing         = $this->calculatePricing($nft);
            $treasuryWallet  = $this->solana->getTreasuryWallet();
            $listCurrency    = $pricing['list_currency'];
            $paymentCurrency = $request->input('payment_currency', $listCurrency);

            $sellerAmount   = $pricing['seller_receives'];
            $platformAmount = $pricing['platform_fee'];
            $royaltyAmount  = $pricing['royalty_amount'];
            $tolerance      = 0.0005;

            if ($paymentCurrency !== $listCurrency) {
                $rateData = $this->getSpumpRateData();
                if (!$rateData) {
                    Log::error('Buy confirm blocked: SPUMP/USDC rate unavailable for currency conversion', ['nft_id' => $nft->id]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Unable to verify payment right now (rate unavailable). Please try again shortly.',
                    ], 422);
                }
                $spumpPerUsdc = (float) $rateData['spump_per_usdc'];

                if ($listCurrency === 'usdc' && $paymentCurrency === 'spump') {
                    // Listed in USDC, buyer pays in SPUMP.
                    $sellerAmount   = $sellerAmount   * $spumpPerUsdc;
                    $platformAmount = $platformAmount * $spumpPerUsdc;
                    $royaltyAmount  = $royaltyAmount  * $spumpPerUsdc;
                } elseif ($listCurrency === 'spump' && $paymentCurrency === 'usdc') {
                    // Listed in SPUMP, buyer pays in USDC.
                    $sellerAmount   = $sellerAmount   / $spumpPerUsdc;
                    $platformAmount = $platformAmount / $spumpPerUsdc;
                    $royaltyAmount  = $royaltyAmount  / $spumpPerUsdc;
                }

                // 5% covers normal rate volatility between quote and confirm.
                $tolerance = max($sellerAmount * 0.05, $tolerance);
            }

            $mintAddress = $paymentCurrency === 'usdc'
                ? config('services.tokens.usdc_mint')
                : config('services.tokens.spump_mint');

            if (!$mintAddress) {
                Log::error('Buy confirm blocked: payment token mint not configured', ['currency' => $paymentCurrency]);
                return response()->json([
                    'success' => false,
                    'message' => 'Platform payment is not configured. Please contact support.',
                ], 500);
            }

            $sellerPaid = $this->solana->verifyTokenPayment(
                $signature, $mintAddress, $pricing['seller_wallet'], $sellerAmount, $tolerance
            );
            $platformPaid = $platformAmount <= 0 || (
                $treasuryWallet && $this->solana->verifyTokenPayment(
                    $signature, $mintAddress, $treasuryWallet, $platformAmount, $tolerance
                )
            );
            $royaltyPaid = $royaltyAmount <= 0 || (
                $pricing['creator_wallet'] && $this->solana->verifyTokenPayment(
                    $signature, $mintAddress, $pricing['creator_wallet'], $royaltyAmount, $tolerance
                )
            );

            if (!$sellerPaid || !$platformPaid || !$royaltyPaid) {
                Log::warning('Buy confirm blocked: payment not found in transaction', [
                    'nft_id'           => $nft->id,
                    'transaction_sig'  => $signature,
                    'list_currency'    => $listCurrency,
                    'payment_currency' => $paymentCurrency,
                    'expected_seller'  => $sellerAmount,
                    'expected_platform'=> $platformAmount,
                    'expected_royalty' => $royaltyAmount,
                    'treasury_wallet'  => $treasuryWallet,
                ]);

                $unit = strtoupper($paymentCurrency);
                return response()->json([
                    'success' => false,
                    'message' => "Payment not found in this transaction. Expected {$sellerAmount} {$unit} to the seller, {$platformAmount} {$unit} to the platform"
                        . ($royaltyAmount > 0 ? ", and {$royaltyAmount} {$unit} royalty to the creator." : '.'),
                ], 422);
            }

            $salePrice = $pricing['price_after_discount'];

            // DB to ownership transfer — still inside the lock, so the
            // next waiting confirm() for this collection only proceeds
            // (and only counts this sale) once it's fully committed.
            DB::transaction(function () use ($nft, $request, $salePrice, $signature) {
                $previousOwner = $nft->wallet_address;

                $nft->update([
                    'wallet_address'  => $request->buyer_wallet,
                    'is_listed'       => false,
                    'sold_price'      => $salePrice,
                    'list_price'      => null,
                    'listed_at'       => null,
                    'sold_to'         => $request->buyer_wallet,
                    'sold_at'         => now(),
                    'sold_tx'         => $signature,
                    'previous_owner'  => $previousOwner,
                ]);
            });
        } finally {
            if ($discountLock) {
                $discountLock->release();
            }
        }

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
                'transaction_sig'=> $signature,
                'explorer_url'   => $this->solana->getExplorerUrl($signature, 'tx'),
            ],
        ]);
    }

    private function getSpumpRateData(): ?array
    {
        return $this->solana->getSpumpUsdcRate();
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
                'success'        => true,
                'spump_per_usdc' => $data['spump_per_usdc'],
                'spump_usd'      => $data['spump_usd'],
                'usdc_usd'       => $data['usdc_usd'],
                'decimals'       => $data['decimals'],
                'usdc_decimals'  => $data['usdc_decimals'],
                'updated_at'     => $data['updated_at'],
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
