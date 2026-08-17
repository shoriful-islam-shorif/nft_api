<?php

namespace App\Console\Commands;

use App\Models\Nft;
use Illuminate\Console\Command;

/**
 * Reports NFTs whose storage_paid_until has passed without renewal.
 *
 * Deliberately does NOT delete anything. Whether an unpaid NFT's image
 * actually gets removed, hidden, or just flagged is a policy decision
 * (it affects marketplace listings, past buyers, and possibly legal
 * expectations set at sale time) — this command surfaces the list so
 * that decision can be made explicitly, e.g. by an admin action or a
 * follow-up command once the policy is decided.
 *
 * Suggested schedule (in app/Console/Kernel.php):
 *   $schedule->command('storage:check-expired')->daily();
 */
class CheckExpiredStorage extends Command
{
    protected $signature = 'storage:check-expired';
    protected $description = 'List NFTs whose storage fee has expired without renewal (read-only report)';

    public function handle(): int
    {
        $expired = Nft::where('status', 'minted')
            ->whereNotNull('storage_paid_until')
            ->where('storage_paid_until', '<', now())
            ->get(['id', 'name', 'mint_address', 'wallet_address', 'storage_paid_until', 'storage_fee_spump']);

        if ($expired->isEmpty()) {
            $this->info('No NFTs with expired, unrenewed storage.');
            return self::SUCCESS;
        }

        $this->warn("{$expired->count()} NFT(s) have expired storage:");
        $this->table(
            ['ID', 'Name', 'Mint Address', 'Owner Wallet', 'Expired On', 'Renewal Fee (SPUMP)'],
            $expired->map(fn($n) => [
                $n->id, $n->name, $n->mint_address, $n->wallet_address,
                $n->storage_paid_until->toDateString(), $n->storage_fee_spump,
            ])
        );

        $this->line("\nThese are reported only — nothing was deleted or hidden.");
        $this->line("Decide and implement an actual policy (grace period, notify owner, hide from marketplace, delete file) before automating any action here.");

        return self::SUCCESS;
    }
}
