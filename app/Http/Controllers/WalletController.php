<?php

namespace App\Http\Controllers;

use App\Services\SolanaService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class WalletController extends Controller
{
    public function __construct(private SolanaService $solana) {}

    /**
     * Wallet verify + balance check
     * POST /api/wallet/verify
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'wallet_address' => 'required|string',
        ]);

        if (!$this->solana->isValidWalletAddress($request->wallet_address)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Solana wallet address',
            ], 422);
        }

        try {
            $balance = $this->solana->getBalance($request->wallet_address);

            return response()->json([
                'success' => true,
                'data'    => [
                    'wallet'  => $request->wallet_address,
                    'balance' => $balance . ' SOL',
                    'network' => $this->solana->getNetwork(),
                    'explorer'=> $this->solana->getExplorerUrl($request->wallet_address),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Wallet- NFT list ( To Solana chain)
     * GET /api/wallet/nfts/{address}
     */
    public function getNfts(string $address): JsonResponse
    {
        if (!$this->solana->isValidWalletAddress($address)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid wallet address',
            ], 422);
        }

        try {
            $nfts = $this->solana->getNftsByWallet($address);

            return response()->json([
                'success' => true,
                'wallet'  => $address,
                'total'   => count($nfts),
                'data'    => $nfts,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
