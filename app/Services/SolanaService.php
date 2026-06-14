<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Exception;

class SolanaService
{
    private string $rpcUrl;
    private string $network;

    public function __construct()
    {
        $this->rpcUrl  = config('services.solana.rpc_url');
        $this->network = config('services.solana.network');
    }

    /**
     * Wallet Address Validate 
     */
    public function isValidWalletAddress(string $address): bool
    {
        // Solana address = base58, 32-44 characters
        return (bool) preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $address);
    }

    /**
     * Wallet-to  SOL Balance Chack
     */
    public function getBalance(string $walletAddress): float
    {
        $response = Http::post($this->rpcUrl, [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'getBalance',
            'params'  => [$walletAddress],
        ]);

        if (!$response->successful()) {
            throw new Exception('Solana RPC error');
        }

        $lamports = $response->json('result.value') ?? 0;

        // Lamports → SOL (1 SOL = 1,000,000,000 lamports)
        return $lamports / 1_000_000_000;
    }

    /**
     * Wallet-to  NFT list get (Metaplex standard)
     */
    public function getNftsByWallet(string $walletAddress): array
    {
        // Token accounts fetch 
        $response = Http::post($this->rpcUrl, [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'getTokenAccountsByOwner',
            'params'  => [
                $walletAddress,
                ['programId' => 'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA'],
                ['encoding' => 'jsonParsed', 'commitment' => 'confirmed'],
            ],
        ]);

        if (!$response->successful()) {
            throw new Exception('Failed to fetch NFTs from Solana');
        }

        $accounts = $response->json('result.value') ?? [];
        $nfts = [];

        foreach ($accounts as $account) {
            $info = $account['account']['data']['parsed']['info'] ?? null;
            if (!$info) continue;

            // NFT = supply 1, decimals 0
            if (
                isset($info['tokenAmount']['amount']) &&
                $info['tokenAmount']['amount'] === '1' &&
                $info['tokenAmount']['decimals'] === 0
            ) {
                $nfts[] = [
                    'mint'    => $info['mint'],
                    'account' => $account['pubkey'],
                ];
            }
        }

        return $nfts;
    }

    /**
     * Transaction Confirm Chack
     */
    public function getTransaction(string $signature): ?array
    {
        $response = Http::post($this->rpcUrl, [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'getTransaction',
            'params'  => [
                $signature,
                ['encoding' => 'jsonParsed', 'commitment' => 'confirmed'],
            ],
        ]);

        return $response->json('result');
    }

    /**
     * Network info
     */
    public function getNetwork(): string
    {
        return $this->network;
    }

    /**
     * Explorer link  make
     */
    public function getExplorerUrl(string $address, string $type = 'address'): string
    {
        $cluster = $this->network === 'mainnet-beta' ? '' : '?cluster=' . $this->network;
        return "https://explorer.solana.com/{$type}/{$address}{$cluster}";
    }
}
