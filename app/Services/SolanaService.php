<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
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
     * Resolve the platform's NFT-transfer-delegate wallet — the hot
     * wallet approved (at listing time) as the mpl-core TransferDelegate
     * authority for marketplace sales. Deliberately a separate identity
     * from the treasury wallet (getTreasuryWallet()) that only ever
     * receives fee payments; this one only ever signs transfer
     * instructions, never touches funds directly.
     */
    public function getDelegateWallet(): ?string
    {
        $dbWallet = \App\Models\PlatformSetting::get('delegate_wallet');
        return $dbWallet ?: config('services.platform.delegate_wallet');
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
     * Resolve the platform fee — a flat SOL amount (admin-editable via
     * PlatformSetting 'platform_fee_amount_sol'), no longer a percentage
     * of the sale price. Falls back to the .env default when no
     * PlatformSetting row exists yet.
     */
    public function getPlatformFeeAmountSol(): float
    {
        return (float) \App\Models\PlatformSetting::get(
            'platform_fee_amount_sol',
            config('services.platform.fee_amount_sol', 0.01)
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
     * Live SPUMP↔USDC rate — shared by mint and buy/sell flows so there's
     * one source of truth. Cached 60s (successful lookups only).
     *
     * Source chain, in order, each tried only if the one before it fails:
     *   1. Jupiter    — Price API, falling back internally to Jupiter's own
     *                   Quote API for low-liquidity pools it won't index a
     *                   confident price for.
     *   2. Raydium    — independent of Jupiter's infrastructure entirely.
     *   3. Dexscreener (by mint address) — picks the highest-liquidity
     *                   pair Dexscreener has indexed for this token.
     *   4. Dexscreener (by known pool address) — most specific/guaranteed
     *                   fallback: reads the exact SPUMP/USDC pool directly
     *                   by its pair address, bypassing any search/matching.
     */
    public function getSpumpUsdcRate(): ?array
    {
        // Only cache SUCCESSFUL lookups. If we cached failures too, a
        // transient API hiccup (or a config value that was just fixed)
        // would leave the frontend stuck showing null/"Loading..." for up
        // to 60s even after the underlying problem is gone.
        $cached = Cache::get('spump_price_data');
        if ($cached !== null) {
            return $cached;
        }

        $spumpMint = config('services.tokens.spump_mint');
        $usdcMint  = config('services.tokens.usdc_mint');

        if (!$spumpMint || !$usdcMint) {
            \Illuminate\Support\Facades\Log::error('SPUMP/USDC rate unavailable: token mint(s) not configured', [
                'spump_mint_set' => (bool) $spumpMint,
                'usdc_mint_set'  => (bool) $usdcMint,
            ]);
            return null;
        }

        $sources = [
            'jupiter'     => fn () => $this->getSpumpUsdcRateViaJupiter($spumpMint, $usdcMint),
            'raydium'     => fn () => $this->getSpumpUsdcRateViaRaydium($spumpMint, $usdcMint),
            'dexscreener' => fn () => $this->getSpumpUsdcRateViaDexscreenerToken($spumpMint, $usdcMint),
            'dexscreener_pool' => fn () => $this->getSpumpUsdcRateViaDexscreenerPool($spumpMint, $usdcMint),
        ];

        $result = null;
        $tried  = [];

        foreach ($sources as $name => $attempt) {
            $tried[] = $name;
            $result  = $attempt();
            if ($result !== null) {
                break;
            }
            \Illuminate\Support\Facades\Log::warning("SPUMP/USDC rate: {$name} failed, trying next source", [
                'spump_mint' => $spumpMint,
                'usdc_mint'  => $usdcMint,
            ]);
        }

        if ($result === null) {
            \Illuminate\Support\Facades\Log::error('SPUMP/USDC rate: all sources failed', [
                'tried'      => $tried,
                'spump_mint' => $spumpMint,
                'usdc_mint'  => $usdcMint,
            ]);

            $fallback = $this->staticFallbackRate('spump_usd_fallback');
            if ($fallback === null) {
                return null;
            }

            // No cache on the fallback path — deliberately re-tries live
            // sources every call (it's cheap: they fail fast with no
            // route found), so pricing snaps back to real market data
            // immediately once it becomes available, instead of being
            // stuck on a stale static number for up to 60s.
            \Illuminate\Support\Facades\Log::warning('SPUMP/USDC rate: using static fallback price (live sources unavailable — expected on devnet)', ['spump_usd_fallback' => $fallback]);
            return [
                'spump_per_usdc' => round(1 / $fallback, 6),
                'spump_usd'      => $fallback,
                'usdc_usd'       => (float) config('services.tokens.usdc_usd_fallback', 1.0),
                'decimals'       => null,
                'usdc_decimals'  => null,
                'source'         => 'static_fallback',
                'updated_at'     => now()->toDateTimeString(),
            ];
        }

        $result['updated_at'] = now()->toDateTimeString();

        Cache::put('spump_price_data', $result, 60);

        return $result;
    }

    /**
     * Reads a configured static fallback USD price (e.g.
     * spump_usd_fallback, sol_usd_fallback) — returns null (meaning
     * "no fallback configured, stay failed") if it isn't set or isn't a
     * usable positive number, so an admin who hasn't opted into this
     * still gets the original null/"rate unavailable" behavior.
     */
    private function staticFallbackRate(string $configKey): ?float
    {
        $value = config("services.tokens.{$configKey}");
        if ($value === null || $value === '') {
            return null;
        }
        $value = (float) $value;
        return $value > 0 ? $value : null;
    }

    /**
     * PRIMARY source. Jupiter Price API first; if it won't vouch for a
     * confident usdPrice on SPUMP specifically (common for low-liquidity
     * tokens — the pool is found but the index API withholds a number),
     * falls back internally to Jupiter's own Quote API, which computes a
     * rate from a real swap route regardless of that confidence threshold.
     * Returns null only if Jupiter is unusable end-to-end (down, no
     * config, no route at all) — that's the signal to try Raydium next.
     */
    private function getSpumpUsdcRateViaJupiter(string $spumpMint, string $usdcMint): ?array
    {
        try {
            $response = Http::timeout(10)->get(
                'https://lite-api.jup.ag/price/v3',
                ['ids' => "{$spumpMint},{$usdcMint}"]
            );
        } catch (Exception $e) {
            \Illuminate\Support\Facades\Log::error('SPUMP/USDC rate fetch threw', ['message' => $e->getMessage()]);
            return null;
        }

        if (!$response->successful()) {
            \Illuminate\Support\Facades\Log::error('SPUMP/USDC rate fetch failed', ['status' => $response->status(), 'body' => $response->body()]);
            return null;
        }

        $prices = $response->json();

        $spumpDecimals = (int) ($prices[$spumpMint]['decimals'] ?? 6);
        $usdcDecimals  = (int) ($prices[$usdcMint]['decimals'] ?? 6);

        // USDC virtually always has a confident usdPrice from the Price API
        // (it's the world's most liquid stablecoin) — if THAT's missing,
        // something is genuinely wrong with this source (bad mint config,
        // API outage), not just a thin SPUMP pool. Bail out to Raydium.
        if (!isset($prices[$usdcMint]['usdPrice'])) {
            \Illuminate\Support\Facades\Log::error('SPUMP/USDC rate fetch missing price data', ['prices' => $prices, 'spump_mint' => $spumpMint, 'usdc_mint' => $usdcMint]);
            return null;
        }

        $usdcUsd = (float) $prices[$usdcMint]['usdPrice'];

        $spumpUsd     = isset($prices[$spumpMint]['usdPrice']) ? (float) $prices[$spumpMint]['usdPrice'] : null;
        $spumpPerUsdc = null;

        if ($spumpUsd !== null && $spumpUsd > 0) {
            $spumpPerUsdc = round($usdcUsd / $spumpUsd, 6);
        } else {
            // SPUMP alone missing 'usdPrice' even though Jupiter recognizes
            // the mint and found its pool (decimals/liquidity/blockId
            // present) — the Price API omits usdPrice when it isn't
            // confident enough in a pool's liquidity to quote a price from
            // it. The Quote API is a different code path — it computes a
            // rate from a REAL swap route through that same pool
            // regardless of the index's confidence threshold.
            $quote = $this->getSpumpUsdcRateViaQuote($spumpMint, $usdcMint, $usdcDecimals, $spumpDecimals, $usdcUsd);
            if ($quote === null) {
                return null;
            }
            $spumpPerUsdc = $quote['spump_per_usdc'];
            $spumpUsd     = $quote['spump_usd'];
        }

        return [
            'spump_per_usdc' => $spumpPerUsdc,
            'spump_usd'      => $spumpUsd,
            'usdc_usd'       => $usdcUsd,
            'decimals'       => $spumpDecimals,
            'usdc_decimals'  => $usdcDecimals,
            'source'         => 'jupiter',
        ];
    }

    /**
     * Fallback used only when the Price API has a pool for SPUMP but
     * won't return a confident usdPrice for it (common for low-liquidity
     * tokens). Asks the Quote API for a real swap route: "1 USDC in, how
     * much SPUMP out right now" — that's a live, on-chain-route-backed
     * rate, not an index confidence score, so it works regardless of how
     * thin the pool is (as long as a route exists at all).
     */
    private function getSpumpUsdcRateViaQuote(string $spumpMint, string $usdcMint, int $usdcDecimals, int $spumpDecimals, float $usdcUsd): ?array
    {
        // Quote for exactly 1 USDC worth of input, in USDC's smallest unit.
        $oneUsdcRaw = (int) (1 * (10 ** $usdcDecimals));

        try {
            $response = Http::timeout(10)->get('https://lite-api.jup.ag/swap/v1/quote', [
                'inputMint'   => $usdcMint,
                'outputMint'  => $spumpMint,
                'amount'      => $oneUsdcRaw,
                'slippageBps' => 500, // generous — this is a rate lookup, not a real swap
            ]);
        } catch (Exception $e) {
            \Illuminate\Support\Facades\Log::error('SPUMP/USDC quote fallback threw', ['message' => $e->getMessage()]);
            return null;
        }

        if (!$response->successful()) {
            \Illuminate\Support\Facades\Log::error('SPUMP/USDC quote fallback failed', ['status' => $response->status(), 'body' => $response->body()]);
            return null;
        }

        $quote     = $response->json();
        $outAmount = $quote['outAmount'] ?? null;

        if (!$outAmount || (float) $outAmount <= 0) {
            \Illuminate\Support\Facades\Log::error('SPUMP/USDC quote fallback: no route/outAmount', ['quote' => $quote, 'spump_mint' => $spumpMint, 'usdc_mint' => $usdcMint]);
            return null;
        }

        // outAmount is how much SPUMP (raw, smallest-unit) 1 USDC bought —
        // that IS spump_per_usdc once converted to whole-token units.
        $spumpPerUsdc = round(((float) $outAmount) / (10 ** $spumpDecimals), 6);
        if ($spumpPerUsdc <= 0) {
            return null;
        }

        return [
            'spump_per_usdc' => $spumpPerUsdc,
            // Approximate — derived from the swap rate against USDC's own
            // usdPrice, not a separate index price for SPUMP. Fine for
            // display; not precise enough to rely on for anything else.
            'spump_usd'      => round($usdcUsd / $spumpPerUsdc, 8),
        ];
    }

    /**
     * SECONDARY source — only tried when Jupiter (Price API + its own
     * Quote fallback) fails entirely. Raydium runs independently of
     * Jupiter's infrastructure, so it can still answer during a Jupiter
     * outage. Uses Raydium's own price index (mint/price) — like
     * Jupiter's Price API, this is confidence-based, so a very
     * low-liquidity token can still come back empty here too; that's
     * what the Dexscreener tiers below are for.
     */
    private function getSpumpUsdcRateViaRaydium(string $spumpMint, string $usdcMint): ?array
    {
        try {
            $response = Http::timeout(10)->get('https://api-v3.raydium.io/mint/price', [
                'mints' => "{$spumpMint},{$usdcMint}",
            ]);
        } catch (Exception $e) {
            \Illuminate\Support\Facades\Log::error('SPUMP/USDC Raydium fallback threw', ['message' => $e->getMessage()]);
            return null;
        }

        if (!$response->successful()) {
            \Illuminate\Support\Facades\Log::error('SPUMP/USDC Raydium fallback failed', ['status' => $response->status(), 'body' => $response->body()]);
            return null;
        }

        $body   = $response->json();
        $prices = $body['data'] ?? null;

        $spumpUsdRaw = $prices[$spumpMint] ?? null;
        $usdcUsdRaw  = $prices[$usdcMint] ?? null;

        if ($spumpUsdRaw === null || $usdcUsdRaw === null) {
            \Illuminate\Support\Facades\Log::error('SPUMP/USDC Raydium fallback: missing price data', ['body' => $body, 'spump_mint' => $spumpMint, 'usdc_mint' => $usdcMint]);
            return null;
        }

        $spumpUsd = (float) $spumpUsdRaw;
        $usdcUsd  = (float) $usdcUsdRaw;

        if ($spumpUsd <= 0) {
            return null;
        }

        return [
            'spump_per_usdc' => round($usdcUsd / $spumpUsd, 6),
            'spump_usd'      => $spumpUsd,
            'usdc_usd'       => $usdcUsd,
            // Raydium's mint/price response doesn't include decimals —
            // fall back to the standard 6 both these tokens actually use.
            'decimals'       => 6,
            'usdc_decimals'  => 6,
            'source'         => 'raydium',
        ];
    }

    /**
     * TERTIARY source — tried when both Jupiter and Raydium fail. Queries
     * Dexscreener for every pool it has indexed for the SPUMP mint, picks
     * the highest-liquidity one, and reads that pool's own priceUsd
     * directly. Dexscreener computes priceUsd itself from the pool's real
     * reserves — it isn't a confidence-gated index like the two above, so
     * it can often answer even for pools too thin for Jupiter/Raydium to
     * vouch for.
     */
    private function getSpumpUsdcRateViaDexscreenerToken(string $spumpMint, string $usdcMint): ?array
    {
        try {
            $response = Http::timeout(10)->get("https://api.dexscreener.com/latest/dex/tokens/{$spumpMint}");
        } catch (Exception $e) {
            \Illuminate\Support\Facades\Log::error('SPUMP/USDC Dexscreener (token) fallback threw', ['message' => $e->getMessage()]);
            return null;
        }

        if (!$response->successful()) {
            \Illuminate\Support\Facades\Log::error('SPUMP/USDC Dexscreener (token) fallback failed', ['status' => $response->status(), 'body' => $response->body()]);
            return null;
        }

        $pairs = $response->json('pairs');
        if (!is_array($pairs) || count($pairs) === 0) {
            \Illuminate\Support\Facades\Log::error('SPUMP/USDC Dexscreener (token) fallback: no pairs found', ['spump_mint' => $spumpMint]);
            return null;
        }

        // Prefer a pair actually quoted against USDC if one exists (most
        // direct); otherwise fall back to whatever pair has the highest
        // liquidity — Dexscreener's priceUsd is already USD-normalized
        // regardless of the quote asset, so any pair still gives a usable
        // spump_usd.
        $usdcPairs = array_filter($pairs, function ($p) use ($spumpMint, $usdcMint) {
            $base  = strtolower($p['baseToken']['address']  ?? '');
            $quote = strtolower($p['quoteToken']['address'] ?? '');
            return ($base === strtolower($spumpMint) && $quote === strtolower($usdcMint))
                || ($base === strtolower($usdcMint) && $quote === strtolower($spumpMint));
        });

        $candidates = count($usdcPairs) > 0 ? array_values($usdcPairs) : $pairs;

        usort($candidates, fn ($a, $b) => (float) ($b['liquidity']['usd'] ?? 0) <=> (float) ($a['liquidity']['usd'] ?? 0));
        $best = $candidates[0];

        $baseIsSpump = strtolower($best['baseToken']['address'] ?? '') === strtolower($spumpMint);
        $spumpUsd    = (float) ($best['priceUsd'] ?? 0);

        if ($spumpUsd <= 0) {
            \Illuminate\Support\Facades\Log::error('SPUMP/USDC Dexscreener (token) fallback: no usable priceUsd', ['pair' => $best]);
            return null;
        }

        // If SPUMP is the quote side instead of base for the best pair,
        // priceUsd refers to the OTHER token — invert.
        if (!$baseIsSpump) {
            $otherUsd = $spumpUsd;
            $spumpUsd = $otherUsd > 0 ? round(1 / $otherUsd, 12) : 0;
            // This edge case is rare enough (Dexscreener usually returns
            // the queried token as base) that we bail rather than trust a
            // shaky inversion.
            if ($spumpUsd <= 0) {
                return null;
            }
        }

        return [
            'spump_per_usdc' => round(1 / $spumpUsd, 6),
            'spump_usd'      => $spumpUsd,
            'usdc_usd'       => 1.0, // USDC assumed ≈ $1 here; Dexscreener's priceUsd for SPUMP is already USD-denominated
            'decimals'       => 6,
            'usdc_decimals'  => 6,
            'source'         => 'dexscreener',
        ];
    }

    /**
     * QUATERNARY / final source — reads one specific, known-good
     * SPUMP/USDC pool by its exact pair address, bypassing any
     * search/matching entirely. This is the most specific and most
     * reliable fallback of all four, but only covers this ONE pool, so
     * it's kept last: if that particular pool ever drains or migrates,
     * the earlier tiers (which discover pools dynamically) still work.
     */
    private function getSpumpUsdcRateViaDexscreenerPool(string $spumpMint, string $usdcMint): ?array
    {
        $poolAddress = config('services.tokens.spump_usdc_pool');
        if (!$poolAddress) {
            return null;
        }

        try {
            $response = Http::timeout(10)->get("https://api.dexscreener.com/latest/dex/pairs/solana/{$poolAddress}");
        } catch (Exception $e) {
            \Illuminate\Support\Facades\Log::error('SPUMP/USDC Dexscreener (pool) fallback threw', ['message' => $e->getMessage()]);
            return null;
        }

        if (!$response->successful()) {
            \Illuminate\Support\Facades\Log::error('SPUMP/USDC Dexscreener (pool) fallback failed', ['status' => $response->status(), 'body' => $response->body()]);
            return null;
        }

        $pairs = $response->json('pairs');
        $pair  = is_array($pairs) && count($pairs) > 0 ? $pairs[0] : null;

        if (!$pair) {
            \Illuminate\Support\Facades\Log::error('SPUMP/USDC Dexscreener (pool) fallback: pool not found', ['pool_address' => $poolAddress]);
            return null;
        }

        $baseAddress  = strtolower($pair['baseToken']['address']  ?? '');
        $quoteAddress = strtolower($pair['quoteToken']['address'] ?? '');
        $priceUsd     = (float) ($pair['priceUsd'] ?? 0);

        if ($priceUsd <= 0) {
            return null;
        }

        if ($baseAddress === strtolower($spumpMint)) {
            $spumpUsd = $priceUsd;
        } elseif ($quoteAddress === strtolower($spumpMint)) {
            $spumpUsd = $priceUsd > 0 ? round(1 / $priceUsd, 12) : 0;
        } else {
            \Illuminate\Support\Facades\Log::error('SPUMP/USDC Dexscreener (pool) fallback: configured pool does not contain SPUMP mint', ['pool_address' => $poolAddress, 'pair' => $pair]);
            return null;
        }

        if ($spumpUsd <= 0) {
            return null;
        }

        return [
            'spump_per_usdc' => round(1 / $spumpUsd, 6),
            'spump_usd'      => $spumpUsd,
            'usdc_usd'       => 1.0,
            'decimals'       => 6,
            'usdc_decimals'  => 6,
            'source'         => 'dexscreener',
        ];
    }

    /**
     * Live SOL↔USDC rate. Admin pricing (mint_price, platform fee) is
     * now configured in SOL, so this is the other half of every
     * SOL→SPUMP / SOL→USDC conversion. Reuses the exact same
     * multi-source fallback chain as getSpumpUsdcRate() (Jupiter →
     * Raydium → Dexscreener-by-mint) by just pointing those same
     * mint-agnostic private methods at the wrapped-SOL mint instead of
     * SPUMP — SOL/USDC is about as deep a pool as exists on Solana, so
     * the pool-address-specific final tier isn't needed here. Cached
     * 60s (successful lookups only), same as the SPUMP rate.
     */
    public function getSolUsdcRate(): ?array
    {
        $cached = Cache::get('sol_price_data');
        if ($cached !== null) {
            return $cached;
        }

        $solMint  = config('services.tokens.sol_mint');
        $usdcMint = config('services.tokens.usdc_mint');

        if (!$solMint || !$usdcMint) {
            \Illuminate\Support\Facades\Log::error('SOL/USDC rate unavailable: token mint(s) not configured', [
                'sol_mint_set'  => (bool) $solMint,
                'usdc_mint_set' => (bool) $usdcMint,
            ]);
            return null;
        }

        $sources = [
            'jupiter'     => fn () => $this->getSpumpUsdcRateViaJupiter($solMint, $usdcMint),
            'raydium'     => fn () => $this->getSpumpUsdcRateViaRaydium($solMint, $usdcMint),
            'dexscreener' => fn () => $this->getSpumpUsdcRateViaDexscreenerToken($solMint, $usdcMint),
        ];

        $result = null;
        $tried  = [];

        foreach ($sources as $name => $attempt) {
            $tried[] = $name;
            $result  = $attempt();
            if ($result !== null) {
                break;
            }
            \Illuminate\Support\Facades\Log::warning("SOL/USDC rate: {$name} failed, trying next source", [
                'sol_mint'  => $solMint,
                'usdc_mint' => $usdcMint,
            ]);
        }

        if ($result === null) {
            \Illuminate\Support\Facades\Log::error('SOL/USDC rate: all sources failed', [
                'tried'    => $tried,
                'sol_mint' => $solMint,
            ]);

            $fallback = $this->staticFallbackRate('sol_usd_fallback');
            if ($fallback === null) {
                return null;
            }

            \Illuminate\Support\Facades\Log::warning('SOL/USDC rate: using static fallback price (live sources unavailable — expected on devnet)', ['sol_usd_fallback' => $fallback]);
            return [
                'sol_per_usdc'  => round(1 / $fallback, 6),
                'sol_usd'       => $fallback,
                'usdc_usd'      => (float) config('services.tokens.usdc_usd_fallback', 1.0),
                'decimals'      => null,
                'usdc_decimals' => null,
                'source'        => 'static_fallback',
                'updated_at'    => now()->toDateTimeString(),
            ];
        }

        // Remap the (mint-agnostic) spump_* keys the shared helpers
        // return into sol_* keys, for clarity at every SOL call site.
        $normalized = [
            'sol_per_usdc'  => $result['spump_per_usdc'],
            'sol_usd'       => $result['spump_usd'],
            'usdc_usd'      => $result['usdc_usd'],
            'decimals'      => $result['decimals'],
            'usdc_decimals' => $result['usdc_decimals'],
            'source'        => $result['source'],
            'updated_at'    => now()->toDateTimeString(),
        ];

        Cache::put('sol_price_data', $normalized, 60);

        return $normalized;
    }

    /**
     * SOL → SPUMP at live rates. Goes through USD rather than needing a
     * direct SOL/SPUMP pool: sol_usd / spump_usd. Returns null if either
     * leg's rate is unavailable — callers must treat that as "can't
     * price this right now", never silently charge/store 0.
     */
    public function convertSolToSpump(float $solAmount): ?float
    {
        $solRate   = $this->getSolUsdcRate();
        $spumpRate = $this->getSpumpUsdcRate();
        if (!$solRate || !$spumpRate || (float) $spumpRate['spump_usd'] <= 0) {
            return null;
        }
        $usdValue = $solAmount * (float) $solRate['sol_usd'];
        return round($usdValue / (float) $spumpRate['spump_usd'], 6);
    }

    /**
     * SOL → USDC at live rates.
     */
    public function convertSolToUsdc(float $solAmount): ?float
    {
        $solRate = $this->getSolUsdcRate();
        if (!$solRate || (float) ($solRate['usdc_usd'] ?? 0) <= 0) {
            return null;
        }
        $usdValue = $solAmount * (float) $solRate['sol_usd'];
        return round($usdValue / (float) $solRate['usdc_usd'], 6);
    }

    /**
     * SOL → whichever payment currency string is passed ('spump' or
     * anything else is treated as 'usdc'). Small dispatch helper used
     * wherever an admin SOL-denominated setting (mint_price,
     * platform_fee_amount_sol) needs to be expressed in a specific
     * listing's actual currency.
     */
    public function convertSolTo(float $solAmount, string $currency): ?float
    {
        return strtolower($currency) === 'usdc'
            ? $this->convertSolToUsdc($solAmount)
            : $this->convertSolToSpump($solAmount);
    }

    /**
     * Plain USD → SPUMP at the live rate. Used for the storage fee,
     * which is admin-configured directly in USD (storage_fee_per_mb_usd)
     * rather than SOL.
     */
    public function convertUsdToSpump(float $usdAmount): ?float
    {
        $spumpRate = $this->getSpumpUsdcRate();
        if (!$spumpRate || (float) $spumpRate['spump_usd'] <= 0) {
            return null;
        }
        return round($usdAmount / (float) $spumpRate['spump_usd'], 6);
    }

    /**
     * Plain USD → USDC at the live rate (USDC's own usd price, not
     * assumed to be exactly 1.0).
     */
    public function convertUsdToUsdc(float $usdAmount): ?float
    {
        $spumpRate = $this->getSpumpUsdcRate(); // only need its usdc_usd leg
        $usdcUsd   = $spumpRate['usdc_usd'] ?? null;
        if (!$usdcUsd || $usdcUsd <= 0) {
            return null;
        }
        return round($usdAmount / (float) $usdcUsd, 6);
    }

    /**
     * USD → whichever payment currency string is passed, mirroring
     * convertSolTo() above.
     */
    public function convertUsdTo(float $usdAmount, string $currency): ?float
    {
        return strtolower($currency) === 'usdc'
            ? $this->convertUsdToUsdc($usdAmount)
            : $this->convertUsdToSpump($usdAmount);
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
