<?php

namespace App\Http\Controllers;

use App\Models\Nft;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class MarketplaceController extends Controller
{
    /**
     * Marketplace — listed NFT 
     * GET /api/marketplace
     */
    public function index(Request $request): JsonResponse
    {
        $query = Nft::where('status', 'minted')
            ->where('is_listed', true)
            ->with('collection');
            

        if ($request->category && $request->category !== 'all') {
            $query->where('category', $request->category);
        }

        if ($request->search) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        if ($request->sort === 'price_asc') {
            $query->orderBy('list_price', 'asc');
        } elseif ($request->sort === 'price_desc') {
            $query->orderBy('list_price', 'desc');
        }else {
        $query->latest('listed_at');
    }

        $nfts = $query->paginate(12);

        return response()->json([
            'success' => true,
            'data'    => $nfts,
        ]);
    }

    /**
     * NFT List for Sale
     * POST /api/marketplace/list
     */
    public function list(Request $request): JsonResponse
    {
        $request->validate([
            'nft_id'         => 'required|exists:nfts,id',
            'wallet_address' => 'required|string',
            'list_price'     => 'required|numeric|min:0',
        ]);

        $nft = Nft::findOrFail($request->nft_id);

        // only owner can list 
        if ($nft->wallet_address !== $request->wallet_address) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized — you are not the owner of this NFT.',
            ], 403);
        }

        if ($nft->status !== 'minted') {
            return response()->json([
                'success' => false,
                'message' => 'NFT is not minted yet.',
            ], 422);
        }

        $nft->update([
            'is_listed'  => true,
            'list_price' => $request->list_price,
            'listed_at'  => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'NFT listed on marketplace successfully! 🎉',
            'data'    => $nft->fresh(),
        ]);
    }

    /**
     * NFT Unlist
     * POST /api/marketplace/unlist
     */
    public function unlist(Request $request): JsonResponse
    {
        $request->validate([
            'nft_id'         => 'required|exists:nfts,id',
            'wallet_address' => 'required|string',
        ]);

        $nft = Nft::findOrFail($request->nft_id);

        if ($nft->wallet_address !== $request->wallet_address) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized — you are not the owner of this NFT.',
            ], 403);
        }

        $nft->update([
            'is_listed'  => false,
            'list_price' => null,
            'listed_at'  => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'NFT unlisted from marketplace.',
        ]);
    }

    /**
     * My NFTs (wallet at minted NFT)
     * GET /api/marketplace/my-nfts/{wallet}
     */
    public function myNfts(string $wallet): JsonResponse
    {
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
}
