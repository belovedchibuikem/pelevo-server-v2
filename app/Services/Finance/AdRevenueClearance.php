<?php

namespace App\Services\Finance;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cleared ad revenue is the sum of USD rows whose 30-day release time has passed.
 * The read path uses the row timestamp, so a monthly script cannot extend the hold.
 */
final class AdRevenueClearance
{
    public function __construct(private readonly DisplayFx $display) {}

    /**
     * @param  iterable<int, string>  $creatorIds
     * @return array{pending_usd_micros: int, cleared_usd_micros: int}
     */
    public function micros(iterable $creatorIds): array
    {
        $ids = Collection::wrap($creatorIds)->filter()->values();
        if ($ids->isEmpty()) {
            return ['pending_usd_micros' => 0, 'cleared_usd_micros' => 0];
        }

        $now = now()->toDateTimeString();
        $row = DB::table('creator_ad_revenue_allocations')
            ->whereIn('creator_profile_id', $ids)
            ->where('status', '!=', 'reversed')
            ->whereNull('reversed_at')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN held_until > ? AND status IN (\'pending\', \'confirmed\') THEN gross_usd_micros ELSE 0 END), 0) AS pending_usd_micros, COALESCE(SUM(CASE WHEN held_until <= ? AND status IN (\'pending\', \'confirmed\') THEN gross_usd_micros ELSE 0 END), 0) AS matured_usd_micros',
                [$now, $now],
            )
            ->first();
        $available = (int) DB::table('financial_accounts')
            ->where('owner_type', 'App\\Models\\CreatorProfile')
            ->whereIn('owner_id', $ids)
            ->where('type', 'reels_ad_available')
            ->where('unit', 'USD')
            ->sum('balance');

        return [
            'pending_usd_micros' => (int) ($row->pending_usd_micros ?? 0),
            'cleared_usd_micros' => (int) ($row->matured_usd_micros ?? 0) + $available,
        ];
    }

    /**
     * @param  iterable<int, string>  $creatorIds
     * @return array<string, mixed>
     */
    public function present(iterable $creatorIds, ?string $countryCode): array
    {
        $micros = $this->micros($creatorIds);
        $clearedCents = $this->centsFromMicros($micros['cleared_usd_micros']);
        $display = $this->display->present($clearedCents, $countryCode);

        return [
            ...$display,
            'pending_ad_usd' => TokenValuation::formatCents($this->centsFromMicros($micros['pending_usd_micros'])),
            'cleared_ad_usd' => $display['available_usd'],
            'pending_ad_usd_micros' => $micros['pending_usd_micros'],
            'cleared_ad_usd_micros' => $micros['cleared_usd_micros'],
            'minimum_cashout_usd' => TokenValuation::formatCents(TokenValuation::AD_MIN_USD_CENTS),
            'cashout_eligible' => $clearedCents >= TokenValuation::AD_MIN_USD_CENTS,
            'hold_hours' => TokenValuation::AD_HOLD_HOURS,
        ];
    }

    private function centsFromMicros(int $micros): int
    {
        if ($micros <= 0) {
            return 0;
        }
        $base = intdiv($micros, 10_000);
        $remainder = $micros % 10_000;

        return $base + ($remainder >= 5_000 ? 1 : 0);
    }
}
