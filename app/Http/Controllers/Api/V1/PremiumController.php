<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Finance\ActivatePremiumPayment;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class PremiumController extends Controller
{
    public function plans(): JsonResponse
    {
        return ApiResponse::success(DB::table('premium_plans')->where('active', true)->orderBy('price_minor')->orderBy('id')->get()->map(fn (object $row): array => $this->presentedPlan($row))->values());
    }

    public function me(Request $request): JsonResponse
    {
        $entitlement = DB::table('premium_entitlements')->where('user_id', $request->user()->id)->where('state', 'active')->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))->latest('starts_at')->first();
        $subscription = DB::table('premium_subscriptions')->where('user_id', $request->user()->id)->latest()->first();

        return ApiResponse::success([
            'entitlement' => $entitlement ? $this->presentedEntitlement($entitlement) : null,
            'subscription' => $subscription ? $this->presentedSubscription($subscription) : null,
        ]);
    }

    public function invoices(Request $request): JsonResponse
    {
        return ApiResponse::success(DB::table('invoices')->where('user_id', $request->user()->id)->select('id', 'provider_invoice_id', 'subtotal_minor', 'tax_minor', 'total_minor', 'currency', 'state', 'receipt_url', 'paid_at', 'created_at')->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(20)->items());
    }

    public function exclusive(Request $request): JsonResponse
    {
        $active = DB::table('premium_entitlements')->where('user_id', $request->user()->id)->where('state', 'active')->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))->exists();
        if (! $active) {
            return ApiResponse::error('PREMIUM_REQUIRED', 'An active Premium entitlement is required.', 403);
        }

        return ApiResponse::success(DB::table('premium_exclusive_episodes')->join('episodes', 'episodes.id', '=', 'premium_exclusive_episodes.episode_id')->join('shows', 'shows.id', '=', 'episodes.show_id')->where(fn ($query) => $query->whereNull('premium_exclusive_episodes.starts_at')->orWhere('premium_exclusive_episodes.starts_at', '<=', now()))->where(fn ($query) => $query->whereNull('premium_exclusive_episodes.ends_at')->orWhere('premium_exclusive_episodes.ends_at', '>', now()))->select('episodes.id', 'episodes.show_id', 'episodes.title', 'episodes.duration_seconds', 'episodes.published_at', 'shows.title as show_title', 'shows.artwork_url')->orderByDesc('episodes.published_at')->orderByDesc('episodes.id')->cursorPaginate(20)->items());
    }

    public function checkout(Request $request): JsonResponse
    {
        if (! config('premium.public_enabled')) {
            return ApiResponse::error('SERVICE_DEGRADED', 'Premium checkout is awaiting payment sign-off.', 503);
        }
        $key = (string) $request->header('Idempotency-Key');
        if ($key === '') {
            return ApiResponse::error('VALIDATION', 'Idempotency-Key header is required.', 422);
        }
        $data = $request->validate(['plan_id' => ['required', 'exists:premium_plans,id'], 'provider' => ['required', 'in:paystack,flutterwave']]);
        $plan = DB::table('premium_plans')->where('id', $data['plan_id'])->where('active', true)->first();
        if (! $plan) {
            return ApiResponse::error('VALIDATION', 'The selected Premium plan is unavailable.', 422);
        }
        $hash = hash('sha256', json_encode([$request->user()->id, $plan->id, $data['provider'], $plan->price_minor, $plan->currency], JSON_THROW_ON_ERROR));
        $existing = DB::table('premium_checkouts')->where('idempotency_key', $key)->first();
        if ($existing) {
            return hash_equals($existing->request_hash, $hash) ? ApiResponse::success($this->presentedCheckout($existing)) : ApiResponse::error('CONFLICT', 'Idempotency key was reused with different checkout values.', 409);
        }
        $endpoint = config("services.{$data['provider']}.checkout_url");
        if (! $endpoint) {
            return ApiResponse::error('SERVICE_DEGRADED', 'Checkout provider credentials are not configured.', 503);
        }
        $id = (string) Str::ulid();
        $providerReference = 'premium-'.$id;
        try {
            $response = Http::withToken((string) config("services.{$data['provider']}.checkout_token"))->connectTimeout(3)->timeout(15)->post($endpoint, ['reference' => $providerReference, 'amount' => $plan->price_minor, 'currency' => $plan->currency, 'email' => $request->user()->email, 'callback_url' => config('app.url').'/premium/return'])->throw()->json();
        } catch (Throwable) {
            return ApiResponse::error('SERVICE_DEGRADED', 'Checkout provider is unavailable.', 503);
        }
        DB::table('premium_checkouts')->insert(['id' => $id, 'user_id' => $request->user()->id, 'premium_plan_id' => $plan->id, 'provider' => $data['provider'], 'provider_reference' => $providerReference, 'idempotency_key' => $key, 'request_hash' => $hash, 'amount_minor' => $plan->price_minor, 'currency' => $plan->currency, 'state' => 'pending', 'checkout_url' => data_get($response, 'authorization_url') ?? data_get($response, 'data.link'), 'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now()]);

        return ApiResponse::success($this->presentedCheckout(DB::table('premium_checkouts')->find($id)), status: 201);
    }

    public function changePlan(Request $request): JsonResponse
    {
        return $this->checkout($request);
    }

    public function restore(Request $request, ActivatePremiumPayment $activate): JsonResponse
    {
        $data = $request->validate(['provider' => ['required', 'in:paystack,flutterwave'], 'reference' => ['required', 'string', 'max:191']]);
        $endpoint = config("services.{$data['provider']}.restore_url");
        if (! $endpoint) {
            return ApiResponse::error('SERVICE_DEGRADED', 'Premium restore provider is unavailable.', 503);
        }
        $payment = Http::withToken((string) config("services.{$data['provider']}.checkout_token"))->connectTimeout(3)->timeout(15)->get($endpoint, ['reference' => $data['reference']])->throw()->json();
        if (($payment['paid'] ?? false) !== true) {
            return ApiResponse::error('PREMIUM_UNVERIFIED', 'The Premium payment is not verified.', 422);
        }
        $checkout = DB::table('premium_checkouts')->where('provider', $data['provider'])->where('provider_reference', $data['reference'])->where('user_id', $request->user()->id)->first();
        if (! $checkout) {
            return ApiResponse::error('NOT_FOUND', 'Premium checkout not found.', 404);
        }

        return ApiResponse::success($this->presentedInvoice($activate->handle($data['provider'], $payment)));
    }

    private function presentedPlan(object $row): array
    {
        return [
            'id' => $row->id,
            'slug' => $row->slug,
            'name' => $row->name,
            'price_minor' => (int) $row->price_minor,
            'currency' => $row->currency,
            'interval' => $row->interval,
        ];
    }

    private function presentedEntitlement(object $row): array
    {
        return [
            'state' => $row->state,
            'starts_at' => $row->starts_at,
            'ends_at' => $row->ends_at,
            'plan_id' => $row->premium_plan_id,
        ];
    }

    private function presentedSubscription(object $row): array
    {
        return [
            'id' => $row->id,
            'state' => $row->state,
            'provider' => $row->provider,
            'current_period_start' => $row->current_period_start,
            'current_period_end' => $row->current_period_end,
            'plan_id' => $row->premium_plan_id,
            'cancel_at' => $row->cancel_at,
        ];
    }

    private function presentedCheckout(object $row): array
    {
        return [
            'id' => $row->id,
            'state' => $row->state,
            'checkout_url' => $row->checkout_url,
            'expires_at' => $row->expires_at,
            'amount_minor' => (int) $row->amount_minor,
            'currency' => $row->currency,
            'provider' => $row->provider,
        ];
    }

    private function presentedInvoice(object $row): array
    {
        return [
            'id' => $row->id,
            'subtotal_minor' => (int) $row->subtotal_minor,
            'tax_minor' => (int) $row->tax_minor,
            'total_minor' => (int) $row->total_minor,
            'currency' => $row->currency,
            'state' => $row->state,
            'receipt_url' => $row->receipt_url,
            'paid_at' => $row->paid_at,
            'created_at' => $row->created_at,
        ];
    }
}
