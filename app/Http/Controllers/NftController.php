<?php

namespace App\Http\Controllers;

use App\Models\Nft;
use App\Models\Collection;
use App\Services\PinataService;
use App\Services\SolanaService;
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
        private SolanaService $solana
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
        'image'                   => 'required|file|mimes:jpg,jpeg,png,gif,webp|max:10240',

        'collection_id'           => 'nullable|exists:collections,id',
        'category'                => 'required|in:' . implode(',', self::CATEGORIES),

        'edition_type'            => 'required|in:unlimited,limited',
        'total_supply'            => 'required_if:edition_type,limited|nullable|integer|min:1|max:100000',

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
        $mintPrice       = (float) $request->mint_price;
        $isFree          = (bool)  $request->is_free_listing;
        $hasMintDiscount = (bool)  $request->has_mint_discount;
        $discountPercent = (float) ($request->mint_discount_percent ?? 0);
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

            // ── Step 4: DB Save ──────────────────────
            $nft = Nft::create([
                'name'                   => $request->name,
                'description'            => $request->description,
                'symbol'                 => $request->symbol ?? 'NFT',
                'image_url'              => $imageResult['url'],
                'image_hash'             => $imageResult['ipfs_hash'],
                'metadata_uri'           => $metadataResult['metadata_uri'],
                'metadata_hash'          => $metadataResult['ipfs_hash'],
                'collection_id'          => $request->collection_id,
                'category'               => $request->category,
                'edition_type'           => $request->edition_type,
                'total_supply'           => $request->edition_type === 'limited' ? $request->total_supply : null,
                'mint_price'             => $mintPrice,
                'is_free_listing'        => $isFree,
                'has_mint_discount'      => $hasMintDiscount,
                'mint_discount_percent'  => $discountPercent,
                'price_after_discount'   => $priceAfter,
                'has_buyer_discount'     => (bool) $request->has_buyer_discount,
                'buyer_discount_percent' => $request->buyer_discount_percent ?? 0,
                'buyer_discount_max_uses'=> $request->buyer_discount_max_uses,
                'royalty'                => $request->royalty ?? 5,
                'attributes' => $request->input('attributes', []),
                'network'                => $this->solana->getNetwork(),
                'network_fee'            => $networkFee,
                'wallet_address'         => $request->wallet_address,
                'creator_wallet'  => $request->wallet_address,
                'status'                 => 'pending',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'NFT ready to mint! 🎉',
                'data'    => [
                    'nft_id'       => $nft->id,
                    'metadata_uri' => $metadataResult['metadata_uri'],
                    'image_url'    => $imageResult['url'],
                    'wallet'       => $request->wallet_address,
                    'network'      => $this->solana->getNetwork(),
                    'pricing'      => [
                        'mint_price'           => $mintPrice . ' SOL',
                        'discount'             => $discountPercent . '%',
                        'price_after_discount' => $priceAfter . ' SOL',
                        'network_fee'          => $networkFee . ' SOL',
                        'total_cost'           => $totalCost . ' SOL',
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

            // ── Payment Verification ─────────────────────────────
            // Confirming the signature landed on-chain is NOT enough —
            // it proves *a* transaction happened, not that this one
            // actually paid the mint price to the platform. Check the
            // treasury wallet's balance actually increased by the
            // expected amount inside this exact transaction.
            if (!$nft->is_free_listing && (float) $nft->price_after_discount > 0) {
                $treasuryWallet = config('services.platform.wallet');

                if (!$treasuryWallet) {
                    \Log::error('Mint confirm blocked: platform wallet not configured');
                    return response()->json([
                        'success' => false,
                        'message' => 'Platform wallet is not configured. Please contact support.',
                    ], 500);
                }

                $paid = $this->solana->verifyPayment(
                    $request->transaction_sig,
                    $treasuryWallet,
                    (float) $nft->price_after_discount
                );

                if (!$paid) {
                    \Log::warning('Mint confirm blocked: payment not found in transaction', [
                        'nft_id'          => $nft->id,
                        'transaction_sig' => $request->transaction_sig,
                        'expected_amount' => $nft->price_after_discount,
                        'treasury_wallet' => $treasuryWallet,
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Payment not found in this transaction. Expected ' . $nft->price_after_discount . ' SOL to the platform wallet.',
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
                'mint_price'           => round($mintPrice, 9) . ' SOL',
                'discount_percent'     => $discount . '%',
                'discount_amount'      => round($mintPrice * $discount / 100, 9) . ' SOL',
                'price_after_discount' => round($priceAfter, 9) . ' SOL',
                'network_fee'          => $networkFee . ' SOL',
                'total_cost'           => round($priceAfter + $networkFee, 9) . ' SOL',
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
}
