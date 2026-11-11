<?php

namespace App\Actions\Finance;

use App\Integrations\Payments\FlutterwaveTransfers;
use App\Support\CountryCurrency;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pays approved Flutterwave bank cashouts as one bulk transfer per currency
 * after the wallet can cover the total.
 */
final class PayDiamondCashouts
{
    public function __construct(
        private readonly FlutterwaveTransfers $flutterwave,
        private readonly SettleDiamondCashout $settle,
    ) {}

    /**
     * @return array{configured: bool, message: ?string, currencies: list<array<string, mixed>>}
     */
    public function preview(): array
    {
        if (! $this->flutterwave->configured()) {
            return [
                'configured' => false,
                'message' => 'Add FLUTTERWAVE_SECRET_KEY before diamond cashouts can be paid.',
                'currencies' => [],
            ];
        }

        $groups = $this->approvedFlutterwave()->groupBy('currency');
        if ($groups->isEmpty()) {
            return [
                'configured' => true,
                'message' => 'No approved Flutterwave bank cashouts are waiting.',
                'currencies' => [],
            ];
        }

        $currencies = [];
        foreach ($groups as $currency => $rows) {
            $required = 0.0;
            foreach ($rows as $row) {
                $required += $this->major((int) $row->net_minor, (string) $currency);
            }
            $available = null;
            $error = null;
            try {
                $available = $this->flutterwave->availableBalance((string) $currency);
            } catch (ConnectionException) {
                $error = 'Flutterwave could not be reached.';
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
            $shortfall = $available === null ? $required : max(0, round($required - $available, 2));
            $currencies[] = [
                'currency' => (string) $currency,
                'count' => $rows->count(),
                'required' => $this->money($required, (string) $currency),
                'available' => $available === null ? null : $this->money($available, (string) $currency),
                'shortfall' => $this->money($shortfall, (string) $currency),
                'can_pay' => $error === null && $shortfall <= 0,
                'error' => $error,
            ];
        }

        return ['configured' => true, 'message' => null, 'currencies' => $currencies];
    }

    /**
     * @param  list<string>  $ids
     * @return array{ok: bool, code: string, message: string, sent: int, blocked: list<array{id: string, reason: string}>}
     */
    public function handle(array $ids, string $reason, ?string $adminId, ?string $requestId, ?string $ip): array
    {
        if (! $this->flutterwave->configured()) {
            return $this->result(false, 'FLUTTERWAVE_UNCONFIGURED', 'Flutterwave is not configured. Add FLUTTERWAVE_SECRET_KEY before paying.', 0, []);
        }

        $ready = [];
        $blocked = [];
        foreach ($this->candidates($ids) as $row) {
            if ((string) $row->provider !== 'flutterwave' || (string) $row->kind !== 'bank') {
                $blocked[] = ['id' => (string) $row->id, 'reason' => 'Only verified Flutterwave bank accounts are paid in this batch.'];

                continue;
            }
            $destination = $this->destination($row);
            if ($destination === null) {
                $blocked[] = ['id' => (string) $row->id, 'reason' => 'The saved bank account is missing a bank code or account number.'];

                continue;
            }
            $ready[] = [
                'id' => (string) $row->id,
                'currency' => strtoupper((string) $row->currency),
                'amount' => $this->major((int) $row->net_minor, (string) $row->currency),
                'reference' => 'dc-'.$row->id,
                'bank_code' => $destination['bank_code'],
                'account_number' => $destination['account_number'],
                'narration' => 'Pelevo diamond cashout',
            ];
        }
        if ($ready === []) {
            return $this->result(false, 'NOTHING_TO_PAY', 'No approved Flutterwave bank cashouts are ready to pay.', 0, $blocked);
        }

        $byCurrency = collect($ready)->groupBy('currency');
        foreach ($byCurrency as $currency => $items) {
            try {
                $available = $this->flutterwave->availableBalance((string) $currency);
            } catch (Throwable $exception) {
                return $this->result(false, 'FLUTTERWAVE_UNAVAILABLE', 'Flutterwave could not confirm the '.$currency.' balance. No transfer was sent.', 0, $blocked);
            }
            $required = array_sum($items->pluck('amount')->all());
            if ($available + 0.0000001 < $required) {
                return $this->result(false, 'INSUFFICIENT_FUNDS', sprintf(
                    'Flutterwave %s balance is %s. This batch needs %s. No transfer was sent.',
                    $currency,
                    $this->money($available, (string) $currency),
                    $this->money($required, (string) $currency),
                ), 0, $blocked);
            }
        }

        $sent = 0;
        $warnings = [];
        foreach ($byCurrency as $currency => $items) {
            $locked = $this->markProcessing($items->all());
            if ($locked === []) {
                continue;
            }
            $result = $this->flutterwave->bulk('Pelevo diamond cashouts '.now()->toDateString(), array_map(fn (array $item): array => [
                'bank_code' => $item['bank_code'],
                'account_number' => $item['account_number'],
                'amount' => $item['amount'],
                'currency' => $item['currency'],
                'narration' => $item['narration'],
                'reference' => $item['reference'],
                'debit_currency' => $item['currency'],
            ], $locked));
            if ($result['outcome'] === 'rejected') {
                $this->revert($locked);

                return $this->result(false, 'FLUTTERWAVE_REJECTED', $result['message'].($sent > 0 ? ' '.$sent.' earlier transfers in this batch were already queued.' : ' No transfer was sent.'), $sent, $blocked);
            }
            if (in_array($result['outcome'], ['unknown', 'duplicate'], true)) {
                $warnings[] = $result['message'];
            }
            $sent += count($locked);
        }

        if ($adminId !== null) {
            DB::table('audit_logs')->insert([
                'id' => (string) Str::ulid(),
                'admin_id' => $adminId,
                'action' => 'diamond_cashout.flutterwave_batch',
                'subject_type' => 'App\\Models\\DiamondCashout',
                'subject_id' => $ready[0]['id'],
                'reason' => $reason,
                'after' => json_encode(['sent' => $sent, 'blocked' => $blocked], JSON_THROW_ON_ERROR),
                'request_id' => $requestId,
                'ip_address' => $ip,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $message = $sent === 1
            ? '1 cashout was sent to Flutterwave. It stays processing until Flutterwave confirms payment.'
            : $sent.' cashouts were sent to Flutterwave. They stay processing until Flutterwave confirms payment.';
        if ($blocked !== []) {
            $message .= ' '.count($blocked).' could not be included.';
        }
        if ($warnings !== []) {
            $message .= ' '.implode(' ', $warnings);
        }

        return $this->result(true, 'QUEUED', $message, $sent, $blocked);
    }

    /**
     * @return array{ok: bool, code: string, message: string}
     */
    public function refresh(string $cashoutId): array
    {
        $cashout = DB::table('diamond_cashouts')->where('id', $cashoutId)->first();
        if (! $cashout) {
            return ['ok' => false, 'code' => 'NOT_FOUND', 'message' => 'Diamond cashout not found.'];
        }
        if (! is_string($cashout->provider_reference) || $cashout->provider_reference === '') {
            return ['ok' => false, 'code' => 'NOT_SENT', 'message' => 'This cashout has not been sent to Flutterwave yet.'];
        }
        $remote = $this->flutterwave->findByReference($cashout->provider_reference);
        if ($remote === null) {
            return ['ok' => true, 'code' => 'PENDING', 'message' => 'Flutterwave has not confirmed this transfer yet.'];
        }
        $state = $this->mapStatus($remote['status']);
        if ($state === null) {
            return ['ok' => true, 'code' => 'PENDING', 'message' => 'Flutterwave status is '.$remote['status'].'.'];
        }
        $changed = $this->settle->apply($cashout->provider_reference, $state, $remote['message']);

        return [
            'ok' => true,
            'code' => $changed ? strtoupper($state) : 'UNCHANGED',
            'message' => $changed ? 'Cashout marked '.$state.'.' : 'Cashout is already '.$cashout->state.'.',
        ];
    }

    /**
     * @param  list<string>  $ids
     */
    private function candidates(array $ids)
    {
        return DB::table('diamond_cashouts')
            ->join('payout_methods', 'payout_methods.id', '=', 'diamond_cashouts.payout_method_id')
            ->where('diamond_cashouts.state', 'approved')
            ->when($ids !== [], fn ($query) => $query->whereIn('diamond_cashouts.id', $ids))
            ->orderBy('diamond_cashouts.id')
            ->get([
                'diamond_cashouts.id',
                'diamond_cashouts.currency',
                'diamond_cashouts.net_minor',
                'payout_methods.provider',
                'payout_methods.kind',
                'payout_methods.destination_encrypted',
            ]);
    }

    private function approvedFlutterwave()
    {
        return DB::table('diamond_cashouts')
            ->join('payout_methods', 'payout_methods.id', '=', 'diamond_cashouts.payout_method_id')
            ->where('diamond_cashouts.state', 'approved')
            ->where('payout_methods.provider', 'flutterwave')
            ->where('payout_methods.kind', 'bank')
            ->get(['diamond_cashouts.id', 'diamond_cashouts.currency', 'diamond_cashouts.net_minor']);
    }

    /**
     * @return array{bank_code: string, account_number: string}|null
     */
    private function destination(object $row): ?array
    {
        try {
            $destination = decrypt($row->destination_encrypted);
        } catch (Throwable) {
            return null;
        }
        if (! is_array($destination)) {
            return null;
        }
        $bank = trim((string) ($destination['bank_code'] ?? ''));
        $account = preg_replace('/\D+/', '', (string) ($destination['account_number'] ?? '')) ?? '';
        if ($bank === '' || $account === '') {
            return null;
        }

        return ['bank_code' => $bank, 'account_number' => $account];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function markProcessing(array $items): array
    {
        $locked = [];
        DB::transaction(function () use ($items, &$locked): void {
            foreach ($items as $item) {
                $updated = DB::table('diamond_cashouts')
                    ->where('id', $item['id'])
                    ->where('state', 'approved')
                    ->update([
                        'state' => 'processing',
                        'provider_reference' => $item['reference'],
                        'failure_reason' => null,
                        'updated_at' => now(),
                    ]);
                if ($updated === 1) {
                    $locked[] = $item;
                }
            }
        }, 3);

        return $locked;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function revert(array $items): void
    {
        DB::transaction(function () use ($items): void {
            foreach ($items as $item) {
                DB::table('diamond_cashouts')
                    ->where('id', $item['id'])
                    ->where('state', 'processing')
                    ->where('provider_reference', $item['reference'])
                    ->update([
                        'state' => 'approved',
                        'provider_reference' => null,
                        'updated_at' => now(),
                    ]);
            }
        }, 3);
    }

    private function major(int $minor, string $currency): float
    {
        $digits = CountryCurrency::fractionDigits($currency);

        return round($minor / (10 ** $digits), $digits);
    }

    private function money(float $amount, string $currency): string
    {
        $digits = CountryCurrency::fractionDigits($currency);

        return $currency.' '.number_format($amount, $digits, '.', ',');
    }

    private function mapStatus(string $status): ?string
    {
        return match (strtoupper($status)) {
            'SUCCESSFUL', 'SUCCESS' => 'paid',
            'FAILED' => 'failed',
            'REVERSED' => 'reversed',
            default => null,
        };
    }

    /**
     * @param  list<array{id: string, reason: string}>  $blocked
     * @return array{ok: bool, code: string, message: string, sent: int, blocked: list<array{id: string, reason: string}>}
     */
    private function result(bool $ok, string $code, string $message, int $sent, array $blocked): array
    {
        return ['ok' => $ok, 'code' => $code, 'message' => $message, 'sent' => $sent, 'blocked' => $blocked];
    }
}
