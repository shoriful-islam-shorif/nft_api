<?php

namespace App\Http\Controllers;

use App\Models\Nft;
use App\Models\PlatformSetting;
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
        $listPrice    = (float) $nft->list_price;
        $listCurrency = $nft->list_currency ?: 'spump';

        // has_buyer_discount / buyer_discount_percent are PLATFORM-WIDE
        // admin settings (same category as the platform fee below) —
        // read LIVE from PlatformSetting here, not from this NFT row's
        // own stored columns. Those columns are only a snapshot captured
        // at mint time (see NftController::create()); reading them here
        // would mean an admin changing the discount in the admin panel
        // never takes effect for any NFT minted before that change,
        // which is exactly the bug this fixes — the buyer should always
        // see today's actual discount, the same way they always see
        // today's actual platform fee.
        //
        // buyer_discount_max_uses is intentionally NOT read live — it's
        // a per-drop admin input captured at mint time (no matching
        // PlatformSetting key), since "first N buyers of THIS drop" is
        // meant to vary per collection/drop, unlike the discount %
        // itself which is one global promotion setting.
        $buyerDiscountPercent = (float) PlatformSetting::get('buyer_discount_percent', 0);
        $hasBuyerDiscount     = $buyerDiscountPercent > 0;
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

        // Platform fee is now a flat SOL amount (admin-configured via
        // 'platform_fee_amount_sol'), not a percentage of the sale
        // price — converted live into whatever currency this NFT is
        // actually listed in, same conversion approach mint_price and
        // the storage fee use. If the rate is unavailable, fail closed
        // (pricing_unavailable=true) rather than silently charging a
        // 0 platform fee — prepare()/confirm() check this flag.
        $platformFeeAmountSol = $this->solana->getPlatformFeeAmountSol();
        $platformFee          = $this->solana->convertSolTo($platformFeeAmountSol, $listCurrency);
        $pricingUnavailable   = $platformFee === null;
        $platformFee          = $platformFee ?? 0.0;

        // Royalty only applies on a resale (current owner !== original creator).
        $creatorWallet = $nft->creator_wallet;
        $sellerWallet  = $nft->wallet_address;
        $isResale      = $creatorWallet && $sellerWallet && $creatorWallet !== $sellerWallet;

        $royaltyPercent = (float) ($nft->royalty ?? 0);
        $royaltyAmount  = $isResale ? round($priceAfterDiscount * $royaltyPercent / 100, 6) : 0;

        $sellerReceives = round($priceAfterDiscount - $platformFee - $royaltyAmount, 6);

        return [
            'list_price'             => $listPrice,
            'list_currency'          => $listCurrency,
            'is_discount_eligible'   => $isDiscountEligible,
            'buyer_discount_percent' => $buyerDiscountPercent,
            'buyer_discount_amount'  => $buyerDiscountAmount,
            'buyer_discount_max_uses'=> $buyerDiscountMaxUses,
            'buyers_so_far'          => $buyersSoFar,
            'price_after_discount'  => $priceAfterDiscount,
            'platform_fee_amount_sol'=> $platformFeeAmountSol,
            'platform_fee'          => $platformFee,
            'pricing_unavailable'   => $pricingUnavailable,
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

        if ($pricing['pricing_unavailable']) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to price this item right now (rate unavailable). Please try again shortly.',
            ], 503);
        }

        return response()->json([
            'success' => true,
            'data'    => array_merge($pricing, [
                'nft_id'       => $nft->id,
                'nft_name'     => $nft->name,
                'image_url'    => $nft->image_url,
                'mint_address' => $nft->mint_address,
                'platform_wallet' => $this->solana->getTreasuryWallet(),
                'delegate_wallet' => $this->solana->getDelegateWallet(),
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
        //
        // The lock now wraps EVERYTHING, including signing/broadcast —
        // not just the DB write — because pricing (computed under the
        // lock) is what we tell the signer to verify before it signs.
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

            // ── Compute expected payment BEFORE signing anything ─────────
            // list_price (and everything calculatePricing() derives from
            // it) is denominated in whatever currency the seller listed
            // in (list_currency: spump or usdc). The buyer can pay in
            // either — if it matches list_currency, use those exact
            // amounts directly; if it differs, convert via the live
            // SPUMP/USDC rate first (with a tolerance band for rate drift
            // between the buyer's quote and this confirmation). Both are
            // SPL tokens — there's no native-SOL payment path.
            //
            // Computed *inside* the lock so buyersSoFar reflects any sale
            // that just committed a moment ago in another request.
            $pricing = $this->calculatePricing($nft);

            if ($pricing['pricing_unavailable']) {
                Log::error('Buy confirm blocked: platform fee rate unavailable', ['nft_id' => $nft->id]);
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to price this item right now (rate unavailable). Please try again shortly.',
                ], 503);
            }

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

            // ── Expected transfers, handed to the signer BEFORE signing ──
            // SECURITY-CRITICAL: this is the fix for the "payment failed
            // but NFT transferred anyway" bug. The buyer's transaction is
            // atomic — payment + the mpl-core NFT-transfer instruction are
            // in the SAME transaction. Previously we co-signed and
            // broadcast it first, then checked payment amounts afterwards;
            // by the time a mismatch was caught, the transfer had already
            // happened on-chain, irreversibly.
            //
            // Now coSignAndSubmit() itself decodes the transaction's
            // actual instructions and checks destination + amount against
            // $expectedTransfers BEFORE adding the platform's signature.
            // If anything doesn't match, it refuses to sign — nothing is
            // broadcast, so the NFT is never transferred and the buyer is
            // never charged.
            $expectedTransfers = [
                ['destination' => $pricing['seller_wallet'], 'amount' => $sellerAmount, 'mint' => $mintAddress],
            ];
            if ($platformAmount > 0 && $treasuryWallet) {
                $expectedTransfers[] = ['destination' => $treasuryWallet, 'amount' => $platformAmount, 'mint' => $mintAddress];
            }
            if ($royaltyAmount > 0 && $pricing['creator_wallet']) {
                $expectedTransfers[] = ['destination' => $pricing['creator_wallet'], 'amount' => $royaltyAmount, 'mint' => $mintAddress];
            }

            // SECURITY-CRITICAL, same rationale as $expectedTransfers above:
            // proves the transaction's mpl-core TransferV1 instruction moves
            // THIS NFT (not some other asset the platform delegate also has
            // authority over) to THIS buyer. Without this, correct payment
            // alone would be enough to get the platform to co-sign a
            // transfer of a different, more valuable NFT to the buyer.
            $expectedNftTransfer = [
                'asset'     => $nft->mint_address,
                'newOwner'  => $request->buyer_wallet,
                'authority' => $this->solana->getDelegateWallet(),
            ];

            // ── Verify-then-co-sign + broadcast ───────────────────────────
            // The buyer has already signed this transaction (payment
            // instructions + the mpl-core NFT-transfer instruction, which
            // names the platform as delegate authority). We only add the
            // platform's signature and submit it if $expectedTransfers
            // matches what the transaction actually instructs — this is
            // what actually moves NFT ownership on-chain, not just a DB
            // update, so it must not happen on an unverified payment.
            $submission = $this->signer->coSignAndSubmit($request->signed_tx, $expectedTransfers, $tolerance, $expectedNftTransfer);

            if (!($submission['success'] ?? false)) {
                Log::warning('Buy confirm blocked: signer refused to co-sign/broadcast', [
                    'nft_id'             => $nft->id,
                    'buyer_wallet'       => $request->buyer_wallet,
                    'payment_currency'   => $paymentCurrency,
                    'expected_transfers' => $expectedTransfers,
                    'error'              => $submission['error'] ?? null,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => $submission['error'] ?? 'Transaction failed to submit. The NFT was not transferred and you were not charged.',
                ], 422);
            }

            $signature = $submission['signature'];
            $salePrice = $pricing['price_after_discount'];

            // DB to ownership transfer — still inside the lock, so the
            // next waiting confirm() for this collection only proceeds
            // (and only counts this sale) once it's fully committed.
            //
            // Broadcast having succeeded IS the source of truth here: the
            // signer already verified the payment before signing, so by
            // the time we get here the transfer is confirmed on-chain and
            // this DB write is just catching up to reality.
            // Stored in list_currency (== sold_currency below), matching
            // $salePrice — NOT $platformAmount above, which may have
            // been converted into paymentCurrency if the buyer paid in
            // the other token. Keeps sold_price/platform_fee_charged/
            // sold_currency internally consistent for admin reporting.
            $platformFeeChargedInListCurrency = $pricing['platform_fee'];

            DB::transaction(function () use ($nft, $request, $salePrice, $signature, $listCurrency, $platformFeeChargedInListCurrency) {
                $previousOwner = $nft->wallet_address;

                $nft->update([
                    'wallet_address'  => $request->buyer_wallet,
                    'is_listed'       => false,
                    'sold_price'      => $salePrice,
                    'sold_currency'   => $listCurrency, 
                    'list_price'      => null,
                    'listed_at'       => null,
                    'sold_to'         => $request->buyer_wallet,
                    'sold_at'         => now(),
                    'sold_tx'         => $signature,
                    'previous_owner'  => $previousOwner,
                    // Actual flat platform fee charged, in sold_currency —
                    // the platform fee is no longer a fixed % of volume,
                    // so admin revenue reporting sums this column instead
                    // of recomputing a percentage after the fact.
                    'platform_fee_charged' => $platformFeeChargedInListCurrency,
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
                'source'         => $data['source'] ?? null,
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
