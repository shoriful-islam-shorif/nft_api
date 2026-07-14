<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use App\Models\Nft;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AdminController extends Controller
{
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

        $totalVolume  = Nft::whereNotNull('sold_to')->sum('list_price');

        return response()->json([
            'success' => true,
            'data'    => [
                'total_nfts'     => Nft::count(),
                'minted_nfts'    => Nft::where('status', 'minted')->count(),
                'pending_nfts'   => Nft::where('status', 'pending')->count(),
                'listed_nfts'    => Nft::where('is_listed', true)->count(),
                'sold_nfts'      => Nft::whereNotNull('sold_to')->count(),
                'total_volume'   => round($totalVolume, 6),
                'total_revenue'  => round($totalVolume * (PlatformSetting::get('platform_fee_percent', 3) / 100), 6),
                'total_collections' => Collection::count(),
                'recent_sales'   => Nft::whereNotNull('sold_to')->orderBy('sold_at', 'desc')->limit(5)
                    ->get(['id', 'name', 'image_url', 'list_price', 'wallet_address', 'sold_to', 'sold_at']),
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
        $nft = Nft::findOrFail($id);
        $nft->update(['status' => 'minted', 'minted_at' => now()]);
        return response()->json(['success' => true, 'message' => 'NFT approved.', 'data' => $nft->fresh()]);
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

        $nft = Nft::findOrFail($id);

        $nft->update([
            'is_listed' => true,
            'listed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'NFT listed successfully.',
        ]);
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

        $totalVolume = Nft::whereNotNull('sold_to')->sum('list_price');

        return response()->json([
            'success' => true,
            'data'    => $query->paginate(20),
            'summary' => [
                'total_sales'   => Nft::whereNotNull('sold_to')->count(),
                'total_volume'  => round($totalVolume, 6),
                'total_revenue' => round($totalVolume * (PlatformSetting::get('platform_fee_percent', 3) / 100), 6),
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

    // GET /api/config (public — frontend এর জন্য)
    public function publicConfig(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'platform_fee_percent'  => PlatformSetting::get('platform_fee_percent', 3),
                'is_free_listing'       => PlatformSetting::get('is_free_listing', true),
                'mint_discount_percent' => PlatformSetting::get('mint_discount_percent', 15),
                'buyer_discount_percent'=> PlatformSetting::get('buyer_discount_percent', 10),
                'spump_per_sol'         => PlatformSetting::get('spump_per_sol', 10000),
                'platform_wallet'       => PlatformSetting::get('platform_wallet', ''),
                'network'               => config('services.solana.network', 'devnet'),
            ],
        ]);
    }
}
