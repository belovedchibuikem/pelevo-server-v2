<?php

use App\Actions\Finance\PostLedgerTransaction;
use App\Models\FinancialAccount;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const COINS = 50_000;

    /** @var list<string> */
    private const EMAILS = [
        'ikecy.chris@gmail.com',
        'belovedchibuikem@gmail.com',
    ];

    public function up(): void
    {
        $liability = FinancialAccount::query()->firstOrCreate(
            ['owner_type' => null, 'owner_id' => null, 'type' => 'ops_gift_credit_liability', 'unit' => 'PCN'],
            ['balance' => 0],
        );

        foreach (self::EMAILS as $email) {
            $user = User::query()->where('email', $email)->first();
            if (! $user) {
                continue;
            }

            $key = 'ops.gift-credit:'.$user->id.':'.self::COINS;
            if (DB::table('ledger_transactions')->where('idempotency_key', $key)->exists()) {
                continue;
            }

            DB::transaction(function () use ($user, $liability, $key): void {
                $wallet = FinancialAccount::query()->firstOrCreate(
                    ['owner_type' => User::class, 'owner_id' => $user->id, 'type' => 'gift_wallet', 'unit' => 'PCN'],
                    ['balance' => 0],
                );

                app(PostLedgerTransaction::class)->handle(
                    'ops.gift_credit',
                    $key,
                    'PCN',
                    [
                        ['account_id' => $liability->id, 'amount' => -self::COINS],
                        ['account_id' => $wallet->id, 'amount' => self::COINS],
                    ],
                    [
                        'user_id' => $user->id,
                        'email' => $user->email,
                        'coins' => self::COINS,
                        'reason' => 'ops_seed_gift_wallet',
                    ],
                );
            }, 3);
        }
    }

    public function down(): void
    {
        // Ledger credits are immutable; do not reverse via migration rollback.
    }
};
