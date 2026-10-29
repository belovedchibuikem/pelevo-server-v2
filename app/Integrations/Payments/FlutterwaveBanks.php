<?php

namespace App\Integrations\Payments;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Flutterwave bank directory and account-name enquiry.
 */
final class FlutterwaveBanks
{
    /**
     * Countries where Flutterwave bank transfers are used in Pelevo.
     *
     * @return list<array{code: string, name: string, currency: string}>
     */
    public function countries(): array
    {
        return [
            ['code' => 'NG', 'name' => 'Nigeria', 'currency' => 'NGN'],
            ['code' => 'GH', 'name' => 'Ghana', 'currency' => 'GHS'],
            ['code' => 'KE', 'name' => 'Kenya', 'currency' => 'KES'],
            ['code' => 'UG', 'name' => 'Uganda', 'currency' => 'UGX'],
            ['code' => 'TZ', 'name' => 'Tanzania', 'currency' => 'TZS'],
            ['code' => 'RW', 'name' => 'Rwanda', 'currency' => 'RWF'],
            ['code' => 'ZM', 'name' => 'Zambia', 'currency' => 'ZMW'],
            ['code' => 'ZA', 'name' => 'South Africa', 'currency' => 'ZAR'],
        ];
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public function banks(string $countryCode): array
    {
        $country = strtoupper(trim($countryCode));
        if ($country === '' || ! $this->supports($country)) {
            throw new RuntimeException('UNSUPPORTED_COUNTRY');
        }
        $response = $this->request('get', 'https://api.flutterwave.com/v3/banks/'.$country);
        $rows = $response['data'] ?? [];
        if (! is_array($rows)) {
            return [];
        }
        $banks = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $code = trim((string) ($row['code'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            if ($code === '' || $name === '') {
                continue;
            }
            $banks[] = ['code' => $code, 'name' => $name];
        }
        usort($banks, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $banks;
    }

    /**
     * @return array{verified: bool, account_name: ?string, reference: ?string}
     */
    public function resolve(string $bankCode, string $accountNumber): array
    {
        $response = $this->request('post', 'https://api.flutterwave.com/v3/accounts/resolve', [
            'account_bank' => $bankCode,
            'account_number' => $accountNumber,
        ]);
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $name = trim((string) ($data['account_name'] ?? ''));

        return [
            'verified' => $name !== '',
            'account_name' => $name === '' ? null : $name,
            'reference' => isset($data['account_number'])
                ? (string) $data['account_number']
                : null,
        ];
    }

    public function supports(string $countryCode): bool
    {
        $country = strtoupper(trim($countryCode));

        return collect($this->countries())->contains(fn (array $row): bool => $row['code'] === $country);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, array $payload = []): array
    {
        $token = (string) config('services.flutterwave.checkout_token');
        if ($token === '') {
            throw new RuntimeException('FLUTTERWAVE_UNCONFIGURED');
        }
        $pending = Http::withToken($token)
            ->connectTimeout(3)
            ->timeout(12)
            ->acceptJson();
        $response = $method === 'post'
            ? $pending->post($url, $payload)
            : $pending->get($url);
        $body = $response->json();
        $json = is_array($body) ? $body : [];
        if ($response->successful()) {
            return $json;
        }
        $message = trim((string) ($json['message'] ?? ''));
        if ($message === '') {
            $message = 'Flutterwave returned HTTP '.$response->status().'.';
        }

        throw new RuntimeException('FLUTTERWAVE_PROVIDER:'.$message);
    }
}
