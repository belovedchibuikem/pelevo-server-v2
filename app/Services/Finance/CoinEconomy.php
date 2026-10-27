<?php

namespace App\Services\Finance;

use App\Models\CreatorProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CoinEconomy
{
    public const UNIT = 'DMN';

    public const RATE_SCALE = 100_000_000;

    public function active(): ?object
    {
        return DB::table('coin_economy_regimes')
            ->where('active', true)
            ->where('effective_at', '<=', now())
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->first();
    }

    public function summary(int $diamonds, ?string $countryCode = null): array
    {
        $regime = $this->active();
        $quote = $regime ? $this->cashoutQuote($diamonds, $regime) : null;

        return [
            'unit' => self::UNIT,
            'balance' => $diamonds,
            'min_cashout_diamonds' => $regime ? (int) $regime->min_cashout_diamonds : null,
            'minimum_cashout_usd' => TokenValuation::formatCents(TokenValuation::GIFT_MIN_USD_CENTS),
            'anchor' => [
                'coins' => TokenValuation::ANCHOR_COINS,
                'usd' => TokenValuation::formatCents(TokenValuation::ANCHOR_USD_CENTS),
                'gross_coin_usd' => TokenValuation::formatScaled(TokenValuation::GROSS_COIN_USD_SCALED),
            ],
            'regime' => $regime ? $this->presentRegime($regime, false) : null,
            'quotes' => $quote ? ['USD' => $quote] : null,
            'display' => $quote ? app(DisplayFx::class)->present($quote['gross_minor'], $countryCode) : null,
            'rules' => [
                'coins_per_diamond' => 1,
                'extra_platform_fee_on_cashout' => false,
                'transfer_fee_deducted_from_creator' => true,
                'ledger_currency' => 'USD',
            ],
        ];
    }

    public function balanceForCreators(iterable $creatorIds): int
    {
        $ids = Collection::wrap($creatorIds)->filter()->values();
        if ($ids->isEmpty()) {
            return 0;
        }

        return (int) DB::table('financial_accounts')
            ->where('owner_type', CreatorProfile::class)
            ->whereIn('owner_id', $ids)
            ->where('type', 'diamond_wallet')
            ->where('unit', self::UNIT)
            ->sum('balance');
    }

    /**
     * Diamond value is the published USD rate. Local currency is applied later from a live FX quote.
     *
     * @return array<string, mixed>
     */
    public function cashoutQuote(int $diamonds, object $regime): array
    {
        $gross = $this->minorForDiamonds($diamonds, (int) $regime->diamond_usd_rate);
        $fee = $diamonds === 0 ? 0 : (int) $regime->transfer_fee_usd_cents;
        $net = $gross - $fee;

        return [
            'currency' => 'USD',
            'diamonds' => $diamonds,
            'gross_minor' => $gross,
            'transfer_fee_minor' => $fee,
            'net_minor' => $net,
            'payable' => $net >= TokenValuation::GIFT_MIN_USD_CENTS,
            'minimum_usd_cents' => TokenValuation::GIFT_MIN_USD_CENTS,
            'rate_includes_app_share' => true,
        ];
    }

    public function minimumDiamonds(int $scaledRate, int $usdCents): int
    {
        $low = 1;
        $high = 10_000_000;
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            if ($this->minorForDiamonds($mid, $scaledRate) >= $usdCents) {
                $high = $mid;
            } else {
                $low = $mid + 1;
            }
        }

        return $low;
    }

    /**
     * @return array{retail_minor: int, store_fee_minor: int, net_minor: int, creator_minor: int, app_minor: int}
     */
    public function split(int $retailMinor, int $storeFeeBasisPoints, int $creatorSplitBasisPoints): array
    {
        $fee = $this->share($retailMinor, $storeFeeBasisPoints);
        $net = $retailMinor - $fee;
        $creator = $this->share($net, $creatorSplitBasisPoints);

        return [
            'retail_minor' => $retailMinor,
            'store_fee_minor' => $fee,
            'net_minor' => $net,
            'creator_minor' => $creator,
            'app_minor' => $net - $creator,
        ];
    }

    public function minorForDiamonds(int $diamonds, int $scaledRate): int
    {
        if ($diamonds < 0 || $scaledRate < 0) {
            throw new InvalidArgumentException('Rate inputs cannot be negative.');
        }
        $divisor = intdiv(self::RATE_SCALE, 100);
        $product = $diamonds * $scaledRate;
        $base = intdiv($product, $divisor);
        $remainder = $product % $divisor;

        return $base + ($remainder * 2 >= $divisor ? 1 : 0);
    }

    public function share(int $amount, int $basisPoints): int
    {
        if ($amount < 0 || $basisPoints < 0) {
            throw new InvalidArgumentException('Split inputs cannot be negative.');
        }
        $product = $amount * $basisPoints;
        $base = intdiv($product, 10_000);
        $remainder = $product % 10_000;

        return $base + ($remainder >= 5_000 ? 1 : 0);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function presentPacks(bool $activeOnly = true): array
    {
        $regime = $this->active();
        $query = DB::table('coin_products')->orderBy('coins')->orderBy('store')->orderBy('product_id');
        if ($activeOnly) {
            $query->where('active', true);
        }

        return $query->get()->map(fn (object $row): array => $this->presentPack($row, $regime))->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function adminPacks(): array
    {
        $regime = $this->active();

        return DB::table('coin_products')->orderBy('store')->orderBy('coins')->limit(80)->get()->map(function (object $row) use ($regime): array {
            $pack = $this->presentPack($row, $regime);
            $pack['active'] = $row->active;
            $pack['economics_label'] = $this->economicsLabel($pack);

            return $pack;
        })->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function adminRegimes(): array
    {
        $live = $this->active();

        return DB::table('coin_economy_regimes')->orderByDesc('effective_at')->limit(40)->get()->map(function (object $row) use ($live): array {
            $presented = $this->presentRegime($row, true);
            $presented['live'] = $live !== null && $live->id === $row->id;

            return $presented;
        })->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function presentRegime(object $row, bool $withReason): array
    {
        $presented = [
            'id' => $row->id,
            'code' => $row->code,
            'name' => $row->name,
            'store_fee_basis_points' => (int) $row->store_fee_basis_points,
            'creator_split_basis_points' => (int) $row->creator_split_basis_points,
            'app_split_basis_points' => (int) $row->app_split_basis_points,
            'store_fee_percent' => ((int) $row->store_fee_basis_points) / 100,
            'creator_split_percent' => ((int) $row->creator_split_basis_points) / 100,
            'app_split_percent' => ((int) $row->app_split_basis_points) / 100,
            'diamond_ngn' => self::formatRate((int) $row->diamond_ngn_rate),
            'diamond_usd' => self::formatRate((int) $row->diamond_usd_rate),
            'transfer_fee_ngn_kobo' => (int) $row->transfer_fee_ngn_kobo,
            'transfer_fee_ngn_min_kobo' => (int) $row->transfer_fee_ngn_min_kobo,
            'transfer_fee_ngn_max_kobo' => (int) $row->transfer_fee_ngn_max_kobo,
            'transfer_fee_usd_cents' => (int) $row->transfer_fee_usd_cents,
            'min_cashout_diamonds' => (int) $row->min_cashout_diamonds,
            'active' => (bool) $row->active,
            'effective_at' => $row->effective_at,
        ];
        if ($withReason) {
            $presented['reason'] = $row->reason;
        }

        return $presented;
    }

    /**
     * @return array<string, mixed>
     */
    public function presentPack(object $row, ?object $regime): array
    {
        $ngn = $row->price_ngn_kobo === null ? null : (int) $row->price_ngn_kobo;
        $usd = $row->price_usd_cents === null ? null : (int) $row->price_usd_cents;
        $economics = null;
        if ($regime && $ngn !== null && $usd !== null) {
            $economics = [
                'regime_code' => $regime->code,
                'store_fee_percent' => ((int) $regime->store_fee_basis_points) / 100,
                'creator_split_percent' => ((int) $regime->creator_split_basis_points) / 100,
                'ngn' => $this->split($ngn, (int) $regime->store_fee_basis_points, (int) $regime->creator_split_basis_points),
                'usd' => $this->split($usd, (int) $regime->store_fee_basis_points, (int) $regime->creator_split_basis_points),
            ];
        }

        return [
            'id' => $row->id,
            'store' => $row->store,
            'product_id' => $row->product_id,
            'coins' => (int) $row->coins,
            'unit' => $row->unit,
            'price_ngn_kobo' => $ngn,
            'price_usd_cents' => $usd,
            'economics' => $economics,
        ];
    }

    public static function scaleRate(string $decimal): int
    {
        if (! preg_match('/^\d+(\.\d{1,8})?$/', $decimal)) {
            throw new InvalidArgumentException('INVALID_RATE');
        }
        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, 8), 8, '0');

        return ((int) $whole) * self::RATE_SCALE + (int) $fraction;
    }

    public static function formatRate(int $scaled): string
    {
        $whole = intdiv($scaled, self::RATE_SCALE);
        $fraction = str_pad((string) ($scaled % self::RATE_SCALE), 8, '0', STR_PAD_LEFT);
        $fraction = rtrim($fraction, '0');

        return $whole.'.'.($fraction === '' ? '0' : $fraction);
    }

    public static function majorToMinor(string $major): int
    {
        if (! preg_match('/^\d+(\.\d{1,2})?$/', $major)) {
            throw new InvalidArgumentException('INVALID_MONEY');
        }
        [$whole, $fraction] = array_pad(explode('.', $major, 2), 2, '0');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

        return ((int) $whole) * 100 + (int) $fraction;
    }

    public static function seed(): void
    {
        $now = now();
        $minimum = new self;
        $standardRate = self::scaleRate(TokenValuation::DIAMOND_USD_STANDARD);
        $smallRate = self::scaleRate(TokenValuation::DIAMOND_USD_SMALL_BUSINESS);
        $regimes = [
            [
                'code' => 'standard',
                'name' => 'Standard store fee (30%)',
                'store_fee_basis_points' => 3000,
                'creator_split_basis_points' => 7000,
                'app_split_basis_points' => 3000,
                'diamond_ngn_rate' => $standardRate,
                'diamond_usd_rate' => $standardRate,
                'min_cashout_diamonds' => $minimum->minimumDiamonds($standardRate, TokenValuation::GIFT_MIN_USD_CENTS),
                'active' => true,
                'effective_at' => '2020-01-01 00:00:00',
                'reason' => 'v1.2 anchor: 300 coins = $0.38. Creator net is $0.0006207 per diamond after the 30% store fee and the 30% platform cut of net. Naira is a live FX display, not a stored rate.',
            ],
            [
                'code' => 'small_business',
                'name' => 'Small Business Program store fee (15%)',
                'store_fee_basis_points' => 1500,
                'creator_split_basis_points' => 7000,
                'app_split_basis_points' => 3000,
                'diamond_ngn_rate' => $smallRate,
                'diamond_usd_rate' => $smallRate,
                'min_cashout_diamonds' => $minimum->minimumDiamonds($smallRate, TokenValuation::GIFT_MIN_USD_CENTS),
                'active' => false,
                'effective_at' => '2020-01-01 00:00:01',
                'reason' => 'v1.2 15% store-fee regime. Creator net is $0.0007537 per diamond. Activate it after Apple and Google small-business enrollment.',
            ],
        ];
        foreach ($regimes as $regime) {
            $exists = DB::table('coin_economy_regimes')->where('code', $regime['code'])->where('effective_at', $regime['effective_at'])->exists();
            if ($exists) {
                continue;
            }
            DB::table('coin_economy_regimes')->insert([
                ...$regime,
                'id' => (string) Str::ulid(),
                'transfer_fee_ngn_kobo' => 5000,
                'transfer_fee_ngn_min_kobo' => 1000,
                'transfer_fee_ngn_max_kobo' => 5000,
                'transfer_fee_usd_cents' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $packs = [
            [300, 'coin_pack_300', 50000],
            [500, 'coin_pack_500', 85000],
            [750, 'coin_pack_750', 125000],
            [1000, 'coin_pack_1000', 170000],
            [1500, 'coin_pack_1500', 250000],
            [2000, 'coin_pack_2000', 335000],
        ];
        foreach (['apple', 'google'] as $store) {
            foreach ($packs as [$coins, $productId, $ngnKobo]) {
                $usdCents = TokenValuation::anchorUsdCents($coins);
                $existing = DB::table('coin_products')->where('store', $store)->where('product_id', $productId)->first();
                if ($existing) {
                    if ($existing->price_ngn_kobo === null || $existing->price_usd_cents === null) {
                        DB::table('coin_products')->where('id', $existing->id)->update([
                            'price_ngn_kobo' => $ngnKobo,
                            'price_usd_cents' => $usdCents,
                            'updated_at' => $now,
                        ]);
                    }

                    continue;
                }
                DB::table('coin_products')->insert([
                    'id' => (string) Str::ulid(),
                    'store' => $store,
                    'product_id' => $productId,
                    'coins' => $coins,
                    'unit' => 'PCN',
                    'price_ngn_kobo' => $ngnKobo,
                    'price_usd_cents' => $usdCents,
                    'active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $pack
     */
    private function economicsLabel(array $pack): string
    {
        if (! is_array($pack['economics'] ?? null)) {
            return $pack['price_ngn_kobo'] === null ? 'Retail price not set' : 'Waiting for an active store-fee regime';
        }
        $economics = $pack['economics'];
        $ngn = $economics['ngn'];
        $usd = $economics['usd'];

        return sprintf(
            'NGN retail %s, fee %s, creator %s · USD retail %s, fee %s, creator %s',
            self::formatMinor((int) $ngn['retail_minor']),
            self::formatMinor((int) $ngn['store_fee_minor']),
            self::formatMinor((int) $ngn['creator_minor']),
            self::formatMinor((int) $usd['retail_minor']),
            self::formatMinor((int) $usd['store_fee_minor']),
            self::formatMinor((int) $usd['creator_minor']),
        );
    }

    private static function formatMinor(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}
