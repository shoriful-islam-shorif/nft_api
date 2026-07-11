<?php

namespace App\Http\Controllers;

use App\Models\Nft;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AdminController extends Controller
{
    // ── Admin check ─────────────────────────────────────────────
    private function checkAdmin(Request $request): ?JsonResponse
    {
        if (!$request->user() || !$request->user()->is_admin) {
            return response()->json(['success' => false, 'message' => 'Unauthorized. Admin access required.'], 403);
        }
        return null;
    }

    // ── POST /api/admin/login ────────────────────────────────────
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt(['email' => $request->email, 'password' => $request->password])) {
            return response()->json(['success' => false, 'message' => 'Invalid email or password.'], 401);
        }

        $user = Auth::user();

        if (!$user->is_admin) {
            Auth::logout();
            return response()->json(['success' => false, 'message' => 'Access denied. Admin only.'], 403);
        }

        // পুরনো token মুছে নতুন দাও
        $user->tokens()->delete();
        $token = $user->createToken('admin-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'token'   => $token,
            'user'    => [
                'name'  => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    // ── POST /api/admin/logout ───────────────────────────────────
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['success' => true, 'message' => 'Logged out successfully.']);
    }

    // ── GET /api/admin/stats ─────────────────────────────────────
    public function stats(Request $request): JsonResponse
    {
        if ($err = $this->checkAdmin($request)) return $err;

        $totalNfts   = Nft::count();
        $mintedNfts  = Nft::where('status', 'minted')->count();
        $pendingNfts = Nft::where('status', 'pending')->count();
        $listedNfts  = Nft::where('is_listed', true)->count();
        $soldNfts    = Nft::whereNotNull('sold_to')->count();
        $totalVolume = Nft::whereNotNull('sold_to')->sum('list_price');
        $totalRevenue = $totalVolume * 0.03;

        $recentSales = Nft::whereNotNull('sold_to')
            ->orderBy('sold_at', 'desc')
            ->limit(5)
            ->get(['id', 'name', 'image_url', 'list_price', 'wallet_address', 'sold_to', 'sold_at', 'category']);

        // Category wise stats
        $categoryStats = Nft::where('status', 'minted')
            ->selectRaw('category, COUNT(*) as total')
            ->groupBy('category')
            ->orderByDesc('total')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'total_nfts'     => $totalNfts,
                'minted_nfts'    => $mintedNfts,
                'pending_nfts'   => $pendingNfts,
                'listed_nfts'    => $listedNfts,
                'sold_nfts'      => $soldNfts,
                'total_volume'   => round($totalVolume, 6),
                'total_revenue'  => round($totalRevenue, 6),
                'recent_sales'   => $recentSales,
                'category_stats' => $categoryStats,
            ],
        ]);
    }

    // ── GET /api/admin/nfts ──────────────────────────────────────
    public function nfts(Request $request): JsonResponse
    {
        if ($err = $this->checkAdmin($request)) return $err;

        $query = Nft::with('collection')->latest();

        if ($request->status)   $query->where('status', $request->status);
        if ($request->category) $query->where('category', $request->category);
        if ($request->search)   $query->where('name', 'like', '%' . $request->search . '%');
        if ($request->wallet)   $query->where('wallet_address', $request->wallet);

        return response()->json([
            'success' => true,
            'data'    => $query->paginate(20),
        ]);
    }

    // ── POST /api/admin/nfts/{id}/approve ───────────────────────
    public function approveNft(Request $request, int $id): JsonResponse
    {
        if ($err = $this->checkAdmin($request)) return $err;

        $nft = Nft::findOrFail($id);
        $nft->update(['status' => 'minted', 'minted_at' => now()]);

        return response()->json(['success' => true, 'message' => 'NFT approved successfully.', 'data' => $nft->fresh()]);
    }

    // ── POST /api/admin/nfts/{id}/reject ────────────────────────
    public function rejectNft(Request $request, int $id): JsonResponse
    {
        if ($err = $this->checkAdmin($request)) return $err;

        $nft = Nft::findOrFail($id);
        $nft->update(['status' => 'rejected', 'is_listed' => false]);

        return response()->json(['success' => true, 'message' => 'NFT rejected.']);
    }

    // ── POST /api/admin/nfts/{id}/unlist ────────────────────────
    public function unlistNft(Request $request, int $id): JsonResponse
    {
        if ($err = $this->checkAdmin($request)) return $err;

        $nft = Nft::findOrFail($id);
        $nft->update(['is_listed' => false, 'list_price' => null, 'listed_at' => null]);

        return response()->json(['success' => true, 'message' => 'NFT unlisted successfully.']);
    }

    // ── DELETE /api/admin/nfts/{id} ─────────────────────────────
    public function deleteNft(Request $request, int $id): JsonResponse
    {
        if ($err = $this->checkAdmin($request)) return $err;

        Nft::findOrFail($id)->delete();

        return response()->json(['success' => true, 'message' => 'NFT deleted successfully.']);
    }

    // ── GET /api/admin/sales ─────────────────────────────────────
    public function sales(Request $request): JsonResponse
    {
        if ($err = $this->checkAdmin($request)) return $err;

        $query = Nft::whereNotNull('sold_to')->orderBy('sold_at', 'desc');

        if ($request->from) $query->whereDate('sold_at', '>=', $request->from);
        if ($request->to)   $query->whereDate('sold_at', '<=', $request->to);

        $sales        = $query->paginate(20);
        $totalVolume  = Nft::whereNotNull('sold_to')->sum('list_price');
        $totalRevenue = $totalVolume * 0.03;

        return response()->json([
            'success' => true,
            'data'    => $sales,
            'summary' => [
                'total_sales'   => Nft::whereNotNull('sold_to')->count(),
                'total_volume'  => round($totalVolume, 6),
                'total_revenue' => round($totalRevenue, 6),
            ],
        ]);
    }

    // ── GET /api/admin/users ─────────────────────────────────────
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

    // ── GET /api/admin/pending-nfts ──────────────────────────────
    public function pendingNfts(Request $request): JsonResponse
    {
        if ($err = $this->checkAdmin($request)) return $err;

        $nfts = Nft::where('status', 'pending')
            ->with('collection')
            ->latest()
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $nfts]);
    }
}
