<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pinata IPFS Configuration
    |--------------------------------------------------------------------------
    */
    // Pinata (IPFS) — removed. Images/metadata are now stored on local
    // disk via App\Services\LocalStorageService (storage/app/public).
    /*
    |--------------------------------------------------------------------------
    | Solana Configuration
    |--------------------------------------------------------------------------
    */
    'solana' => [
        'network' => env('SOLANA_NETWORK', 'devnet'),
        'rpc_url' => env('SOLANA_RPC_URL', 'https://api.devnet.solana.com'),
        'node_binary' => env('NODE_BINARY_PATH', 'node'),
    ],

    'platform' => [
    // Platform fee is now a flat SOL amount (admin-configurable via the
    // 'platform_fee_amount_sol' PlatformSetting), not a percentage of
    // the sale price — this .env value is only the fallback used when
    // no PlatformSetting row exists yet.
    'fee_amount_sol' => env('PLATFORM_FEE_AMOUNT_SOL', 0.01),
    'wallet'      => env('PLATFORM_WALLET'),
    'delegate_wallet'        => env('PLATFORM_DELEGATE_WALLET'),
    'delegate_keypair_path'  => env('PLATFORM_DELEGATE_KEYPAIR_PATH'),
    ],

    // The two accepted SPL-token PAYMENT currencies across the platform
    // (mint, list, buy) remain SPUMP (primary/reference) and USDC (the
    // alternative) — SOL is still never a payment currency anywhere
    // except the network/gas fee (a Solana protocol requirement).
    //
    // SOL *is*, however, now the admin's pricing BASE: mint_price and
    // platform_fee_amount_sol are configured in SOL and converted live
    // into SPUMP/USDC at charge time (see SolanaService::convertSolTo()).
    // 'sol_mint' is the canonical wrapped-SOL mint address — it's the
    // same on every Solana cluster, so it isn't an env override like
    // the others.
    //
    // IMPORTANT: SPUMP_MINT_ADDRESS must be set in .env or every
    // SPUMP/USDC (and therefore SOL/SPUMP) rate lookup will fail
    // silently and the frontend will show "Loading rate..." forever.
    'tokens' => [
        'spump_mint' => env('SPUMP_MINT_ADDRESS'),
        'usdc_mint'  => env('USDC_MINT_ADDRESS', '4zMMC9srt5Ri5X14GAgXhaHii3GnPAEERYPJgZJDncDU'), // devnet USDC default
        'sol_mint'   => 'So11111111111111111111111111111111111111112', // wrapped SOL, same on every cluster
        // Final-resort rate fallback: a known-good SPUMP/USDC pool's own
        // Dexscreener pair address, read directly if every other rate
        // source (Jupiter, Raydium, Dexscreener-by-mint) fails. Override
        // in .env as SPUMP_USDC_POOL_ADDRESS if the pool migrates.
        // (Restored here — this key previously only existed in the
        // unused app/Services/services.php duplicate, so the pool
        // fallback tier was silently a no-op.)
        'spump_usdc_pool' => env('SPUMP_USDC_POOL_ADDRESS', '2gkymgcngo7ZVJD4899cHkJ1B91fkHv3ZnohCuxsU67n'),

        // LAST-RESORT static fallback prices (USD), used ONLY when every
        // live source above (Jupiter/Raydium/Dexscreener) fails to find
        // a price at all — which is the NORMAL, expected case on devnet:
        // those are all real mainnet liquidity sources, so a devnet-only
        // test mint (SOLANA_NETWORK=devnet) will never have a route on
        // any of them, no matter how many fallback tiers are added.
        // Leave unset in production/mainnet — live pricing is always
        // preferred and this is never consulted if any live source
        // succeeds. Set these in devnet .env so pricing (storage fee,
        // mint price, platform fee) doesn't come back null/0 during
        // testing. USDC defaults to $1 (its peg) since that's true
        // regardless of which mint address represents it.
        'spump_usd_fallback' => env('SPUMP_USD_FALLBACK_PRICE'),
        'sol_usd_fallback'   => env('SOL_USD_FALLBACK_PRICE'),
        'usdc_usd_fallback'  => env('USDC_USD_FALLBACK_PRICE', 1.0),
    ],

];
