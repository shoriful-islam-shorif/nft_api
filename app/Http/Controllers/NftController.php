<?php

namespace App\Http\Controllers;

use App\Models\Nft;
use App\Models\Collection;
use App\Models\PlatformSetting;
use App\Services\PinataService;
use App\Services\SolanaService;
use App\Services\SolanaSignerService;
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
        private PinataService $pinata,
        private SolanaService $solana,
        private SolanaSignerService $signer
    ) {}

    /**
     * NFT Create
     * POST /api/nft/create
     * Wallet connect to  — account 
     */
    public function create(Request $request): JsonResponse
    {
        // // ── Validation ───────────────────────────────
        // $request->validate([
        //     // Wallet (Identity)
        //     'wallet_address'          => 'required|string',

        //     // Basic Info
        //     'name'                    => 'required|string|max:100',
        //     'description'             => 'required|string|max:1000',
        //     'symbol'                  => 'nullable|string|max:10',
        //     'image'                   => 'required|file|mimes:jpg,jpeg,png,gif,webp|max:10240',

        //     // Collection
        //     'collection_id'           => 'nullable|exists:collections,id',
        //     'category'                => 'required|in:' . implode(',', self::CATEGORIES),

        //     // Supply
        //     'edition_type'            => 'required|in:unlimited,limited',
        //     'total_supply'            => 'required_if:edition_type,limited|nullable|integer|min:1|max:100000',

        //     // Pricing
        //     'mint_price'              => 'required|numeric|min:0',
        //     'is_free_listing'         => 'boolean',

        //     // Mint Discount
        //     'has_mint_discount'       => 'boolean',
        //     'mint_discount_percent'   => 'required_if:has_mint_discount,true|nullable|numeric|min:1|max:90',

        //     // Buyer Discount
        //     'has_buyer_discount'      => 'boolean',
        //     'buyer_discount_percent'  => 'required_if:has_buyer_discount,true|nullable|numeric|min:1|max:90',
        //     'buyer_discount_max_uses' => 'nullable|integer|min:1',

        //     // Royalty & Attributes
        //     'royalty'                 => 'nullable|numeric|min:0|max:50',
        //     'attributes'              => 'nullable|array',
        //     'attributes.*.trait_type' => 'required|string',
        //     'attributes.*.value'      => 'required|string',
        // ]);

        $validator = \Validator::make($request->all(), [
        'wallet_address'          => 'required|string',
        'name'                    => 'required|string|max:100',
        'description'             => 'required|string|max:1000',
        'symbol'                  => 'nullable|string|max:10',
        'image'                   => 'required|file|image|mimes:jpg,jpeg,png,gif,webp|max:10240|dimensions:max_width=8000,max_height=8000',

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
        $isFree          = (bool)  PlatformSetting::get('is_free_listing', true);
        $mintPrice       = $isFree ? 0 : (float) PlatformSetting::get('mint_price', 5000);
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
            // ── Step 1: Image → Pinata ───────────────
            $imageResult = $this->pinata->uploadImage(
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

            // ── Step 3: Metadata → Pinata ────────────
            $metadataResult = $this->pinata->uploadMetadata($metadata);

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
            // actually paid the mint price to the platform. The admin
            // sets mint_price in SPUMP (the reference currency), but
            // the minter can pay in either SPUMP or USDC — convert via
            // the live rate if they chose USDC.
            if (!$nft->is_free_listing && (float) $nft->price_after_discount > 0) {
                $treasuryWallet   = $this->solana->getTreasuryWallet();
                $paymentCurrency  = $request->input('payment_currency', 'spump');
                $expectedAmount   = (float) $nft->price_after_discount; // in SPUMP
                $tolerance        = 0.0005;

                if ($paymentCurrency === 'usdc') {
                    $rateData = $this->solana->getSpumpUsdcRate();
                    if (!$rateData) {
                        \Log::error('Mint confirm blocked: SPUMP/USDC rate unavailable', ['nft_id' => $nft->id]);
                        return response()->json([
                            'success' => false,
                            'message' => 'Unable to verify payment right now (rate unavailable). Please try again shortly.',
                        ], 422);
                    }
                    // Listed/priced in SPUMP, minter paid in USDC.
                    $expectedAmount = $expectedAmount / (float) $rateData['spump_per_usdc'];
                    $tolerance      = max($expectedAmount * 0.05, $tolerance);
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
                        'nft_id'           => $nft->id,
                        'transaction_sig'  => $request->transaction_sig,
                        'payment_currency' => $paymentCurrency,
                        'expected_amount'  => $expectedAmount,
                        'treasury_wallet'  => $treasuryWallet,
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Payment not found in this transaction. Expected ' . $expectedAmount . ' ' . strtoupper($paymentCurrency) . ' to the platform wallet.',
                    ], 422);
                }
            }

            $nft->update([
                'mint_address'    => $request->mint_address,
                'transaction_sig' => $request->transaction_sig,
                'status'          => 'minted',
                'minted_at'       => now(),
                'minted_count'    => 1,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'NFT minted successfully! ✅',
                'data'    => [
                    'mint_address' => $request->mint_address,
                    'transaction'  => $request->transaction_sig,
                    'explorer_url' => $this->solana->getExplorerUrl($request->mint_address),
                    'wallet'       => $request->wallet_address,
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
    public function show(string $mintAddress): JsonResponse
    {
        $nft = Nft::where('mint_address', $mintAddress)
            ->with('collection')
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data'    => array_merge($nft->toArray(), [
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
