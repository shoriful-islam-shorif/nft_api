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
        // Solana address = base58 characters, 32-44 length
        $address = trim($address);
        if (strlen($address) < 32 || strlen($address) > 44) {
            return false;
        }
        // base58 characters only
        return (bool) preg_match('/^[1-9A-HJ-NP-Za-km-z]+$/', $address);
    }

    /**
     * Wallet- chack  SOL Balance 
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
     * Wallet- NFT list  (Metaplex standard)
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
     * Transaction Confirm chack
     */
    public function getTransaction(string $signature): ?array
    {
        $maxAttempts = 5;
        $delaySeconds = 2;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = Http::post($this->rpcUrl, [
                'jsonrpc' => '2.0',
                'id'      => 1,
                'method'  => 'getTransaction',
                'params'  => [
                    $signature,
                    [
                        'encoding'                       => 'jsonParsed',
                        'commitment'                      => 'confirmed',
                        'maxSupportedTransactionVersion'  => 0,
                    ],
                ],
            ]);

            $result = $response->json('result');

            if ($result !== null) {
                return $result;
            }

            
            if ($attempt < $maxAttempts) {
                sleep($delaySeconds);
            }
        }

        return null;
    }

    /**
     * Network info
     */
    public function getNetwork(): string
    {
        return $this->network;
    }

    /**
     * Lightweight fallback check — uses getSignatureStatuses instead
     * of getTransaction. This RPC method is typically faster to
     * index and is a reliable secondary signal that a transaction
     * has landed on-chain, even if the full transaction details
     * aren't queryable yet via getTransaction.
     */
    public function isSignatureConfirmed(string $signature): bool
    {
        $maxAttempts = 5;
        $delaySeconds = 2;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = Http::post($this->rpcUrl, [
                'jsonrpc' => '2.0',
                'id'      => 1,
                'method'  => 'getSignatureStatuses',
                'params'  => [
                    [$signature],
                    ['searchTransactionHistory' => true],
                ],
            ]);

            $status = $response->json('result.value.0');

            if ($status !== null) {
                $confirmationStatus = $status['confirmationStatus'] ?? null;
                if (in_array($confirmationStatus, ['confirmed', 'finalized'], true)) {
                    return true;
                }
                // Has a status but not confirmed/finalized yet — also check for err
                if (($status['err'] ?? null) !== null) {
                    // Transaction failed on-chain, no point retrying
                    return false;
                }
            }

            if ($attempt < $maxAttempts) {
                sleep($delaySeconds);
            }
        }

        return false;
    }

    
    public function getExplorerUrl(string $address, string $type = 'address'): string
    {
        $cluster = $this->network === 'mainnet-beta' ? '' : '?cluster=' . $this->network;
        return "https://explorer.solana.com/{$type}/{$address}{$cluster}";
    }
}
