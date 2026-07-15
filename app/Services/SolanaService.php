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
     * Verify that a confirmed transaction actually paid the expected
     * SOL amount to the expected recipient wallet.
     *
     * Without this check, `getTransaction`/`isSignatureConfirmed` only
     * prove *some* transaction landed on-chain — not that it paid
     * anything. A client could submit an unrelated (but valid) signed
     * signature and have the mint marked as paid for free. This reads
     * the transaction's pre/post SOL balances for the recipient
     * account and confirms it actually increased by the expected
     * amount (within a small tolerance for float rounding).
     */
    public function verifyPayment(string $signature, string $recipientWallet, float $expectedAmountSol, float $toleranceSol = 0.0005): bool
    {
        // Nothing to verify for free mints.
        if ($expectedAmountSol <= 0) {
            return true;
        }

        $tx = $this->getTransaction($signature);
        if (!$tx) {
            return false;
        }

        // Transaction must not have failed on-chain.
        if (($tx['meta']['err'] ?? null) !== null) {
            return false;
        }

        $accountKeys   = $tx['transaction']['message']['accountKeys'] ?? [];
        $preBalances   = $tx['meta']['preBalances'] ?? [];
        $postBalances  = $tx['meta']['postBalances'] ?? [];

        if (empty($accountKeys) || empty($preBalances) || empty($postBalances)) {
            return false;
        }

        foreach ($accountKeys as $index => $key) {
            $pubkey = is_array($key) ? ($key['pubkey'] ?? null) : $key;
            if ($pubkey !== $recipientWallet) {
                continue;
            }

            $pre  = $preBalances[$index]  ?? null;
            $post = $postBalances[$index] ?? null;
            if ($pre === null || $post === null) {
                return false;
            }

            $receivedSol = ($post - $pre) / 1_000_000_000;
            return $receivedSol >= ($expectedAmountSol - $toleranceSol);
        }

        // Recipient wallet never appears in the transaction's account keys.
        return false;
    }

    /**
     * Resolve the platform treasury wallet. The admin dashboard lets an
     * admin override this via `platform_settings` (key: platform_wallet);
     * fall back to the .env default when no override is set. Every place
     * that needs "the" platform wallet (mint payment verification, buy
     * payment verification, showing it to the frontend) must go through
     * this single method — otherwise different parts of the app can end
     * up checking payments against different addresses.
     */
    public function getTreasuryWallet(): ?string
    {
        $dbWallet = \App\Models\PlatformSetting::get('platform_wallet');
        return $dbWallet ?: config('services.platform.wallet');
    }

    /**
     * Resolve the platform fee percent the same way — DB setting
     * (admin-editable) overrides the .env default.
     */
    public function getPlatformFeePercent(): float
    {
        return (float) \App\Models\PlatformSetting::get(
            'platform_fee_percent',
            config('services.platform.fee_percent', 3)
        );
    }

    /**
     * Verify an SPL-token transfer (e.g. SPUMP payments) actually
     * landed on the recipient's associated token account inside this
     * exact transaction — the token-balance equivalent of
     * verifyPayment(). Reads meta.preTokenBalances/postTokenBalances,
     * which report ui-amounts per (mint, owner) pair regardless of
     * whether the destination token account already existed before
     * this transaction (newly-created ATAs simply have no pre-balance
     * entry, treated as a starting balance of 0).
     */
    public function verifyTokenPayment(string $signature, string $mintAddress, string $recipientWallet, float $expectedAmount, float $tolerance = 0.01): bool
    {
        if ($expectedAmount <= 0) {
            return true;
        }

        $tx = $this->getTransaction($signature);
        if (!$tx) {
            return false;
        }

        if (($tx['meta']['err'] ?? null) !== null) {
            return false;
        }

        $preTokenBalances  = $tx['meta']['preTokenBalances']  ?? [];
        $postTokenBalances = $tx['meta']['postTokenBalances'] ?? [];

        $postEntry = null;
        foreach ($postTokenBalances as $entry) {
            if (($entry['mint'] ?? null) === $mintAddress && ($entry['owner'] ?? null) === $recipientWallet) {
                $postEntry = $entry;
                break;
            }
        }

        if (!$postEntry) {
            // Recipient's token account never appears post-transaction
            // for this mint — no way it received a payment.
            return false;
        }

        $decimals = (int) ($postEntry['uiTokenAmount']['decimals'] ?? 0);
        $postRaw  = (float) ($postEntry['uiTokenAmount']['amount'] ?? '0');

        $preRaw = 0.0;
        foreach ($preTokenBalances as $entry) {
            if (
                ($entry['mint'] ?? null) === $mintAddress
                && ($entry['owner'] ?? null) === $recipientWallet
                && ($entry['accountIndex'] ?? null) === ($postEntry['accountIndex'] ?? null)
            ) {
                $preRaw = (float) ($entry['uiTokenAmount']['amount'] ?? '0');
                break;
            }
        }

        $received = ($postRaw - $preRaw) / (10 ** $decimals);

        return $received >= ($expectedAmount - $tolerance);
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
