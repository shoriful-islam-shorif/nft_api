<?php

namespace App\Console\Commands;

use App\Models\Nft;
use Illuminate\Console\Command;

/**
 * Finds NFTs whose storage_paid_until is within the next 30 days (and
 * hasn't already been warned about), marks them as warned, and reports
 * them.
 *
 * NOTE: This does NOT send an actual email/notification yet — this
 * project doesn't have a mail service wired up in what's been shared so
 * far. The hook is marked below with a TODO; plug in Laravel's
 * Notification/Mail facade there once mail config (SMTP/Postmark/etc.)
 * is set up. Until then, this at least gives you a reliable, de-duped
 * list you could act on manually or via another channel (Discord
 * webhook, admin dashboard alert, etc.).
 *
 * Suggested schedule (in app/Console/Kernel.php):
 *   $schedule->command('storage:send-warnings')->daily();
 */
class SendStorageExpiryWarnings extends Command
{
    protected $signature = 'storage:send-warnings';
    protected $description = 'Warn owners whose NFT storage expires within 30 days (report + dedup; wire up actual delivery separately)';

    public function handle(): int
    {
        $windowEnd = now()->addDays(30);

        $expiringSoon = Nft::where('status', 'minted')
            ->whereNotNull('storage_paid_until')
            ->where('storage_paid_until', '>', now())
            ->where('storage_paid_until', '<=', $windowEnd)
            ->whereNull('storage_warning_sent_at')
            ->get(['id', 'name', 'mint_address', 'wallet_address', 'storage_paid_until', 'storage_fee_spump']);

        if ($expiringSoon->isEmpty()) {
            $this->info('No NFTs newly entering the 30-day storage expiry warning window.');
            return self::SUCCESS;
        }

        foreach ($expiringSoon as $nft) {
            // TODO: plug in actual delivery once mail/notifications are
            // configured, e.g.:
            //   Mail::to(...)->send(new StorageExpiringSoon($nft));
            // Wallet addresses aren't email addresses, so this likely
            // needs an in-app notification or a captured contact email
            // — whichever this project ends up using.

            $nft->update(['storage_warning_sent_at' => now()]);
        }

        $this->warn("{$expiringSoon->count()} NFT(s) newly entering the 30-day warning window:");
        $this->table(
            ['ID', 'Name', 'Mint Address', 'Owner Wallet', 'Expires On', 'Renewal Fee (SPUMP)'],
            $expiringSoon->map(fn($n) => [
                $n->id, $n->name, $n->mint_address, $n->wallet_address,
                $n->storage_paid_until->toDateString(), $n->storage_fee_spump,
            ])
        );

        return self::SUCCESS;
    }
}
