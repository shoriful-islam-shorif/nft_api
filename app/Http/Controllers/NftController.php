<?php

namespace App\Http\Controllers;

use App\Models\Nft;
use App\Models\Collection;
use App\Models\PlatformSetting;
use App\Services\LocalStorageService;
use App\Services\SolanaService;
use App\Services\SolanaSignerService;
use App\Services\StorageFeeService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;


class NftController extends Controller
{
    const NETWORK_FEE_SOL = 0.00001;

    const CATEGORIES = [
        'art', 'music', 'gaming', 'sports',
        'photography', 'collectible', 'utility', 'other',
        'fashion', 'jewelry', 'video', 'digital',
    ];

    public function __construct(
        private LocalStorageService $storage,
        private SolanaService $solana,
        private SolanaSignerService $signer,
        private StorageFeeService $storageFee
    ) {}

    /**
     * NFT Create
     * POST /api/nft/create
     * Wallet connect to  — account 
     */
    public function create(Request $request): JsonResponse
    {
        $validator = \Validator::make($request->all(), [
        'wallet_address'          => 'required|string',
        'name'                    => 'required|string|max:100',
        'description'             => 'required|string|max:1000',
        'symbol'                  => 'nullable|string|max:10',
        'image'                   => 'required|file|image|mimes:jpg,jpeg,png,gif,webp|max:50240|dimensions:max_width=8000,max_height=8000',

        'collection_id'           => 'nullable|exists:collections,id',
        'category'                => 'required|in:' . implode(',', self::CATEGORIES),

        'edition_type'            => 'required|in:unlimited,limited',
        'total_supply'            => 'required_if:edition_type,limited|nullable|integer|min:1|max:10',

        'mint_price'              => 'required|numeric|min:0',
        'is_free_listing'         => 'boolean',

        'has_mint_discount'       => 'boolean',
        'mint_discount_percent'   => 'nullable|numeric|min:1|max:90',

        'has_buyer_discount'      => 'boolean',
        'buyer_discount_percent'  => 'nullable|numeric|min:1|max:90',
        'buyer_discount_max_uses' => 'nullable|integer|min:1',

        'royalty'                 => 'nullable|numeric|min:0|max:50',
        'attributes'             => 'nullable|array',
        'attributes.*.trait_type' => 'required|string',
        'attributes.*.value'      => 'required|string',

        // External links — all optional
        'external_website'   => 'nullable|url|max:255',
        'external_social'    => 'nullable|url|max:255',
        'external_twitter'   => 'nullable|url|max:255',
        'external_sosay'      => 'nullable|url|max:255',
        'external_telegram'  => 'nullable|url|max:255',
        'external_whatsapp'  => 'nullable|string|max:255',

        // Unlockable content — optional, buyer/owner-only (see Nft
        // model's $hidden + NftController@show)
        'unlockable_content' => 'nullable|string|max:5000',

        // Explicit/sensitive content flag
        'is_explicit'        => 'boolean',
    ]);

    // ❌ RETURN JSON (NO REDIRECT)
    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation error',
            'errors'  => $validator->errors(),
        ], 422);
    }

    $data = $validator->validated();

        // ── Wallet Validate ──────────────────────────
        if (!$this->solana->isValidWalletAddress($request->wallet_address)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Solana wallet address',
            ], 422);
        }

        // ── Price Calculation ────────────────────────
        // mint_price / is_free_listing / mint_discount_percent /
        // has_buyer_discount / buyer_discount_percent /
        // buyer_discount_max_uses are PLATFORM-WIDE admin settings (see
        // AdminController::publicConfig() — the frontend's own NFT
        // Studio page treats these as read-only, pulled from
        // /api/config, not creator input). They must be read from
        // PlatformSetting here too, NOT trusted from the request body —
        // otherwise anyone calling this endpoint directly (bypassing the
        // UI) could pass is_free_listing=1 / mint_price=0 and mint for
        // free regardless of what the admin actually configured, since
        // mint()'s payment check later only looks at what got stored
        // here on this row.
        $isFree = (bool) PlatformSetting::get('is_free_listing', true);

        // mint_price is now admin-configured in SOL; storage fee (added
        // to baseRow further below) is admin-configured in USD. Both
        // get converted to SPUMP — this platform's canonical internal
        // currency — via the live rate. Pre-warm/verify that rate HERE,
        // before the slow/irreversible image upload in Step 1 below:
        // failing fast here is cheap, failing after the image is
        // already on disk is not. Because getSpumpUsdcRate() caches for
        // 60s, this same lookup is what the storage-fee conversion a
        // few lines later will reuse, so it isn't fetched twice.
        if (!$this->solana->getSpumpUsdcRate()) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to price this mint right now (rate unavailable). Please try again shortly.',
            ], 503);
        }

        $mintPrice = 0; // SPUMP — stays 0 for free listings
        if (!$isFree) {
            $mintPriceSol = (float) PlatformSetting::get('mint_price', 0.05);
            $mintPrice    = $this->solana->convertSolToSpump($mintPriceSol);
            if ($mintPrice === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to price this mint right now (SOL rate unavailable). Please try again shortly.',
                ], 503);
            }
        }
        $hasMintDiscount = !$isFree && (float) PlatformSetting::get('mint_discount_percent', 0) > 0;
        $discountPercent = $hasMintDiscount ? (float) PlatformSetting::get('mint_discount_percent', 0) : 0;
        $networkFee      = self::NETWORK_FEE_SOL;

        if ($isFree) {
            $mintPrice       = 0;
            $discountPercent = 0;
            $priceAfter      = 0;
        } elseif ($hasMintDiscount && $discountPercent > 0) {
            $priceAfter = $mintPrice - ($mintPrice * $discountPercent / 100);
        } else {
            $priceAfter = $mintPrice;
        }

        $hasBuyerDiscount     = (bool) PlatformSetting::get('buyer_discount_percent', 0) > 0;
        $buyerDiscountPercent = $hasBuyerDiscount ? (float) PlatformSetting::get('buyer_discount_percent', 0) : 0;
        // buyer_discount_max_uses has no matching PlatformSetting key
        // in publicConfig() currently — kept as a per-drop admin input
        // for now (not attacker-exploitable on its own: it only limits
        // how many buyers get the discount, it can't change what
        // anyone actually pays), but flagged here since every OTHER
        // discount field above is centrally controlled.
        $buyerDiscountMaxUses = $request->buyer_discount_max_uses;

        $totalCost = $priceAfter + $networkFee;

        try {
            // ── Step 1: Image → local disk ───────────
            $imageResult = $this->storage->uploadImage(
                $request->file('image'),
                $request->name
            );

            // ── Step 2: Metadata Build ───────────────
            $metadata = [
                'name'        => $request->name,
                'symbol'      => $request->symbol ?? 'NFT',
                'description' => $request->description,
                'image'       => $imageResult['url'],
                'seller_fee_basis_points' => (int)(($request->royalty ?? 5) * 100),
                'attributes'  => $request->attributes ?? [],
                'properties'  => [
                    'files'    => [['uri' => $imageResult['url'], 'type' => 'image/png']],
                    'category' => $request->category,
                    'edition'  => $request->edition_type,
                ],
                'collection' => $request->collection_id
                    ? ['id' => $request->collection_id]
                    : null,
            ];

            // ── Step 3: Metadata → local disk ────────
            $metadataResult = $this->storage->uploadMetadata($metadata);

            // Storage fee: admin-configured in USD, converted to SPUMP
            // live (same reasoning as mint_price above). The rate was
            // already verified reachable before the image upload, so
            // this should only fail here if it changed availability in
            // the last few seconds — checked anyway rather than
            // silently storing a wrong (null→0) fee.
            $storageFeeSpump = $this->storageFee->calculateAnnualFeeSpump($imageResult['size_bytes']);
            if ($storageFeeSpump === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to finalize storage pricing (rate unavailable). Please try again.',
                ], 503);
            }

            //Step 4: DB Save 
            // Edition / Total Supply fix: a "limited" edition of N must
            // actually produce N independently-mintable rows (each will
            // become its own on-chain token), not one row that gets
            // marked "minted" and stops there. All N share the same
            // artwork/metadata (uploaded once above) and an
            // edition_group_id so supply can be tracked reliably —
            // "how many rows in this group are minted" instead of a
            // separate counter that can drift out of sync.
            $editionType  = $request->edition_type;
            $totalCopies  = $editionType === 'limited' ? max((int) $request->total_supply, 1) : 1;
            $editionGroupId = (string) \Illuminate\Support\Str::uuid();

            $baseRow = [
                'name'                   => $request->name,
                'description'            => $request->description,
                'symbol'                 => $request->symbol ?? 'NFT',
                'image_url'              => $imageResult['url'],
                'image_hash'             => $imageResult['ipfs_hash'],
                'image_size_bytes'       => $imageResult['size_bytes'],
                'storage_fee_spump'      => $storageFeeSpump,
                'metadata_uri'           => $metadataResult['metadata_uri'],
                'metadata_hash'          => $metadataResult['ipfs_hash'],
                'collection_id'          => $request->collection_id,
                'category'               => $request->category,
                'edition_type'           => $editionType,
                'total_supply'           => $editionType === 'limited' ? $totalCopies : null,
                'edition_group_id'       => $editionGroupId,
                'mint_price'             => $mintPrice,
                'is_free_listing'        => $isFree,
                'has_mint_discount'      => $hasMintDiscount,
                'mint_discount_percent'  => $discountPercent,
                'price_after_discount'   => $priceAfter,
                'has_buyer_discount'     => $hasBuyerDiscount,
                'buyer_discount_percent' => $buyerDiscountPercent,
                'buyer_discount_max_uses'=> $buyerDiscountMaxUses,
                'royalty'                => $request->royalty ?? 5,
                'network'                => $this->solana->getNetwork(),
                'network_fee'            => $networkFee,
                'wallet_address'         => $request->wallet_address,
                'creator_wallet'         => $request->wallet_address,
                'attributes'             => json_encode($request->attributes ?? []),
                'external_website'       => $request->external_website,
                'external_social'        => $request->external_social,
                'external_twitter'       => $request->external_twitter,
                'external_sosay'         => $request->external_sosay,
                'external_telegram'      => $request->external_telegram,
                'external_whatsapp'      => $request->external_whatsapp,
                'unlockable_content'     => $request->unlockable_content,
                'is_explicit'            => (bool) $request->is_explicit,
                'status'                 => 'pending',
                'created_at'             => now(),
                'updated_at'             => now(),
            ];

            $rows = [];
            for ($i = 1; $i <= $totalCopies; $i++) {
                $rows[] = array_merge($baseRow, ['edition_number' => $i]);
            }

            // Bulk-insert in chunks (fast even for large editions, e.g. 100000)
            foreach (array_chunk($rows, 500) as $chunk) {
                \DB::table('nfts')->insert($chunk);
            }

            $nftIds = Nft::where('edition_group_id', $editionGroupId)
                ->orderBy('edition_number')
                ->pluck('id')
                ->values();

            return response()->json([
                'success' => true,
                'message' => $totalCopies > 1
                    ? "NFT ready to mint! {$totalCopies} copies created — mint them one by one. 🎉"
                    : 'NFT ready to mint! 🎉',
                'data'    => [
                    'nft_id'           => $nftIds->first(),
                    'nft_ids'          => $nftIds,
                    'edition_group_id' => $editionGroupId,
                    'total_supply'     => $editionType === 'limited' ? $totalCopies : null,
                    'metadata_uri'     => $metadataResult['metadata_uri'],
                    'image_url'        => $imageResult['url'],
                    'wallet'           => $request->wallet_address,
                    'network'          => $this->solana->getNetwork(),
                    'pricing'          => [
                        // Informational only — the frontend never lets a
                        // user pick SOL as a payment currency, this just
                        // shows the admin-configured base the SPUMP
                        // figure below was converted from.
                        'mint_price_sol'       => $isFree ? 0 : (float) PlatformSetting::get('mint_price', 0.05),
                        'mint_price'           => $mintPrice . ' SPUMP',
                        'discount'             => $discountPercent . '%',
                        'price_after_discount' => $priceAfter . ' SPUMP',
                        'network_fee'          => $networkFee . ' SOL',
                        'total_cost'           => $totalCost . ' SPUMP + ' . $networkFee . ' SOL',
                        'is_free'              => $isFree,
                    ],
                    'next_step' => 'Use metadata_uri to mint via Phantom Wallet',
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'NFT creation failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mint Confirm — Frontend mint after call 
     * POST /api/nft/mint
     */
    // public function mint(Request $request): JsonResponse
    // {
    //     $request->validate([
    //         'nft_id'          => 'required|exists:nfts,id',
    //         'mint_address'    => 'required|string',
    //         'transaction_sig' => 'required|string',
    //         'wallet_address'  => 'required|string',
    //     ]);
    //      \Log::info('Mint Request', [
    //     'signature' => $request->transaction_sig,
    //     'mint'      => $request->mint_address,
    //     ]);

    //     try {
    //         $transaction = $this->solana->getTransaction($request->transaction_sig);

    //         if (!$transaction) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'Transaction not confirmed yet. Please wait.',
    //             ], 422);
    //         }

    //         $nft = Nft::findOrFail($request->nft_id);
    //         $nft->update([
    //             'mint_address'    => $request->mint_address,
    //             'transaction_sig' => $request->transaction_sig,
    //             'status'          => 'minted',
    //             'minted_at'       => now(),
    //             'minted_count'    => 1,
    //         ]);

    //         return response()->json([
    //             'success' => true,
    //             'message' => 'NFT minted successfully! ✅',
    //             'data'    => [
    //                 'mint_address' => $request->mint_address,
    //                 'transaction'  => $request->transaction_sig,
    //                 'explorer_url' => $this->solana->getExplorerUrl($request->mint_address),
    //                 'wallet'       => $request->wallet_address,
    //             ],
    //         ]);

    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => $e->getMessage(),
    //         ], 500);
    //     }
    // }

    public function mint(Request $request): JsonResponse
    {
        $request->validate([
            'nft_id'          => 'required|exists:nfts,id',
            'mint_address'    => 'required|string',
            'transaction_sig' => 'required|string',
            'wallet_address'  => 'required|string',
            'payment_currency'=> 'nullable|string|in:spump,usdc',
        ]);

        \Log::info('Mint confirm request received', [
            'nft_id'          => $request->nft_id,
            'mint_address'    => $request->mint_address,
            'transaction_sig' => $request->transaction_sig,
        ]);

        try {
            // Primary check: full transaction lookup (has retry built in)
            $transaction = $this->solana->getTransaction($request->transaction_sig);

            // Fallback check: lighter-weight signature status lookup.
            // Some RPC providers index getTransaction slower than
            // getSignatureStatuses, so this catches cases where the
            // primary check still misses despite retries.
            $verified = $transaction !== null
                || $this->solana->isSignatureConfirmed($request->transaction_sig);

            if (!$verified) {
                \Log::warning('Mint confirm failed verification', [
                    'transaction_sig' => $request->transaction_sig,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Transaction not confirmed yet. Please wait a moment and try again.',
                ], 422);
            }

            $nft = Nft::findOrFail($request->nft_id);

            if ($nft->status === 'minted') {
                return response()->json([
                    'success' => false,
                    'message' => 'This NFT copy has already been minted.',
                ], 422);
            }

            // ── Payment Verification ─────────────────────────────
            // Confirming the signature landed on-chain is NOT enough —
            // it proves *a* transaction happened, not that this one
            // actually paid what's owed to the platform. What's owed is
            // mint price (0 if free listing) PLUS one year of storage
            // fee — storage fee is NEVER waived by free_listing, since
            // it's a real hosting cost (see LocalStorageService) rather
            // than a promotional mint price. Both are billed together
            // in a single payment, converted to USDC via the live rate
            // if the minter chose to pay that way.
            $mintPriceComponent   = (float) $nft->price_after_discount;
            $storageFeeComponent  = (float) ($nft->storage_fee_spump ?? 0);
            $expectedAmountSpump  = round($mintPriceComponent + $storageFeeComponent, 9);

            if ($expectedAmountSpump > 0) {
                $treasuryWallet   = $this->solana->getTreasuryWallet();
                $paymentCurrency  = $request->input('payment_currency', 'spump');
                $expectedAmount   = $expectedAmountSpump; // in SPUMP
                $tolerance        = 0.0005;

                if ($paymentCurrency === 'usdc') {
                    $expectedAmount = $this->storageFee->spumpToUsdc($expectedAmount);
                    if ($expectedAmount === null) {
                        \Log::error('Mint confirm blocked: SPUMP/USDC rate unavailable', ['nft_id' => $nft->id]);
                        return response()->json([
                            'success' => false,
                            'message' => 'Unable to verify payment right now (rate unavailable). Please try again shortly.',
                        ], 422);
                    }
                    $tolerance = max($expectedAmount * 0.05, $tolerance);
                }

                $mintAddress = $paymentCurrency === 'usdc'
                    ? config('services.tokens.usdc_mint')
                    : config('services.tokens.spump_mint');

                if (!$treasuryWallet || !$mintAddress) {
                    \Log::error('Mint confirm blocked: platform wallet or payment token mint not configured');
                    return response()->json([
                        'success' => false,
                        'message' => 'Platform payment is not configured. Please contact support.',
                    ], 500);
                }

                $paid = $this->solana->verifyTokenPayment(
                    $request->transaction_sig,
                    $mintAddress,
                    $treasuryWallet,
                    $expectedAmount,
                    $tolerance
                );

                if (!$paid) {
                    \Log::warning('Mint confirm blocked: payment not found in transaction', [
                        'nft_id'              => $nft->id,
                        'transaction_sig'     => $request->transaction_sig,
                        'payment_currency'    => $paymentCurrency,
                        'expected_amount'     => $expectedAmount,
                        'mint_price_spump'    => $mintPriceComponent,
                        'storage_fee_spump'   => $storageFeeComponent,
                        'treasury_wallet'     => $treasuryWallet,
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Payment not found in this transaction. Expected ' . $expectedAmount . ' ' . strtoupper($paymentCurrency) . ' to the platform wallet (mint price + 1 year storage fee).',
                    ], 422);
                }
            }

            $nft->update([
                'mint_address'       => $request->mint_address,
                'transaction_sig'    => $request->transaction_sig,
                'status'             => 'minted',
                'minted_at'          => now(),
                'minted_count'       => 1,
                'storage_paid_until' => now()->addYear(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'NFT minted successfully! ✅',
                'data'    => [
                    'mint_address'        => $request->mint_address,
                    'transaction'         => $request->transaction_sig,
                    'explorer_url'        => $this->solana->getExplorerUrl($request->mint_address),
                    'wallet'              => $request->wallet_address,
                    'storage_paid_until'  => $nft->fresh()->storage_paid_until,
                ],
            ]);

        } 
        // catch (\Exception $e) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => $e->getMessage(),
        //     ], 500);
        // }

        catch (\Exception $e) {

            $error = $e->getMessage();

            if (
                str_contains($error, 'Attempt to debit an account') ||
                str_contains($error, 'Insufficient funds')
            ) {
                $message = 'Your wallet does not have enough SOL balance.';
            } elseif (str_contains($error, 'Blockhash not found')) {
                $message = 'Transaction expired. Please try again.';
            } elseif (str_contains($error, 'User rejected')) {
                $message = 'Transaction cancelled by user.';
            } else {
                $message = 'NFT minting failed. Please try again.';
            }

            \Log::error('Mint Error', [
                'actual_error' => $error,
            ]);

            return response()->json([
                'success' => false,
                'message' => $message,
            ], 422);
        }
    }

    /**
     * Renew storage — pays for another year of hosting this NFT's
     * image/metadata. Only the CURRENT owner can renew (wallet_address
     * tracks current owner across sales — see BuyController), matching
     * the "responsibility transfers to whoever owns it now" rule.
     * Extends from the existing expiry if not yet expired (so renewing
     * early never loses paid-for time), or from now() if it already
     * lapsed.
     * POST /api/nft/{id}/storage/renew
     */
    public function renewStorage(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'wallet_address'   => 'required|string',
            'transaction_sig'  => 'required|string',
            'payment_currency' => 'nullable|string|in:spump,usdc',
        ]);

        $nft = Nft::findOrFail($id);

        if ($nft->wallet_address !== $request->wallet_address) {
            return response()->json([
                'success' => false,
                'message' => 'Only the current owner can renew storage for this NFT.',
            ], 403);
        }

        if (!$nft->image_size_bytes) {
            return response()->json([
                'success' => false,
                'message' => 'This NFT has no tracked image size — storage fee cannot be calculated. Please contact support.',
            ], 422);
        }

        try {
            $verified = $this->solana->getTransaction($request->transaction_sig) !== null
                || $this->solana->isSignatureConfirmed($request->transaction_sig);

            if (!$verified) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction not confirmed yet. Please wait a moment and try again.',
                ], 422);
            }

            // Recalculated fresh from current admin rate (not the
            // mint-time cached value) — a renewal is a new purchase of
            // another year at whatever the rate is now.
            $renewalFeeSpump = $this->storageFee->calculateAnnualFeeSpump($nft->image_size_bytes);
            if ($renewalFeeSpump === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to price storage renewal right now (rate unavailable). Please try again shortly.',
                ], 422);
            }

            $treasuryWallet  = $this->solana->getTreasuryWallet();
            $paymentCurrency = $request->input('payment_currency', 'spump');
            $expectedAmount  = $renewalFeeSpump;
            $tolerance       = 0.0005;

            if ($paymentCurrency === 'usdc') {
                $expectedAmount = $this->storageFee->spumpToUsdc($expectedAmount);
                if ($expectedAmount === null) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unable to verify payment right now (rate unavailable). Please try again shortly.',
                    ], 422);
                }
                $tolerance = max($expectedAmount * 0.05, $tolerance);
            }

            $mintAddress = $paymentCurrency === 'usdc'
                ? config('services.tokens.usdc_mint')
                : config('services.tokens.spump_mint');

            if (!$treasuryWallet || !$mintAddress) {
                return response()->json([
                    'success' => false,
                    'message' => 'Platform payment is not configured. Please contact support.',
                ], 500);
            }

            $paid = $this->solana->verifyTokenPayment(
                $request->transaction_sig,
                $mintAddress,
                $treasuryWallet,
                $expectedAmount,
                $tolerance
            );

            if (!$paid) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment not found in this transaction. Expected ' . $expectedAmount . ' ' . strtoupper($paymentCurrency) . ' to the platform wallet.',
                ], 422);
            }

            $extendsFrom = ($nft->storage_paid_until && $nft->storage_paid_until->isFuture())
                ? $nft->storage_paid_until
                : now();

            $nft->update([
                'storage_fee_spump'  => $renewalFeeSpump,
                'storage_warning_sent_at' => null,
                'storage_paid_until' => $extendsFrom->copy()->addYear(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Storage renewed for another year! ✅',
                'data'    => [
                    'storage_paid_until' => $nft->fresh()->storage_paid_until,
                    'renewal_fee_spump'  => $renewalFeeSpump,
                ],
            ]);

        } catch (\Exception $e) {
            \Log::error('Storage renewal error', ['actual_error' => $e->getMessage(), 'nft_id' => $id]);
            return response()->json([
                'success' => false,
                'message' => 'Storage renewal failed. Please try again.',
            ], 500);
        }
    }

    /**
     * Real-time Price Calculation
     * POST /api/nft/calculate
     */
    public function calculate(Request $request): JsonResponse
    {
        $request->validate([
            'mint_price'            => 'required|numeric|min:0',
            'is_free_listing'       => 'boolean',
            'has_mint_discount'     => 'boolean',
            'mint_discount_percent' => 'nullable|numeric|min:0|max:90',
        ]);

        $mintPrice       = (float) $request->mint_price;
        $isFree          = (bool)  $request->is_free_listing;
        $hasMintDiscount = (bool)  $request->has_mint_discount;
        $discount        = (float) ($request->mint_discount_percent ?? 0);
        $networkFee      = self::NETWORK_FEE_SOL;

        if ($isFree) {
            $priceAfter = 0;
            $discount   = 0;
        } elseif ($hasMintDiscount && $discount > 0) {
            $priceAfter = $mintPrice - ($mintPrice * $discount / 100);
        } else {
            $priceAfter = $mintPrice;
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'mint_price'           => round($mintPrice, 9) . ' SPUMP',
                'discount_percent'     => $discount . '%',
                'discount_amount'      => round($mintPrice * $discount / 100, 9) . ' SPUMP',
                'price_after_discount' => round($priceAfter, 9) . ' SPUMP',
                // Network fee is always paid in SOL (a Solana protocol
                // requirement, independent of what currency the mint
                // price itself is set in) — kept separate rather than
                // summed into price_after_discount, since SPUMP and SOL
                // are different tokens and can't be added together.
                'network_fee'          => $networkFee . ' SOL',
                'is_free'              => $isFree,
            ],
        ]);
    }

    /**
     * Collections List
     * GET /api/collections
     */
    public function collections(Request $request): JsonResponse
    {
        $wallet = $request->query('wallet');
        $query  = Collection::latest();
        if ($wallet) {
            $query->where('wallet_address', $wallet);
        }

        return response()->json([
            'success' => true,
            'data'    => $query->get(),
        ]);
    }

    /**
     * Wallet-এর NFT List
     * GET /api/nft/list/{wallet}
     */
    public function listByWallet(string $wallet): JsonResponse
    {
        if (!$this->solana->isValidWalletAddress($wallet)) {
            return response()->json(['success' => false, 'message' => 'Invalid wallet address'], 422);
        }

        $nfts = Nft::where('wallet_address', $wallet)
            ->where('status', 'minted')
            ->with('collection')
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $nfts,
            'total'   => $nfts->count(),
        ]);
    }

    /**
     * Edition Supply — live count of how many copies exist / are minted
     * GET /api/nft/edition/{edition_group_id}/supply
     */
    public function editionSupply(string $editionGroupId): JsonResponse
    {
        $total  = Nft::where('edition_group_id', $editionGroupId)->count();

        if ($total === 0) {
            return response()->json(['success' => false, 'message' => 'Edition not found.'], 404);
        }

        $minted = Nft::where('edition_group_id', $editionGroupId)->where('status', 'minted')->count();

        return response()->json([
            'success' => true,
            'data'    => [
                'edition_group_id' => $editionGroupId,
                'total_supply'     => $total,
                'minted_count'     => $minted,
                'remaining'        => max($total - $minted, 0),
                'sold_out'         => $minted >= $total,
            ],
        ]);
    }

    /**
     * Single NFT Details
     * GET /api/nft/{mint_address}
     */
    public function show(Request $request,string $mintAddress): JsonResponse
    {
        $nft = Nft::where('mint_address', $mintAddress)
            ->with('collection')
            ->firstOrFail();
        
        $data = $nft->toArray(); // unlockable_content excluded here ($hidden)
        unset($data['unlockable_content']);
 
        // Only the current owner ever sees unlockable content — pass
        // ?viewer_wallet=<wallet> to check. wallet_address tracks the
        // current owner (updated on every sale in BuyController), so
        // this stays correct after resales without extra lookups.
        if ($request->viewer_wallet && $nft->wallet_address === $request->viewer_wallet) {
            $data['unlockable_content'] = $nft->unlockable_content;
        }
 
 
        return response()->json([
            'success' => true,
            'data'    => array_merge($data, [
                'explorer_url' => $this->solana->getExplorerUrl($mintAddress),
            ]),
        ]);
    }

    /**
     * Import Preview
     * POST /api/nft/import/preview
     *
     * Given a mint address and the wallet claiming to own it, fetches
     * the NFT's on-chain + off-chain metadata (auto-detecting mpl-core
     * vs legacy Token Metadata) and confirms that wallet currently
     * holds it. Read-only — nothing is saved yet.
     */
    public function importPreview(Request $request): JsonResponse
    {
        $request->validate([
            'mint_address'   => 'required|string',
            'wallet_address' => 'required|string',
        ]);

        if (Nft::where('mint_address', $request->mint_address)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This NFT is already on the platform.',
            ], 422);
        }

        $result = $this->signer->fetchExternalNft($request->mint_address);

        if (!($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Could not find or read this NFT on-chain.',
            ], 422);
        }

        if (!$result['current_owner']) {
            return response()->json([
                'success' => false,
                'message' => 'Could not determine the current owner of this NFT.',
            ], 422);
        }

        if ($result['current_owner'] !== $request->wallet_address) {
            return response()->json([
                'success' => false,
                'message' => 'This wallet does not currently hold this NFT.',
            ], 403);
        }

        return response()->json(['success' => true, 'data' => $result]);
    }

    /**
     * Import
     * POST /api/nft/import
     *
     * Re-verifies ownership (never trust a client-supplied claim from a
     * moment ago — re-check right before writing to the DB) and creates
     * a platform record for this externally-minted NFT so it can be
     * listed on the marketplace like any other.
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'mint_address'   => 'required|string',
            'wallet_address' => 'required|string',
            'category'       => 'nullable|string|in:' . implode(',', self::CATEGORIES),
        ]);

        if (Nft::where('mint_address', $request->mint_address)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This NFT is already on the platform.',
            ], 422);
        }

        $result = $this->signer->fetchExternalNft($request->mint_address);

        if (!($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Could not find or read this NFT on-chain.',
            ], 422);
        }

        if (($result['current_owner'] ?? null) !== $request->wallet_address) {
            return response()->json([
                'success' => false,
                'message' => 'This wallet does not currently hold this NFT.',
            ], 403);
        }

        $creators     = $result['creators'] ?? [];
        $creatorWallet = $creators[0]['address'] ?? $result['update_authority'] ?? $request->wallet_address;

        $nft = Nft::create([
            'wallet_address'  => $request->wallet_address,
            'creator_wallet'  => $creatorWallet,
            'name'            => $result['name'] ?? 'Untitled',
            'description'     => $result['description'] ?? '',
            'image_url'       => $result['image_url'] ?? null,
            'metadata_uri'    => $result['uri'] ?? null,
            'mint_address'    => $request->mint_address,
            'royalty'         => round(($result['seller_fee_basis_points'] ?? 0) / 100, 2),
            'category'        => $request->input('category', 'other'),
            'status'          => 'minted',
            'minted_at'       => now(),
            'minted_count'    => 1,
            'token_standard'  => $result['token_standard'],
            'source'          => 'imported',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'NFT imported successfully! You can now list it on the marketplace.',
            'data'    => array_merge($nft->toArray(), [
                'explorer_url' => $this->solana->getExplorerUrl($request->mint_address),
            ]),
        ]);
    }
}
