<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use App\Models\Nft;
use App\Services\SolanaService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CollectionController extends Controller
{
    public function __construct(private SolanaService $solana) {}

    /**
     * All Collections or by wallet
     * GET /api/collections
     */
    // public function index(Request $request): JsonResponse
    // {
    //     $wallet = $request->query('wallet');
    //     $query  = Collection::withCount('nfts')->latest();
    //     if ($wallet) {
    //         $query->where('wallet_address', $wallet);
    //     }

    //     return response()->json([
    //         'success' => true,
    //         'data'    => $query->get(),
    //     ]);
    // }


    public function index(Request $request): JsonResponse
    {
        $query = Collection::withCount('nfts')
            ->with(['nfts' => fn($q) => $q->where('status', 'minted')->limit(4)])
            ->latest();

        if ($request->wallet) {
            $query->where('wallet_address', $request->wallet);
        }

        if ($request->search) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $collections = $query->paginate(12);

        // every collection- add stats 
        $collections->getCollection()->transform(function ($col) {
            $nfts              = $col->nfts()->where('status', 'minted')->get();
            $listed            = $nfts->where('is_listed', true);
            $col->floor_price  = $listed->min('list_price');
            $col->total_volume = $nfts->sum('sold_price');
            $col->listed_count = $listed->count();
            $col->preview_images = $nfts->take(4)->pluck('image_url');
            return $col;
        });

        return response()->json([
            'success' => true,
            'data'    => $collections,
        ]);
    }

    /**
     * Single Collection + its NFTs
     * GET /api/collections/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $collection = Collection::withCount('nfts')->findOrFail($id);

        $nftQuery = Nft::where('collection_id', $id)
            ->where('status', 'minted');

        // Filter
        if ($request->category && $request->category !== 'all') {
            $nftQuery->where('category', $request->category);
        }
        if ($request->listed === 'true') {
            $nftQuery->where('is_listed', true);
        }

        // Sort
        match ($request->sort) {
            'price_asc'  => $nftQuery->orderBy('list_price', 'asc'),
            'price_desc' => $nftQuery->orderBy('list_price', 'desc'),
            default      => $nftQuery->latest(),
        };

        $nfts = $nftQuery->paginate(12);

        // Stats
        $allNfts  = Nft::where('collection_id', $id)->where('status', 'minted')->get();
        $listed   = $allNfts->where('is_listed', true);

        $stats = [
            'total_nfts'   => $allNfts->count(),
            'listed_count' => $listed->count(),
            'floor_price'  => $listed->min('list_price'),
            'total_volume' => $allNfts->sum('sold_price'),
            'owners'       => $allNfts->pluck('wallet_address')->unique()->count(),
        ];

        return response()->json([
            'success'    => true,
            'data'       => [
                'collection' => $collection,
                'nfts'       => $nfts,
                'stats'      => $stats,
            ],
        ]);
    }


    /**
     * Create Collection
     * POST /api/collections
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'           => 'required|string|max:100',
            'description'    => 'nullable|string|max:500',
            'symbol'         => 'nullable|string|max:10',
            'wallet_address' => 'required|string',
        ]);

        if (!$this->solana->isValidWalletAddress($request->wallet_address)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid wallet address',
            ], 422);
        }

        $collection = Collection::create([
            'name'           => $request->name,
            'description'    => $request->description,
            'symbol'         => $request->symbol,
            'wallet_address' => $request->wallet_address,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Collection created!',
            'data'    => $collection,
        ]);
    }

    /**
     * Update Collection
     * PUT /api/collections/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $collection = Collection::findOrFail($id);

        if ($collection->wallet_address !== $request->wallet_address) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $collection->update($request->only(['name', 'description', 'symbol']));

        return response()->json(['success' => true, 'data' => $collection]);
    }
}
