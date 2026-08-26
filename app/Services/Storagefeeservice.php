<?php

namespace App\Services;

use App\Models\PlatformSetting;

class StorageFeeService
{
    public function __construct(private SolanaService $solana) {}

    /**
     * USD owed for one year of storage, given a file size in bytes.
     * Rate is admin-configurable (storage_fee_per_mb_usd in
     * platform_settings, entered in plain dollars) — never hardcoded
     * here.
     */
    public function calculateAnnualFeeUsd(int $bytes): float
    {
        $ratePerMb = (float) PlatformSetting::get('storage_fee_per_mb_usd', 0.01);
        $mb        = $bytes / (1024 * 1024);
        return round($mb * $ratePerMb, 6);
    }

    /**
     * The USD storage fee above, converted into SPUMP at the live rate.
     * SPUMP remains this platform's canonical internally-stored
     * currency (mint_price works the same way — admin sets it in SOL,
     * but the Nft row still stores a SPUMP amount) — everything else
     * downstream (payment verification, admin stats) keeps working on
     * SPUMP without needing to know the admin's rate is USD-based.
     * Returns null if the live rate is unavailable — callers must
     * treat that as "can't price this right now", never silently
     * store/charge 0.
     */
    public function calculateAnnualFeeSpump(int $bytes): ?float
    {
        return $this->solana->convertUsdToSpump($this->calculateAnnualFeeUsd($bytes));
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
