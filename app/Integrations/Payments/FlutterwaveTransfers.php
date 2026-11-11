<?php

namespace App\Integrations\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Flutterwave v3 transfers. Amounts are major units (naira, not kobo).
 * A timeout is unknown: the transfer may already exist, so callers must not
 * return funds until Flutterwave reports a failure.
 */
final class FlutterwaveTransfers
{
    public function configured(): bool
    {
        return $this->token() !== '';
    }

    public function availableBalance(string $currency): float
    {
        $currency = strtoupper($currency);
        $payload = $this->request('get', 'https://api.flutterwave.com/v3/balances/'.$currency);
        $data = $payload['data'] ?? null;
        if (is_array($data) && isset($data['available_balance'])) {
            return (float) $data['available_balance'];
        }
        if (is_array($data)) {
            foreach ($data as $row) {
                if (is_array($row) && strtoupper((string) ($row['currency'] ?? '')) === $currency) {
                    return (float) ($row['available_balance'] ?? 0);
                }
            }
        }

        throw new RuntimeException('Flutterwave did not return a '.$currency.' balance.');
    }

    /**
     * @param  list<array{bank_code: string, account_number: string, amount: float, currency: string, narration: string, reference: string, debit_currency: string}>  $transfers
     * @return array{outcome: string, message: string, bulk_id: ?string}
     */
    public function bulk(string $title, array $transfers): array
    {
        try {
            $payload = $this->request('post', 'https://api.flutterwave.com/v3/bulk-transfers', [
                'title' => $title,
                'bulk_data' => $transfers,
            ]);
        } catch (ConnectionException $exception) {
            return ['outcome' => 'unknown', 'message' => 'Flutterwave did not respond. The batch was left in processing so it is not sent twice.', 'bulk_id' => null];
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();

            return [
                'outcome' => str_contains(strtolower($message), 'reference') ? 'duplicate' : 'rejected',
                'message' => $message,
                'bulk_id' => null,
            ];
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        return [
            'outcome' => 'accepted',
            'message' => trim((string) ($payload['message'] ?? 'Bulk transfer queued.')),
            'bulk_id' => isset($data['id']) ? (string) $data['id'] : null,
        ];
    }

    /**
     * @return array{status: string, message: ?string}|null
     */
    public function findByReference(string $reference): ?array
    {
        try {
            $payload = $this->request('get', 'https://api.flutterwave.com/v3/transfers', ['reference' => $reference]);
        } catch (ConnectionException) {
            return null;
        }
        $data = $payload['data'] ?? null;
        $row = null;
        if (is_array($data) && array_is_list($data)) {
            $row = $data[0] ?? null;
        } elseif (is_array($data) && isset($data['status'])) {
            $row = $data;
        }
        if (! is_array($row)) {
            return null;
        }

        return [
            'status' => strtoupper((string) ($row['status'] ?? '')),
            'message' => isset($row['complete_message']) ? (string) $row['complete_message'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, array $payload = []): array
    {
        $token = $this->token();
        if ($token === '') {
            throw new RuntimeException('Flutterwave is not configured.');
        }
        $pending = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(20);
        try {
            $response = $method === 'post'
                ? $pending->post($url, $payload)
                : $pending->get($url, $payload);
        } catch (ConnectionException $exception) {
            throw $exception;
        }
        $body = $response->json();
        $json = is_array($body) ? $body : [];
        if ($response->successful() && strtolower((string) ($json['status'] ?? 'success')) !== 'error') {
            return $json;
        }
        $message = trim((string) ($json['message'] ?? ''));
        if ($message === '') {
            $message = 'Flutterwave returned HTTP '.$response->status().'.';
        }
        if ($response->serverError() || $response->status() === 408 || $response->status() === 429) {
            throw new ConnectionException($message);
        }

        throw new RuntimeException($message);
    }

    private function token(): string
    {
        $token = trim((string) config('services.flutterwave.payout_token'));
        if ($token === '') {
            $token = trim((string) config('services.flutterwave.checkout_token'));
        }

        return $token;
    }
}
