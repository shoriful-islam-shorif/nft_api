<?php

namespace App\Http\Controllers;

use App\Models\Nft;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

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
            ->storageVisible()
            ->with('collection');


        if ($request->category && $request->category !== 'all') {
            $query->where('category', $request->category);
        }

        if ($request->collection_id) {
            $query->where('collection_id', $request->collection_id);
        }

        if ($request->search) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        // ── Group listings by edition ────────────────────────────────
        // When several copies of the same limited edition are listed at
        // once, showing one identical-looking card per copy clutters the
        // grid. Professional marketplaces show ONE card per edition with
        // an "available" count and the floor (cheapest) price instead of
        // N duplicate cards. One-off NFTs (no edition_group_id) are
        // unaffected and still show as themselves.
        $all = $query->get();

        $grouped = $all
            ->groupBy(fn ($nft) => $nft->edition_group_id ?: 'single-' . $nft->id)
            ->map(function ($copies) {
                $cheapest = $copies->sortBy(fn ($n) => (float) $n->list_price)->first();
                $cheapest->setAttribute('listed_copies_count', $copies->count());
                return $cheapest;
            })
            ->values();

        if ($request->sort === 'price_asc') {
            $grouped = $grouped->sortBy(fn ($n) => (float) $n->list_price)->values();
        } elseif ($request->sort === 'price_desc') {
            $grouped = $grouped->sortByDesc(fn ($n) => (float) $n->list_price)->values();
        } else {
            $grouped = $grouped->sortByDesc(fn ($n) => $n->listed_at)->values();
        }

        $perPage = (int) $request->input('per_page', 12);
        $perPage = $perPage > 0 && $perPage <= 100 ? $perPage : 12;
        $page    = max((int) $request->input('page', 1), 1);

        $total = $grouped->count();
        $items = $grouped->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'success' => true,
            'data'    => [
                'data'         => $items,
                'current_page' => $page,
                'last_page'    => (int) max(ceil($total / $perPage), 1),
                'per_page'     => $perPage,
                'total'        => $total,
            ],
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
            'list_currency'  => 'nullable|string|in:spump,usdc',
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
            'is_listed'     => true,
            'list_price'    => $request->list_price,
            'list_currency' => $request->input('list_currency', 'spump'),
            'listed_at'     => now(),
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

    /**
     * Every currently-listed copy of one edition — for the marketplace
     * card's "N available" badge, which opens a modal listing each copy
     * individually (own edition_number, own price) instead of only the
     * cheapest one the card itself links to.
     * GET /api/marketplace/edition/{edition_group_id}/listings
     */
    public function editionListings(string $editionGroupId): JsonResponse
    {
        $listings = Nft::where('edition_group_id', $editionGroupId)
            ->where('status', 'minted')
            ->where('is_listed', true)
            ->orderBy('edition_number')
            ->get(['id', 'mint_address', 'edition_number', 'total_supply', 'list_price', 'list_currency']);

        return response()->json([
            'success' => true,
            'data'    => $listings,
        ]);
    }

    public function show($id)
    {
        $nft = Nft::findOrFail($id);

        // Download image from IPFS
        $response = Http::timeout(30)->get($nft->image_url);

        if (!$response->successful()) {
            abort(404, 'Image not found');
        }

        // Create image manager
        $manager = new ImageManager(new Driver());

        // Read image
        $image = $manager->read($response->body());

        // Resize (keep aspect ratio)
        $image->scale(width: 600);

        // Return compressed WEBP preview
        return response(
            $image->toWebp(quality: 55)
        )
        ->header('Content-Type', 'image/webp')
        ->header('Cache-Control', 'private, max-age=3600')
        ->header('Content-Disposition', 'inline')
        ->header('X-Content-Type-Options', 'nosniff');
    }
}
