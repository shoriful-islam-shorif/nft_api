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
    'pinata' => [
        'api_key'    => env('PINATA_API_KEY'),
        'secret_key' => env('PINATA_SECRET_API_KEY'),
        'gateway'    => env('PINATA_GATEWAY', 'https://ipfs.io/ipfs/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Solana Configuration
    |--------------------------------------------------------------------------
    */
    'solana' => [
        'network'     => env('SOLANA_NETWORK', 'devnet'),
        'rpc_url'     => env('SOLANA_RPC_URL', 'https://api.devnet.solana.com'),
        'node_binary' => env('NODE_BINARY_PATH', 'node'),
    ],

    'platform' => [
        'fee_percent'            => env('PLATFORM_FEE_PERCENT', 3),
        'wallet'                 => env('PLATFORM_WALLET'),
        // Separate hot wallet used ONLY as the mpl-core TransferDelegate
        // authority for marketplace sales — deliberately not the same
        // key as the fee-receiving treasury wallet above, so a
        // compromise of one doesn't automatically compromise the other.
        'delegate_wallet'        => env('PLATFORM_DELEGATE_WALLET'),
        'delegate_keypair_path'  => env('PLATFORM_DELEGATE_KEYPAIR_PATH'),
    ],

    // The two accepted SPL-token payment currencies across the
    // platform (mint, list, buy) — SPUMP is the primary/reference
    // currency; USDC is the alternative. SOL is not accepted as a
    // payment currency anywhere except the network/gas fee, which is
    // always paid in SOL regardless (a Solana protocol requirement).
    'tokens' => [
        'spump_mint' => env('SPUMP_MINT_ADDRESS'),
        'usdc_mint'  => env('USDC_MINT_ADDRESS', '4zMMC9srt5Ri5X14GAgXhaHii3GnPAEERYPJgZJDncDU'), // devnet USDC default
    ],

];
