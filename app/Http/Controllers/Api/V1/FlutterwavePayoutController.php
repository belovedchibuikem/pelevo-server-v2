<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\Payments\FlutterwaveBanks;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

final class FlutterwavePayoutController extends Controller
{
    public function countries(FlutterwaveBanks $banks): JsonResponse
    {
        return ApiResponse::success($banks->countries());
    }

    public function banks(Request $request, FlutterwaveBanks $banks): JsonResponse
    {
        $data = $request->validate(['country' => ['required', 'string', 'size:2']]);
        try {
            return ApiResponse::success($banks->banks($data['country']));
        } catch (RuntimeException $exception) {
            return $this->mapFailure($exception, 'Flutterwave banks could not be loaded right now.');
        } catch (Throwable) {
            return ApiResponse::error('SERVICE_DEGRADED', 'Flutterwave banks could not be loaded right now.', 503);
        }
    }

    public function resolve(Request $request, FlutterwaveBanks $banks): JsonResponse
    {
        $data = $request->validate([
            'country' => ['required', 'string', 'size:2'],
            'bank_code' => ['required', 'string', 'max:20'],
            'account_number' => ['required', 'digits_between:8,12'],
        ]);
        if (! $banks->supports($data['country'])) {
            return ApiResponse::error('VALIDATION', 'That Flutterwave country is not supported.', 422);
        }
        try {
            $resolved = $banks->resolve($data['bank_code'], $data['account_number']);
        } catch (RuntimeException $exception) {
            return $this->mapFailure($exception, 'Account name verification is unavailable right now.');
        } catch (Throwable) {
            return ApiResponse::error('SERVICE_DEGRADED', 'Account name verification is unavailable right now.', 503);
        }
        if (! $resolved['verified'] || $resolved['account_name'] === null) {
            return ApiResponse::error('VALIDATION', 'We could not verify that account number with the selected bank.', 422);
        }

        return ApiResponse::success([
            'account_name' => $resolved['account_name'],
            'account_number' => $data['account_number'],
            'bank_code' => $data['bank_code'],
            'country' => strtoupper($data['country']),
            'verified' => true,
        ]);
    }

    private function mapFailure(RuntimeException $exception, string $fallback): JsonResponse
    {
        $message = $exception->getMessage();
        if (str_starts_with($message, 'FLUTTERWAVE_PROVIDER:')) {
            $provider = trim(substr($message, strlen('FLUTTERWAVE_PROVIDER:')));

            return ApiResponse::error(
                'SERVICE_DEGRADED',
                $provider !== '' ? $provider : $fallback,
                503,
            );
        }

        return match ($message) {
            'UNSUPPORTED_COUNTRY' => ApiResponse::error('VALIDATION', 'That Flutterwave country is not supported.', 422),
            'FLUTTERWAVE_UNCONFIGURED' => ApiResponse::error('SERVICE_DEGRADED', 'Flutterwave payouts are not configured yet.', 503),
            default => ApiResponse::error('SERVICE_DEGRADED', $fallback, 503),
        };
    }
}
