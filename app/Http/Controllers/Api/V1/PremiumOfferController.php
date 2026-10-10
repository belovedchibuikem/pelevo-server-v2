<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\Payments\StoreReceiptVerifier;
use App\Support\ApiResponse;
use App\Support\PremiumTrialOffer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class PremiumOfferController extends Controller
{
    public function session(Request $request, string $offer): JsonResponse
    {
        if ($denied = $this->unknownOffer($offer)) {
            return $denied;
        }
        $plan = $this->trialPlan();
        if (! $plan) {
            return ApiResponse::error('OFFER_UNAVAILABLE', 'The 3-month trial is not available right now.', 404);
        }
        $data = $request->validate(['session_id' => ['required', 'string', 'max:80']]);
        $userId = $request->user()->id;

        $state = DB::transaction(function () use ($userId, $data): object {
            $row = DB::table('premium_offer_states')
                ->where('user_id', $userId)
                ->where('offer_slug', PremiumTrialOffer::SLUG)
                ->lockForUpdate()
                ->first();
            if (! $row) {
                DB::table('premium_offer_states')->insert([
                    'user_id' => $userId,
                    'offer_slug' => PremiumTrialOffer::SLUG,
                    'session_count' => 1,
                    'last_session_id' => $data['session_id'],
                    'last_shown_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } elseif ($row->last_session_id !== $data['session_id']) {
                DB::table('premium_offer_states')
                    ->where('user_id', $userId)
                    ->where('offer_slug', PremiumTrialOffer::SLUG)
                    ->update([
                        'session_count' => (int) $row->session_count + 1,
                        'last_session_id' => $data['session_id'],
                        'updated_at' => now(),
                    ]);
            }

            return DB::table('premium_offer_states')
                ->where('user_id', $userId)
                ->where('offer_slug', PremiumTrialOffer::SLUG)
                ->first();
        }, 3);

        return ApiResponse::success($this->evaluation($plan, $userId, $state));
    }

    public function shown(Request $request, string $offer): JsonResponse
    {
        if ($denied = $this->unknownOffer($offer)) {
            return $denied;
        }
        $userId = $request->user()->id;
        $shownAt = now();
        $existing = DB::table('premium_offer_states')
            ->where('user_id', $userId)
            ->where('offer_slug', PremiumTrialOffer::SLUG)
            ->first();
        if ($existing) {
            DB::table('premium_offer_states')
                ->where('user_id', $userId)
                ->where('offer_slug', PremiumTrialOffer::SLUG)
                ->update(['last_shown_at' => $shownAt, 'updated_at' => $shownAt]);
        } else {
            DB::table('premium_offer_states')->insert([
                'user_id' => $userId,
                'offer_slug' => PremiumTrialOffer::SLUG,
                'session_count' => 0,
                'last_session_id' => null,
                'last_shown_at' => $shownAt,
                'created_at' => $shownAt,
                'updated_at' => $shownAt,
            ]);
        }

        return ApiResponse::success([
            'slug' => PremiumTrialOffer::SLUG,
            'last_shown_at' => $shownAt->toIso8601String(),
        ]);
    }

    public function storePurchase(Request $request, StoreReceiptVerifier $verifier): JsonResponse
    {
        $data = $request->validate([
            'store' => ['required', 'in:apple,google'],
            'receipt' => ['required', 'string', 'max:20000'],
            'plan_id' => ['required', 'exists:premium_plans,id'],
        ]);
        $plan = DB::table('premium_plans')->where('id', $data['plan_id'])->where('active', true)->first();
        if (! $plan) {
            return ApiResponse::error('VALIDATION', 'The selected Premium plan is unavailable.', 422);
        }
        try {
            $verified = $verifier->verify($data['store'], $data['receipt']);
        } catch (RuntimeException $exception) {
            $missing = $exception->getMessage() !== 'IAP_UNVERIFIED';

            return ApiResponse::error(
                $missing ? 'SERVICE_DEGRADED' : 'IAP_UNVERIFIED',
                $missing
                    ? 'Premium store verification is not configured.'
                    : 'The store receipt could not be verified.',
                $missing ? 503 : 422,
            );
        } catch (Throwable) {
            return ApiResponse::error('SERVICE_DEGRADED', 'Premium store verification is unavailable.', 503);
        }

        $expectedProduct = PremiumTrialOffer::storeProductId($plan);
        if (($verified['product_id'] ?? null) !== $expectedProduct) {
            return ApiResponse::error('IAP_UNVERIFIED', 'The verified store product does not match this plan.', 422);
        }

        $userId = $request->user()->id;
        $transactionId = (string) $verified['original_transaction_id'];
        $reference = $data['store'].':'.$transactionId;

        try {
            DB::transaction(function () use ($request, $data, $plan, $verified, $userId, $transactionId, $reference): void {
                $owned = DB::table('premium_entitlements')->where('provider_reference', $reference)->lockForUpdate()->first();
                if ($owned) {
                    if ($owned->user_id !== $userId) {
                        throw new RuntimeException('RECEIPT_CONFLICT');
                    }

                    return;
                }
                if ($this->premiumActive($userId)) {
                    throw new RuntimeException('ALREADY_PREMIUM');
                }
                $trialMonths = (int) ($plan->trial_months ?? 0);
                if ($trialMonths > 0 && DB::table('premium_entitlements')
                    ->where('user_id', $userId)
                    ->whereIn('premium_plan_id', function ($query): void {
                        $query->select('id')->from('premium_plans')->where('trial_months', '>', 0);
                    })
                    ->exists()) {
                    throw new RuntimeException('TRIAL_USED');
                }
                $start = now();
                $end = isset($verified['expires_at'])
                    ? Carbon::parse($verified['expires_at'])
                    : ($trialMonths > 0
                        ? $start->copy()->addMonths($trialMonths)
                        : (PremiumTrialOffer::storeProductId($plan) === PremiumTrialOffer::YEARLY_PRODUCT_ID
                            ? $start->copy()->addYear()
                            : $start->copy()->addMonth()));
                $subscriptionId = (string) Str::ulid();
                DB::table('premium_subscriptions')->insert([
                    'id' => $subscriptionId,
                    'user_id' => $userId,
                    'premium_plan_id' => $plan->id,
                    'provider' => $data['store'],
                    'provider_subscription_id' => $transactionId,
                    'state' => $trialMonths > 0 ? 'trialing' : 'active',
                    'current_period_start' => $start,
                    'current_period_end' => $end,
                    'cancel_at' => null,
                    'created_at' => $start,
                    'updated_at' => $start,
                ]);
                DB::table('premium_entitlements')->insert([
                    'id' => (string) Str::ulid(),
                    'user_id' => $userId,
                    'premium_plan_id' => $plan->id,
                    'provider_reference' => $reference,
                    'starts_at' => $start,
                    'ends_at' => $end,
                    'state' => 'active',
                    'created_at' => $start,
                    'updated_at' => $start,
                ]);
            }, 3);
        } catch (RuntimeException $exception) {
            return match ($exception->getMessage()) {
                'RECEIPT_CONFLICT' => ApiResponse::error('CONFLICT', 'This receipt has already been claimed.', 409),
                'ALREADY_PREMIUM' => ApiResponse::error('ALREADY_PREMIUM', 'This account already has Premium.', 409),
                'TRIAL_USED' => ApiResponse::error('TRIAL_USED', 'The 3-month trial was already used on this account.', 409),
                default => ApiResponse::error('SERVICE_DEGRADED', 'The trial could not be started.', 503),
            };
        }

        return ApiResponse::success($this->account($userId), status: 201);
    }

    private function unknownOffer(string $offer): ?JsonResponse
    {
        return $offer === PremiumTrialOffer::SLUG
            ? null
            : ApiResponse::error('NOT_FOUND', 'Premium offer not found.', 404);
    }

    private function trialPlan(): ?object
    {
        return DB::table('premium_plans')
            ->where('active', true)
            ->where('trial_months', '>=', PremiumTrialOffer::TRIAL_MONTHS)
            ->orderByRaw("case when `interval` = 'year' then 0 else 1 end")
            ->orderBy('price_minor')
            ->first();
    }

    private function premiumActive(string $userId): bool
    {
        return DB::table('premium_entitlements')
            ->where('user_id', $userId)
            ->where('state', 'active')
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->exists();
    }

    private function evaluation(object $plan, string $userId, object $state): array
    {
        $premium = $this->premiumActive($userId);
        $lastShown = $state->last_shown_at ? Carbon::parse($state->last_shown_at) : null;

        return [
            'slug' => PremiumTrialOffer::SLUG,
            'plan_id' => $plan->id,
            'trial_months' => (int) $plan->trial_months,
            'premium' => $premium,
            'eligible' => PremiumTrialOffer::eligible($premium, (int) $state->session_count, $lastShown, now()),
            'session_count' => (int) $state->session_count,
            'last_shown_at' => $lastShown?->toIso8601String(),
            'cooldown_days' => PremiumTrialOffer::COOLDOWN_DAYS,
            'session_interval' => PremiumTrialOffer::SESSION_INTERVAL,
        ];
    }

    private function account(string $userId): array
    {
        $entitlement = DB::table('premium_entitlements')
            ->where('user_id', $userId)
            ->where('state', 'active')
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->latest('starts_at')
            ->first();
        $subscription = DB::table('premium_subscriptions')->where('user_id', $userId)->latest()->first();

        return [
            'entitlement' => $entitlement ? [
                'state' => $entitlement->state,
                'starts_at' => Carbon::parse($entitlement->starts_at)->toIso8601String(),
                'ends_at' => $entitlement->ends_at ? Carbon::parse($entitlement->ends_at)->toIso8601String() : null,
                'plan_id' => $entitlement->premium_plan_id,
            ] : null,
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'state' => $subscription->state,
                'provider' => $subscription->provider,
                'current_period_start' => Carbon::parse($subscription->current_period_start)->toIso8601String(),
                'current_period_end' => Carbon::parse($subscription->current_period_end)->toIso8601String(),
                'plan_id' => $subscription->premium_plan_id,
                'cancel_at' => $subscription->cancel_at ? Carbon::parse($subscription->cancel_at)->toIso8601String() : null,
            ] : null,
        ];
    }
}
