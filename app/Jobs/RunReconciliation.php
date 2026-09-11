<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RunReconciliation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public readonly string $businessDate)
    {
        $this->onQueue('finance');
    }

    public function uniqueId(): string
    {
        return $this->businessDate;
    }

    public function handle(): void
    {
        $run = DB::table('reconciliation_runs')->where('business_date', $this->businessDate)->first();
        if ($run?->state === 'completed' || $run?->state === 'drift') {
            return;
        }
        $runId = $run?->id ?? (string) Str::ulid();
        if (! $run) {
            DB::table('reconciliation_runs')->insert(['id' => $runId, 'business_date' => $this->businessDate, 'state' => 'running', 'created_at' => now(), 'updated_at' => now()]);
        }
        $checked = 0;
        $drift = 0;
        DB::table('financial_accounts')->orderBy('id')->chunkById(200, function ($accounts) use ($runId, &$checked, &$drift): void {
            foreach ($accounts as $account) {
                $ledger = (int) DB::table('ledger_entries')->where('financial_account_id', $account->id)->sum('amount');
                $difference = $account->balance - $ledger;
                $checked++;
                if ($difference !== 0) {
                    $drift++;
                }
                DB::table('reconciliation_items')->insertOrIgnore(['id' => (string) Str::ulid(), 'reconciliation_run_id' => $runId, 'financial_account_id' => $account->id, 'projected_balance' => $account->balance, 'ledger_balance' => $ledger, 'difference' => $difference, 'state' => $difference === 0 ? 'matched' : 'open', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('reconciliation_items')->where('reconciliation_run_id', $runId)->where('financial_account_id', $account->id)->update(['projected_balance' => $account->balance, 'ledger_balance' => $ledger, 'difference' => $difference, 'state' => $difference === 0 ? 'matched' : 'open', 'updated_at' => now()]);
                if ($difference !== 0 && ! DB::table('finance_exceptions')->where('type', 'ledger_drift')->where('reference', $this->businessDate.':'.$account->id)->exists()) {
                    DB::table('finance_exceptions')->insert(['id' => (string) Str::ulid(), 'type' => 'ledger_drift', 'reference' => $this->businessDate.':'.$account->id, 'state' => 'open', 'evidence' => json_encode(['account_id' => $account->id, 'projected' => $account->balance, 'ledger' => $ledger, 'difference' => $difference], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }, 'id');
        DB::table('reconciliation_runs')->where('id', $runId)->update(['state' => $drift ? 'drift' : 'completed', 'checked_count' => $checked, 'drift_count' => $drift, 'completed_at' => now(), 'updated_at' => now()]);
    }
}
