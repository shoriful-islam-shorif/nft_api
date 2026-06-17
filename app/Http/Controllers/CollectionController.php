<?php

namespace App\Http\Controllers;

use App\Models\Collection;
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
    public function index(Request $request): JsonResponse
    {
        $wallet = $request->query('wallet');
        $query  = Collection::withCount('nfts')->latest();
        if ($wallet) {
            $query->where('wallet_address', $wallet);
        }

        return response()->json([
            'success' => true,
            'data'    => $query->get(),
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
}
