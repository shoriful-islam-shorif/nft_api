<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use App\Models\Nft;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\SolanaService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AdminController extends Controller
{
    private SolanaService $solana;

    public function __construct(SolanaService $solana)
    {
        $this->solana = $solana;
    }

    /**
     * sold_price is denominated in whatever list_currency was active
     * at sale time (list_currency itself is never cleared on sale).
     * Summing it across SPUMP and USDC rows would silently produce a
     * meaningless number, so volume/revenue are always grouped by
     * currency first — same fix as CollectionController.
     * Returns e.g. ['spump' => 45000, 'usdc' => 120].
     */
    private function volumeByCurrency($nfts): array
    {
        return $nfts
            ->groupBy(fn($n) => $n->list_currency ?: 'spump')
            ->map(fn($group) => (float) $group->sum('sold_price'))
            ->toArray();
    }

    private function revenueByCurrency(array $volumeByCurrency, float $feePercent): array
    {
        return array_map(fn($vol) => round($vol * ($feePercent / 100), 6), $volumeByCurrency);
    }

    private function checkAdmin(Request $request): ?JsonResponse
    {
        if (!$request->user() || !$request->user()->is_admin) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }
        return null;
    }

    // ── POST /api/admin/login ────────────────────────────────
    public function login(Request $request): JsonResponse
    {   
        $request->validate(['email' => 'required|email', 'password' => 'required']);

        if (!Auth::attempt(['email' => $request->email, 'password' => $request->password])) {
            return response()->json(['success' => false, 'message' => 'Invalid email or password.'], 401);
        }

        $user = Auth::user();
        if (!$user->is_admin) {
            Auth::logout();
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $user->tokens()->delete();
        $token = $user->createToken('admin-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'token'   => $token,
            'user'    => ['name' => $user->name, 'email' => $user->email],
        ]);
    }

    // ── POST /api/admin/logout ───────────────────────────────
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['success' => true, 'message' => 'Logged out.']);
    }

    // ── GET /api/admin/stats ─────────────────────────────────
    public function stats(Request $request): JsonResponse
    {
        if ($err = $this->checkAdmin($request)) return $err;

        $soldNfts    = Nft::whereNotNull('sold_to')->get(['list_currency', 'sold_price']);
        $feePercent  = (float) PlatformSetting::get('platform_fee_percent', 3);
        $volumes     = $this->volumeByCurrency($soldNfts);
        $revenues    = $this->revenueByCurrency($volumes, $feePercent);

        return response()->json([
            'success' => true,
            'data'    => [
                'total_nfts'     => Nft::count(),
                'minted_nfts'    => Nft::where('status', 'minted')->count(),
                'pending_nfts'   => Nft::where('status', 'pending')->count(),
                'listed_nfts'    => Nft::where('is_listed', true)->count(),
                'sold_nfts'      => Nft::whereNotNull('sold_to')->count(),
                'total_volume'   => $volumes,
                'total_revenue'  => $revenues,
                'total_collections' => Collection::count(),
                'recent_sales'   => Nft::whereNotNull('sold_to')->orderBy('sold_at', 'desc')->limit(5)
                    ->get(['id', 'name', 'image_url', 'sold_price', 'list_currency', 'wallet_address', 'sold_to', 'sold_at']),
                'category_stats' => Nft::where('status', 'minted')
                    ->selectRaw('category, COUNT(*) as total')
                    ->groupBy('category')->orderByDesc('total')->get(),
            ],
        ]);
    }

    // ── GET /api/admin/nfts ──────────────────────────────────
    public function nfts(Request $request): JsonResponse
    {
        if ($err = $this->checkAdmin($request)) return $err;

        $query = Nft::with('collection')->latest();
        if ($request->status)   $query->where('status', $request->status);
        if ($request->category) $query->where('category', $request->category);
        if ($request->search)   $query->where('name', 'like', '%'.$request->search.'%');
        if ($request->wallet)   $query->where('wallet_address', $request->wallet);

        return response()->json(['success' => true, 'data' => $query->paginate(20)]);
    }

    public function approveNft(Request $request, int $id): JsonResponse
    {
        if ($err = $this->checkAdmin($request)) return $err;

        // Deliberately not implemented as a force-approve. A "pending" NFT
        // has no mint_address yet — it's only set to "minted" once the real
        // on-chain mint transaction actually confirms (see NftController::
        // mint / POST /nft/mint). Flipping status here without that would
        // create a phantom "minted" record with no on-chain asset behind
        // it, breaking listing/marketplace/purchase for it later.
        return response()->json([
            'success' => false,
            'message' => 'NFTs can only become "minted" by confirming the real on-chain mint transaction — admin cannot force this.',
        ], 422);
    }

    public function rejectNft(Request $request, int $id): JsonResponse
    {
        if ($err = $this->checkAdmin($request)) return $err;
        Nft::findOrFail($id)->update(['status' => 'rejected', 'is_listed' => false]);
        return response()->json(['success' => true, 'message' => 'NFT rejected.']);
    }

    public function unlistNft(Request $request, int $id): JsonResponse
    {
        if ($err = $this->checkAdmin($request)) return $err;
        Nft::findOrFail($id)->update(['is_listed' => false, 'list_price' => null, 'listed_at' => null]);
        return response()->json(['success' => true, 'message' => 'NFT unlisted.']);
    }

    public function listNft(Request $request, int $id): JsonResponse
    {
        if ($err = $this->checkAdmin($request)) {
            return $err;
        }

        // Deliberately not implemented as a force-list. Listing an NFT for
        // sale requires the seller's own wallet to sign an on-chain
        // TransferDelegate approval (see the marketplace page's
        // grantTransferDelegate() + POST /marketplace/list) — that's what
        // actually gives the platform permission to move the asset later.
        // Admin holds no signing key for the seller's wallet, so flipping
        // `is_listed` here alone would produce a listing that LOOKS buyable
        // but always fails at purchase time with the mpl-core program
        // rejecting the transfer ("custom program error: 0x1a" / NoApprovals),
        // because the platform was never actually granted transfer authority
        // on-chain for that asset.
        return response()->json([
            'success' => false,
            'message' => 'NFTs can only be listed by their owner from the Marketplace page — this grants the platform on-chain transfer permission, which admin cannot do on the owner\'s behalf.',
        ], 422);
    }

    public function deleteNft(Request $request, int $id): JsonResponse
    {
        if ($err = $this->checkAdmin($request)) return $err;
        Nft::findOrFail($id)->delete();
        return response()->json(['success' => true, 'message' => 'NFT deleted.']);
    }

    // ── GET /api/admin/sales ─────────────────────────────────
    public function sales(Request $request): JsonResponse
    {
        if ($err = $this->checkAdmin($request)) return $err;

        $query = Nft::whereNotNull('sold_to')->orderBy('sold_at', 'desc');
        if ($request->from) $query->whereDate('sold_at', '>=', $request->from);
        if ($request->to)   $query->whereDate('sold_at', '<=', $request->to);

        $soldNfts   = Nft::whereNotNull('sold_to')->get(['list_currency', 'sold_price']);
        $feePercent = (float) PlatformSetting::get('platform_fee_percent', 3);
        $volumes    = $this->volumeByCurrency($soldNfts);
        $revenues   = $this->revenueByCurrency($volumes, $feePercent);

        return response()->json([
            'success' => true,
            'data'    => $query->paginate(20),
            'summary' => [
                'total_sales'   => Nft::whereNotNull('sold_to')->count(),
                'total_volume'  => $volumes,
                'total_revenue' => $revenues,
            ],
        ]);
    }

    // ── GET /api/admin/users ─────────────────────────────────
    public function users(Request $request): JsonResponse
    {
        if ($err = $this->checkAdmin($request)) return $err;

        $wallets = Nft::select('wallet_address')
            ->selectRaw('COUNT(*) as total_nfts')
            ->selectRaw('SUM(CASE WHEN is_listed = 1 THEN 1 ELSE 0 END) as listed_nfts')
            ->selectRaw('SUM(CASE WHEN sold_to IS NOT NULL THEN 1 ELSE 0 END) as sold_nfts')
            ->selectRaw('MAX(created_at) as last_activity')
            ->groupBy('wallet_address')
            ->orderByDesc('total_nfts')
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $wallets]);
    }

    // ── Collections ──────────────────────────────────────────
    // GET /api/admin/collections
    public function collections(Request $request): JsonResponse
    {
        if ($err = $this->checkAdmin($request)) return $err;

        $query = Collection::withCount('nfts')->latest();
        if ($request->search) $query->where('name', 'like', '%'.$request->search.'%');

        return response()->json(['success' => true, 'data' => $query->paginate(20)]);
    }

    // POST /api/admin/collections
    public function createCollection(Request $request): JsonResponse
    {
        if ($err = $this->checkAdmin($request)) return $err;

        $request->validate([
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'symbol'      => 'nullable|string|max:10',
        ]);

        $collection = Collection::create([
            'name'           => $request->name,
            'description'    => $request->description,
            'symbol'         => $request->symbol,
            // Admin-created collections aren't tied to a single
            // creator wallet the way the old creator-facing endpoint
            // was — the platform itself owns them.
            'wallet_address' => $request->input('wallet_address', 'admin'),
        ]);

        return response()->json(['success' => true, 'message' => 'Collection created.', 'data' => $collection]);
    }

    // DELETE /api/admin/collections/{id}
    public function deleteCollection(Request $request, int $id): JsonResponse
    {
        if ($err = $this->checkAdmin($request)) return $err;

        $col = Collection::withCount('nfts')->findOrFail($id);
        if ($col->nfts_count > 0) {
            return response()->json(['success' => false, 'message' => "Cannot delete — {$col->nfts_count} NFTs in this collection."], 422);
        }

        $col->delete();
        return response()->json(['success' => true, 'message' => 'Collection deleted.']);
    }

    // PUT /api/admin/collections/{id}
    public function updateCollection(Request $request, int $id): JsonResponse
    {
        if ($err = $this->checkAdmin($request)) return $err;

        $col = Collection::findOrFail($id);
        $col->update($request->only(['name', 'description', 'symbol']));

        return response()->json(['success' => true, 'message' => 'Collection updated.', 'data' => $col->fresh()]);
    }

    // ── Platform Settings ────────────────────────────────────
    // GET /api/admin/settings
    public function getSettings(Request $request): JsonResponse
    {
        if ($err = $this->checkAdmin($request)) return $err;

        $settings = PlatformSetting::orderBy('id')->get();
        return response()->json(['success' => true, 'data' => $settings]);
    }

    // POST /api/admin/settings
    public function updateSettings(Request $request): JsonResponse
    {
        if ($err = $this->checkAdmin($request)) return $err;

        $request->validate(['settings' => 'required|array']);

        foreach ($request->settings as $key => $value) {
            PlatformSetting::set($key, $value);
        }

        return response()->json(['success' => true, 'message' => 'Settings saved successfully!']);
    }

    // GET /api/config (public — frontend )
    public function publicConfig(): JsonResponse
    {
        $isFreeListing       = PlatformSetting::get('is_free_listing', true);
        // mint_price is now the SPUMP-denominated canonical price (admin
        // sets this directly in SPUMP) — there is no SOL mint price
        // anymore. USDC is derived live from the Jupiter SPUMP/USDC rate,
        // same source BuyController uses for purchase pricing.
        $mintPriceSpump      = (float) PlatformSetting::get('mint_price', 5000);
        $mintDiscountPercent = (float) PlatformSetting::get('mint_discount_percent', 15);

        $discountAmount     = $isFreeListing ? 0 : round($mintPriceSpump * ($mintDiscountPercent / 100), 6);
        $priceAfterDiscount = $isFreeListing ? 0 : round($mintPriceSpump - $discountAmount, 6);

        $rate = $this->solana->getSpumpUsdcRate();

        return response()->json([
            'success' => true,
            'data'    => [
                'platform_fee_percent'  => PlatformSetting::get('platform_fee_percent', 3),
                'is_free_listing'       => $isFreeListing,
                'mint_price_spump'      => $mintPriceSpump,
                'mint_discount_percent' => $mintDiscountPercent,
                'discount_amount'       => $discountAmount,
                'price_after_discount'  => $priceAfterDiscount,
                'buyer_discount_percent'=> PlatformSetting::get('buyer_discount_percent', 10),
                'spump_per_usdc'        => $rate['spump_per_usdc'] ?? null,
                'spump_mint'            => config('services.tokens.spump_mint'),
                'usdc_mint'             => config('services.tokens.usdc_mint'),
                'platform_wallet'       => PlatformSetting::get('platform_wallet', '') ?: config('services.platform.wallet', ''),
                'delegate_wallet'       => PlatformSetting::get('delegate_wallet', '') ?: config('services.platform.delegate_wallet', ''),
                'network'               => config('services.solana.network', 'devnet'),
            ],
        ]);
    }
}
