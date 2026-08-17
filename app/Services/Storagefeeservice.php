<?php

namespace App\Services;

use App\Models\PlatformSetting;

class StorageFeeService
{
    public function __construct(private SolanaService $solana) {}

    /**
     * SPUMP owed for one year of storage, given a file size in bytes.
     * Rate is admin-configurable (storage_fee_per_mb_spump in
     * platform_settings), same pattern as mint_price/platform_fee_percent
     * — never hardcoded here.
     */
    public function calculateAnnualFeeSpump(int $bytes): float
    {
        $ratePerMb = (float) PlatformSetting::get('storage_fee_per_mb_spump', 10);
        $mb        = $bytes / (1024 * 1024);
        return round($mb * $ratePerMb, 6);
    }

    /**
     * Converts a SPUMP amount into the equivalent USDC amount using the
     * live Jupiter rate — same conversion this platform already uses
     * for mint fees and purchases. Returns null if the rate is
     * unavailable (caller should treat that as "can't verify a USDC
     * payment right now", not as a free pass).
     */
    public function spumpToUsdc(float $spumpAmount): ?float
    {
        $rateData = $this->solana->getSpumpUsdcRate();
        if (!$rateData || (float) $rateData['spump_per_usdc'] <= 0) {
            return null;
        }
        return $spumpAmount / (float) $rateData['spump_per_usdc'];
    }
}
