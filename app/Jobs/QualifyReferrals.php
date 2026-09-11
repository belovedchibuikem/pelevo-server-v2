<?php

namespace App\Jobs;

use App\Actions\Finance\PostLedgerTransaction;
use App\Models\FinancialAccount;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class QualifyReferrals implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function handle(PostLedgerTransaction $post): void
    {
        if (! config('features.referrals')) {
            return;
        }
        $program = DB::table('referral_programs')->where('state', 'active')->where('effective_at', '<=', now())->latest('version')->first();
        if (! $program) {
            return;
        }
        DB::table('referrals')->where('state', 'pending')->orderBy('id')->chunkById(100, function ($referrals) use ($program, $post): void {
            foreach ($referrals as $referral) {
                if (DB::table('playback_progress')->where('user_id', $referral->referred_id)->sum('position_seconds') < $program->qualifying_seconds) {
                    continue;
                }
                DB::transaction(function () use ($referral, $program, $post): void {
                    $locked = DB::table('referrals')->where('id', $referral->id)->where('state', 'pending')->lockForUpdate()->first();
                    if (! $locked) {
                        return;
                    }
                    DB::table('referral_qualification_events')->insertOrIgnore(['id' => (string) Str::ulid(), 'referral_id' => $locked->id, 'type' => 'qualified_listening', 'idempotency_key' => 'referral-qualified:'.$locked->id, 'evidence' => json_encode(['program_version' => $program->version], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                    $liability = FinancialAccount::firstOrCreate(['owner_type' => null, 'owner_id' => null, 'type' => 'referral_liability', 'unit' => 'ECN'], ['balance' => 0]);
                    foreach ([[$locked->referrer_id, $program->referrer_reward], [$locked->referred_id, $program->referred_reward]] as [$userId, $coins]) {
                        $user = User::findOrFail($userId);
                        $wallet = FinancialAccount::firstOrCreate(['owner_type' => User::class, 'owner_id' => $user->id, 'type' => 'earn_wallet', 'unit' => 'ECN'], ['balance' => 0]);
                        $key = 'referral-reward:'.$locked->id.':'.$user->id;
                        $tx = $post->handle('referral.rewarded', $key, 'ECN', [['account_id' => $liability->id, 'amount' => -$coins], ['account_id' => $wallet->id, 'amount' => $coins]], ['program_version' => $program->version]);
                        DB::table('referral_rewards')->insertOrIgnore(['id' => (string) Str::ulid(), 'referral_id' => $locked->id, 'user_id' => $user->id, 'ledger_transaction_id' => $tx->id, 'coins' => $coins, 'idempotency_key' => $key, 'created_at' => now(), 'updated_at' => now()]);
                    }
                    DB::table('referrals')->where('id', $locked->id)->update(['state' => 'qualified', 'qualified_at' => now(), 'updated_at' => now()]);
                }, 3);
            }
        });
    }
}
