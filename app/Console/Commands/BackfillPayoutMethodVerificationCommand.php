<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class BackfillPayoutMethodVerificationCommand extends Command
{
    protected $signature = 'pelevo:backfill-payout-verification {--dry-run : Show how many rows would be updated}';

    protected $description = 'Mark existing PayPal emails and name-enquired bank accounts as verified so withdrawals are not blocked by the old proof gate.';

    public function handle(): int
    {
        $paypalQuery = DB::table('payout_methods')
            ->where('kind', 'paypal')
            ->whereNull('verified_at');

        $bankQuery = DB::table('payout_methods')
            ->where('kind', 'bank')
            ->whereNotNull('verification_reference')
            ->where('verification_reference', '!=', '')
            ->whereNull('verified_at');

        $paypalCount = (clone $paypalQuery)->count();
        $bankCount = (clone $bankQuery)->count();

        if ($this->option('dry-run')) {
            $this->info("Would verify {$paypalCount} PayPal and {$bankCount} bank payout method(s).");

            return self::SUCCESS;
        }

        $now = now();
        $paypalUpdated = $paypalQuery->update(['verified_at' => $now, 'updated_at' => $now]);
        $bankUpdated = $bankQuery->update(['verified_at' => $now, 'updated_at' => $now]);

        $this->info("Verified {$paypalUpdated} PayPal and {$bankUpdated} bank payout method(s).");

        return self::SUCCESS;
    }
}
