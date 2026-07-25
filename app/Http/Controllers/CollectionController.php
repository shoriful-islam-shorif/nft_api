<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use App\Models\Nft;
use App\Services\SolanaService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection as SupportCollection;

class CollectionController extends Controller
{
    public function __construct(private SolanaService $solana) {}

    /**
     * Group a set of NFTs by their listing currency and reduce each
     * group to one number. SPUMP and USDC amounts are never summed or
     * compared against each other directly — 5 USDC and 5000 SPUMP
     * aren't the same "size" of number, so a naive min()/sum() across
     * mixed currencies would silently produce a meaningless value.
     * Returns e.g. ['spump' => 1200, 'usdc' => 5.5] — only currencies
     * that actually have at least one qualifying NFT are included.
     */
    private function groupByCurrency(SupportCollection $nfts, string $priceField, string $reducer): array
    {
        return $nfts
            ->filter(fn($n) => $n->{$priceField} !== null)
            ->groupBy(fn($n) => $n->list_currency ?: 'spump')
            ->map(function ($group) use ($priceField, $reducer) {
                return $reducer === 'min'
                    ? (float) $group->min($priceField)
                    : (float) $group->sum($priceField);
            })
            ->toArray();
    }

    /**
     * All Collections or by wallet
     * GET /api/collections
     */
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

        // Bounded per_page (default 12, same as before) — lets callers
        // like the marketplace's collection-filter dropdown ask for
        // more than one page's worth without a separate endpoint.
        $perPage = min((int) ($request->per_page ?? 12), 100);
        $collections = $query->paginate($perPage);

        // every collection — add stats, split by currency (SPUMP/USDC)
        $collections->getCollection()->transform(function ($col) {
            $nfts   = $col->nfts()->where('status', 'minted')->get();
            $listed = $nfts->where('is_listed', true);
            $sold   = $nfts->whereNotNull('sold_to');

            // Floor price per currency — lowest LISTED price in each
            // currency this collection is actually listed in.
            $col->floor_prices = $this->groupByCurrency($listed, 'list_price', 'min');

            // Volume per currency — sum of sold_price (denominated in
            // whatever list_currency was active at sale time; that
            // field is never cleared on sale, only list_price is).
            $col->volumes = $this->groupByCurrency($sold, 'sold_price', 'sum');

            $col->listed_count   = $listed->count();
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

        // Sort — comparing list_price across currencies isn't
        // meaningful (5 USDC vs 5000 SPUMP), so "price" sort is scoped
        // to whichever single currency the buyer is filtering by, if
        // any; otherwise it falls back to latest to avoid silently
        // mixing units.
        if (in_array($request->sort, ['price_asc', 'price_desc']) && $request->currency) {
            $nftQuery->where('list_currency', $request->currency);
            $nftQuery->orderBy('list_price', $request->sort === 'price_asc' ? 'asc' : 'desc');
        } else {
            match ($request->sort) {
                'price_asc'  => $nftQuery->orderBy('list_price', 'asc'),
                'price_desc' => $nftQuery->orderBy('list_price', 'desc'),
                default      => $nftQuery->latest(),
            };
        }

        $nfts = $nftQuery->paginate(12);

        // Stats — split by currency (SPUMP/USDC), same reasoning as index()
        $allNfts = Nft::where('collection_id', $id)->where('status', 'minted')->get();
        $listed  = $allNfts->where('is_listed', true);
        $sold    = $allNfts->whereNotNull('sold_to');

        $stats = [
            'total_nfts'   => $allNfts->count(),
            'listed_count' => $listed->count(),
            'floor_prices' => $this->groupByCurrency($listed, 'list_price', 'min'),
            'volumes'      => $this->groupByCurrency($sold, 'sold_price', 'sum'),
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
