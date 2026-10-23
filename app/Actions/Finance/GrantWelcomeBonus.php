<?php

namespace App\Actions\Finance;

use App\Models\FinancialAccount;
use App\Models\LedgerTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Terms section 4.3: the first 500 listeners who verify their email receive
 * 250 gift-wallet coins. The credit is not cashable.
 */
final class GrantWelcomeBonus
{
    public const UNIT = 'PCN';

    public function handle(User $user): ?array
    {
        $user->refresh();
        if ($user->email_verified_at === null || $user->status !== 'active') {
            return null;
        }

        $coins = max(0, (int) config('pelevo.welcome_bonus_coins', 250));
        $cap = max(0, (int) config('pelevo.welcome_bonus_cap', 500));
        if ($coins === 0 || $cap === 0) {
            return null;
        }

        return DB::transaction(function () use ($user, $coins, $cap): ?array {
            $key = 'welcome-bonus:'.$user->id;
            if (LedgerTransaction::where('idempotency_key', $key)->exists()) {
                return null;
            }

            $liability = FinancialAccount::query()->firstOrCreate(
                ['owner_type' => null, 'owner_id' => null, 'type' => 'welcome_bonus_liability', 'unit' => self::UNIT],
                ['balance' => 0],
            );
            $wallet = FinancialAccount::query()->firstOrCreate(
                ['owner_type' => $user::class, 'owner_id' => $user->id, 'type' => 'gift_wallet', 'unit' => self::UNIT],
                ['balance' => 0],
            );
            FinancialAccount::whereIn('id', [$liability->id, $wallet->id])->orderBy('id')->lockForUpdate()->get();

            if (LedgerTransaction::where('idempotency_key', $key)->exists()) {
                return null;
            }
            if (LedgerTransaction::where('event_type', 'welcome.bonus')->count() >= $cap) {
                return null;
            }

            app(PostLedgerTransaction::class)->handle(
                'welcome.bonus',
                $key,
                self::UNIT,
                [
                    ['account_id' => $liability->id, 'amount' => -$coins],
                    ['account_id' => $wallet->id, 'amount' => $coins],
                ],
                ['user_id' => $user->id, 'coins' => $coins],
            );

            return [
                'granted' => true,
                'coins' => $coins,
                'balance' => (int) FinancialAccount::whereKey($wallet->id)->value('balance'),
            ];
        }, 3);
    }
}
