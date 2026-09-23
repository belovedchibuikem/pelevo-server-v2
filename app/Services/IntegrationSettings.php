<?php

namespace App\Services;

use App\Integrations\PodcastIndex\PodcastIndexClient;
use App\Integrations\PodcastIndex\PodcastIndexException;
use App\Mail\SmtpConnectionTest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class IntegrationSettings
{
    /** @return array<string, array{group:string,title:string,description:string,fields:array<int, array{name:string,label:string,type:string,secret?:bool,options?:array<int,string>,option_labels?:array<string,string>}>,config:array<string,string>}> */
    public function catalog(): array
    {
        return [
            'podcast_index' => ['group' => 'Catalog', 'title' => 'Podcast Index', 'description' => 'Show discovery plus capped episode seeding (paged max≤1000). Pelevo owns RSS hydration for full archives, rankings, recommendations, and Home rails.', 'fields' => [
                ['name' => 'enabled', 'label' => 'Enabled', 'type' => 'select', 'options' => ['1', '0']],
                ['name' => 'base_url', 'label' => 'API base URL', 'type' => 'text'],
                ['name' => 'api_key', 'label' => 'API key', 'type' => 'password', 'secret' => true],
                ['name' => 'api_secret', 'label' => 'API secret', 'type' => 'password', 'secret' => true],
                ['name' => 'user_agent', 'label' => 'User agent', 'type' => 'text'],
            ], 'config' => ['enabled' => 'services.podcast_index.enabled', 'base_url' => 'services.podcast_index.base_url', 'api_key' => 'services.podcast_index.api_key', 'api_secret' => 'services.podcast_index.api_secret', 'user_agent' => 'services.podcast_index.user_agent']],
            'ai' => ['group' => 'Intelligence', 'title' => 'AI provider', 'description' => 'Summaries, chapters, and transcripts. The AI desk stays closed until a URL and token exist.', 'fields' => [
                ['name' => 'provider', 'label' => 'Provider name', 'type' => 'text'],
                ['name' => 'url', 'label' => 'Inference URL', 'type' => 'text'],
                ['name' => 'token', 'label' => 'API token', 'type' => 'password', 'secret' => true],
                ['name' => 'model', 'label' => 'Model', 'type' => 'text'],
            ], 'config' => ['provider' => 'services.ai.provider', 'url' => 'services.ai.url', 'token' => 'services.ai.token', 'model' => 'services.ai.model']],
            'mux' => ['group' => 'Media', 'title' => 'Mux', 'description' => 'Optional HLS encoding and playback. Reels currently transcode with FFmpeg until Mux is enabled in a later media cutover.', 'fields' => [
                ['name' => 'token_id', 'label' => 'Access token ID', 'type' => 'text'],
                ['name' => 'token_secret', 'label' => 'Access token secret', 'type' => 'password', 'secret' => true],
                ['name' => 'signing_key', 'label' => 'Signing key (optional)', 'type' => 'password', 'secret' => true],
            ], 'config' => ['token_id' => 'services.mux.token_id', 'token_secret' => 'services.mux.token_secret', 'signing_key' => 'services.mux.signing_key']],
            'ffmpeg' => ['group' => 'Media', 'title' => 'FFmpeg (current reel pipeline)', 'description' => 'Local binaries used to probe duration and transcode reels.', 'fields' => [
                ['name' => 'ffmpeg_binary', 'label' => 'ffmpeg path', 'type' => 'text'],
                ['name' => 'ffprobe_binary', 'label' => 'ffprobe path', 'type' => 'text'],
            ], 'config' => ['ffmpeg_binary' => 'media.ffmpeg_binary', 'ffprobe_binary' => 'media.ffprobe_binary']],
            'smtp' => ['group' => 'Email', 'title' => 'SMTP', 'description' => 'Admin password resets, claim codes, and operator mail. Save credentials, then send a test message to an inbox you can open. TLS on port 587 is STARTTLS.', 'fields' => [
                ['name' => 'mailer', 'label' => 'Mailer', 'type' => 'select', 'options' => ['smtp', 'log', 'array']],
                ['name' => 'host', 'label' => 'Host', 'type' => 'text'],
                ['name' => 'port', 'label' => 'Port', 'type' => 'number'],
                ['name' => 'username', 'label' => 'Username', 'type' => 'text'],
                ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'secret' => true],
                ['name' => 'scheme', 'label' => 'Encryption', 'type' => 'select', 'options' => ['smtp', 'smtps'], 'option_labels' => ['smtp' => 'TLS / STARTTLS (port 587)', 'smtps' => 'SSL / SMTPS (port 465)']],
                ['name' => 'from_address', 'label' => 'From address', 'type' => 'text'],
                ['name' => 'from_name', 'label' => 'From name', 'type' => 'text'],
            ], 'config' => ['mailer' => 'mail.default', 'host' => 'mail.mailers.smtp.host', 'port' => 'mail.mailers.smtp.port', 'username' => 'mail.mailers.smtp.username', 'password' => 'mail.mailers.smtp.password', 'scheme' => 'mail.mailers.smtp.scheme', 'from_address' => 'mail.from.address', 'from_name' => 'mail.from.name']],
            'paystack' => ['group' => 'Payments', 'title' => 'Paystack', 'description' => 'Premium checkout, payouts, and webhooks.', 'fields' => [
                ['name' => 'secret_key', 'label' => 'Secret key', 'type' => 'password', 'secret' => true],
                ['name' => 'webhook_secret', 'label' => 'Webhook secret', 'type' => 'password', 'secret' => true],
                ['name' => 'checkout_url', 'label' => 'Checkout URL', 'type' => 'text'],
            ], 'config' => ['secret_key' => 'services.paystack.checkout_token', 'webhook_secret' => 'services.paystack.webhook_secret', 'checkout_url' => 'services.paystack.checkout_url']],
            'flutterwave' => ['group' => 'Payments', 'title' => 'Flutterwave', 'description' => 'Premium checkout and webhook verification.', 'fields' => [
                ['name' => 'secret_key', 'label' => 'Secret key', 'type' => 'password', 'secret' => true],
                ['name' => 'webhook_secret', 'label' => 'Webhook secret', 'type' => 'password', 'secret' => true],
            ], 'config' => ['secret_key' => 'services.flutterwave.checkout_token', 'webhook_secret' => 'services.flutterwave.webhook_secret']],
            'paypal' => ['group' => 'Payments', 'title' => 'PayPal', 'description' => 'Earn and creator payouts to a verified PayPal email on the mobile app. Not used for in-app coin purchases (those stay Apple/Google IAP).', 'fields' => [
                ['name' => 'access_token', 'label' => 'Access token', 'type' => 'password', 'secret' => true],
                ['name' => 'payout_url', 'label' => 'Payout dispatch URL', 'type' => 'text'],
                ['name' => 'payout_verification_url', 'label' => 'Destination verification URL', 'type' => 'text'],
            ], 'config' => ['access_token' => 'services.paypal.payout_token', 'payout_url' => 'services.paypal.payout_url', 'payout_verification_url' => 'services.paypal.payout_verification_url']],
            's3' => ['group' => 'Storage', 'title' => 'Object storage (S3 / R2)', 'description' => 'Presigned uploads for reels, artwork, and share cards.', 'fields' => [
                ['name' => 'key', 'label' => 'Access key', 'type' => 'text'],
                ['name' => 'secret', 'label' => 'Secret', 'type' => 'password', 'secret' => true],
                ['name' => 'region', 'label' => 'Region', 'type' => 'text'],
                ['name' => 'bucket', 'label' => 'Bucket', 'type' => 'text'],
                ['name' => 'disk', 'label' => 'Upload disk', 'type' => 'select', 'options' => ['s3', 'local']],
            ], 'config' => ['key' => 'filesystems.disks.s3.key', 'secret' => 'filesystems.disks.s3.secret', 'region' => 'filesystems.disks.s3.region', 'bucket' => 'filesystems.disks.s3.bucket', 'disk' => 'media.upload_disk']],
        ];
    }

    public function applyToConfig(): void
    {
        // Composer scripts (`package:discover`) boot the app while Forge may be
        // restarting Postgres. Never fail the deploy for a missing overlay.
        if ($this->isComposerLifecycleCommand()) {
            return;
        }

        try {
            if (! Schema::hasTable('integration_settings')) {
                return;
            }
            foreach ($this->catalog() as $provider => $definition) {
                $values = $this->decrypted($provider);
                if ($values === []) {
                    continue;
                }
                foreach ($definition['config'] as $field => $key) {
                    if (! array_key_exists($field, $values) || $values[$field] === null || $values[$field] === '') {
                        continue;
                    }
                    $value = $values[$field];
                    if (in_array($field, ['enabled'], true)) {
                        $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    }
                    if ($field === 'port') {
                        $value = (int) $value;
                    }
                    if ($provider === 'smtp' && $field === 'scheme') {
                        $value = $this->normalizeSmtpScheme((string) $value);
                    }
                    config([$key => $value]);
                }
                if ($provider === 'paypal' && filled(config('services.paypal.payout_token'))) {
                    config(['services.paypal.payout_verification_token' => config('services.paypal.payout_token')]);
                }
            }
        } catch (Throwable) {
            return;
        }
    }

    private function isComposerLifecycleCommand(): bool
    {
        if (! app()->runningInConsole()) {
            return false;
        }

        $command = (string) ($_SERVER['argv'][1] ?? '');

        return in_array($command, ['package:discover', 'vendor:publish'], true);
    }

    /** @return list<array<string, mixed>> */
    public function present(): array
    {
        $rows = Schema::hasTable('integration_settings') ? DB::table('integration_settings')->get()->keyBy('provider') : collect();
        $groups = [];
        foreach ($this->catalog() as $provider => $definition) {
            $stored = $this->decrypted($provider);
            $fields = [];
            foreach ($definition['fields'] as $field) {
                $envValue = config($definition['config'][$field['name']]);
                $value = $stored[$field['name']] ?? $envValue;
                if ($provider === 'smtp' && $field['name'] === 'scheme') {
                    $value = $this->normalizeSmtpScheme((string) ($value ?? ''));
                }
                $secret = (bool) ($field['secret'] ?? false);
                $configured = filled($value);
                $fields[] = [
                    ...$field,
                    'configured' => $configured,
                    'hint' => $secret ? ($configured ? 'Stored · last 4 '.$this->lastFour((string) $value) : 'Not set') : null,
                    'value' => $secret ? '' : (is_bool($value) ? ($value ? '1' : '0') : (string) ($value ?? '')),
                ];
            }
            $row = $rows[$provider] ?? null;
            $groups[] = [
                'provider' => $provider,
                'group' => $definition['group'],
                'title' => $definition['title'],
                'description' => $definition['description'],
                'fields' => $fields,
                'source' => $stored === [] ? 'environment' : 'database',
                'last_tested_at' => $row?->last_tested_at,
                'last_test_state' => $row?->last_test_state,
                'last_test_message' => $row?->last_test_message,
            ];
        }

        return $groups;
    }

    public function save(string $provider, array $incoming, string $adminId, string $reason): void
    {
        $definition = $this->catalog()[$provider] ?? null;
        abort_unless($definition, 404);
        $current = $this->decrypted($provider);
        $payload = [];
        foreach ($definition['fields'] as $field) {
            $name = $field['name'];
            $secret = (bool) ($field['secret'] ?? false);
            if ($secret && (! array_key_exists($name, $incoming) || $incoming[$name] === null || $incoming[$name] === '')) {
                $payload[$name] = $current[$name] ?? config($definition['config'][$name]);

                continue;
            }
            $payload[$name] = $incoming[$name] ?? null;
        }
        if ($provider === 'smtp') {
            $payload['scheme'] = $this->normalizeSmtpScheme((string) ($payload['scheme'] ?? ''));
        }
        DB::table('integration_settings')->updateOrInsert(['provider' => $provider], ['payload_encrypted' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), 'updated_by' => $adminId, 'reason' => $reason, 'updated_at' => now(), 'created_at' => DB::table('integration_settings')->where('provider', $provider)->value('created_at') ?? now()]);
        $this->applyToConfig();
    }

    /** @return array{ok:bool,message:string} */
    public function test(string $provider, array $options = []): array
    {
        $this->applyToConfig();
        $result = match ($provider) {
            'podcast_index' => $this->testPodcastIndex(),
            'ai' => $this->testAi(),
            'mux' => $this->testMux(),
            'ffmpeg' => $this->testFfmpeg(),
            'smtp' => $this->testSmtp($options),
            'paystack' => $this->testHttpBearer((string) config('services.paystack.checkout_token'), 'https://api.paystack.co/bank?perPage=1', 'Paystack'),
            'flutterwave' => $this->testHttpBearer((string) config('services.flutterwave.checkout_token'), 'https://api.flutterwave.com/v3/banks/NG', 'Flutterwave'),
            'paypal' => $this->testPaypal(),
            's3' => filled(config('filesystems.disks.s3.bucket')) && filled(config('filesystems.disks.s3.key'))
                ? ['ok' => true, 'message' => 'Access key and bucket are present. A live list-objects check still needs AWS credentials accepted by the bucket policy.']
                : ['ok' => false, 'message' => 'Bucket or access key is missing.'],
            default => ['ok' => false, 'message' => 'No connectivity probe is defined for this provider.'],
        };
        if (Schema::hasTable('integration_settings') && DB::table('integration_settings')->where('provider', $provider)->exists()) {
            DB::table('integration_settings')->where('provider', $provider)->update([
                'last_tested_at' => now(),
                'last_test_state' => $result['ok'] ? 'ok' : 'failed',
                'last_test_message' => $result['message'],
                'updated_at' => now(),
            ]);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function decrypted(string $provider): array
    {
        $ciphertext = DB::table('integration_settings')->where('provider', $provider)->value('payload_encrypted');
        if (! is_string($ciphertext) || $ciphertext === '') {
            return [];
        }
        try {
            $decoded = json_decode(Crypt::decryptString($ciphertext), true);

            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function lastFour(string $value): string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? 'none' : str_repeat('•', max(0, strlen($trimmed) - 4)).substr($trimmed, -4);
    }

    /** @return array{ok:bool,message:string} */
    private function testPodcastIndex(): array
    {
        if (! config('services.podcast_index.enabled')) {
            return ['ok' => false, 'message' => 'Podcast Index is disabled.'];
        }
        if (! filled(config('services.podcast_index.api_key')) || ! filled(config('services.podcast_index.api_secret'))) {
            return ['ok' => false, 'message' => 'API key or secret is missing.'];
        }
        try {
            $feeds = app(PodcastIndexClient::class)->searchByTerm('pelevo', 1);
            $count = count($feeds['feeds'] ?? $feeds['items'] ?? []);

            return ['ok' => true, 'message' => 'Podcast Index accepted the signed request. Sample matches: '.$count.'.'];
        } catch (PodcastIndexException $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }
    }

    /** @return array{ok:bool,message:string} */
    private function testAi(): array
    {
        $url = (string) config('services.ai.url');
        if ($url === '') {
            return ['ok' => false, 'message' => 'Inference URL is not set.'];
        }
        $response = Http::withToken((string) config('services.ai.token'))->connectTimeout(3)->timeout(8)->acceptJson()->post($url, ['probe' => true, 'model' => config('services.ai.model')]);

        return $response->successful()
            ? ['ok' => true, 'message' => 'AI provider responded HTTP '.$response->status().'.']
            : ['ok' => false, 'message' => 'AI provider returned HTTP '.$response->status().'.'];
    }

    /** @return array{ok:bool,message:string} */
    private function testMux(): array
    {
        $id = (string) config('services.mux.token_id');
        $secret = (string) config('services.mux.token_secret');
        if ($id === '' || $secret === '') {
            return ['ok' => false, 'message' => 'Mux token id or secret is missing.'];
        }
        $response = Http::withBasicAuth($id, $secret)->connectTimeout(3)->timeout(8)->get('https://api.mux.com/video/v1/assets', ['limit' => 1]);

        return $response->successful()
            ? ['ok' => true, 'message' => 'Mux API accepted the access token.']
            : ['ok' => false, 'message' => 'Mux returned HTTP '.$response->status().'. Check the token pair.'];
    }

    /** @return array{ok:bool,message:string} */
    private function testFfmpeg(): array
    {
        $binary = (string) config('media.ffmpeg_binary', 'ffmpeg');
        $result = Process::timeout(8)->run([$binary, '-version']);

        return $result->successful()
            ? ['ok' => true, 'message' => trim(strtok($result->output(), "\n") ?: 'ffmpeg is available.')]
            : ['ok' => false, 'message' => $result->errorOutput() !== '' ? $result->errorOutput() : 'ffmpeg is not executable at '.$binary];
    }

    /** @return array{ok:bool,message:string} */
    private function testSmtp(array $options = []): array
    {
        $to = strtolower(trim((string) ($options['to'] ?? '')));
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Enter the email address that should receive the test message.'];
        }

        $mailer = (string) config('mail.default');
        if (! in_array($mailer, ['smtp', 'log', 'array'], true)) {
            return ['ok' => false, 'message' => 'Mailer must be smtp, log, or array.'];
        }

        $host = (string) config('mail.mailers.smtp.host');
        $port = (int) config('mail.mailers.smtp.port');

        if ($mailer === 'smtp') {
            config(['mail.mailers.smtp.timeout' => 8]);
            $unreachable = $this->probeSmtpSocket($host, $port);
            if ($unreachable !== null) {
                return ['ok' => false, 'message' => $unreachable];
            }
            Mail::purge('smtp');
        }

        try {
            Mail::mailer($mailer)->to($to)->send(new SmtpConnectionTest(
                $host,
                $port,
                (string) config('mail.from.address'),
                now()->timezone(config('app.timezone'))->toDayDateTimeString(),
            ));
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => $this->smtpFailureMessage($exception, $host, $port)];
        }

        if ($mailer !== 'smtp') {
            return ['ok' => false, 'message' => 'The '.$mailer.' mailer does not send through Mailtrap. Set Mailer to smtp, save, then send the test again.'];
        }

        return ['ok' => true, 'message' => 'Test email accepted by '.$host.' for '.$to.'. Open that inbox and spam folder to confirm it arrived.'];
    }

    private function probeSmtpSocket(string $host, int $port): ?string
    {
        if ($host === '' || $port < 1) {
            return 'SMTP host or port is missing.';
        }

        $socket = @fsockopen($host, $port, $errno, $errstr, 5.0);
        if ($socket !== false) {
            fclose($socket);

            return null;
        }

        $detail = trim($errstr) !== '' ? $errstr : 'connection timed out';
        $hint = '';
        if ($port === 587) {
            $alternate = @fsockopen($host, 2525, $alternateErrno, $alternateError, 3.0);
            if ($alternate !== false) {
                fclose($alternate);
                $hint = ' Port 2525 is reachable from this machine. Change Port to 2525, keep TLS / STARTTLS, save, and send the test again.';
            }
        }

        return "Could not reach {$host}:{$port} in 5 seconds ({$detail}). Outbound port {$port} is often blocked on local WAMP or by the ISP.".$hint;
    }

    private function smtpFailureMessage(Throwable $exception, string $host, int $port): string
    {
        $message = trim($exception->getMessage());
        $lower = strtolower($message);
        if ($message === '') {
            $message = $exception::class.' while talking to '.$host.':'.$port;
        }
        if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
            return "Timed out talking to {$host}:{$port}. ".$message;
        }
        if (str_contains($lower, 'authentication') || str_contains($lower, '535') || str_contains($lower, '534')) {
            return 'Mailtrap rejected the username or password. Username should be api and the password should be the Bulk Stream token. '.$message;
        }

        return $message;
    }

    private function normalizeSmtpScheme(string $scheme): string
    {
        return match (strtolower(trim($scheme))) {
            'smtps', 'ssl' => 'smtps',
            default => 'smtp',
        };
    }

    /** @return array{ok:bool,message:string} */
    private function testPaypal(): array
    {
        $token = (string) config('services.paypal.payout_token');
        if ($token === '') {
            return ['ok' => false, 'message' => 'PayPal access token is missing.'];
        }
        $verification = (string) config('services.paypal.payout_verification_url');
        $url = $verification !== '' ? $verification : 'https://api-m.paypal.com/v1/identity/oauth2/userinfo?schema=paypalv1.1';

        return $this->testHttpBearer($token, $url, 'PayPal');
    }

    /** @return array{ok:bool,message:string} */
    private function testHttpBearer(string $token, string $url, string $label): array
    {
        if ($token === '') {
            return ['ok' => false, 'message' => $label.' secret key is missing.'];
        }
        $response = Http::withToken($token)->connectTimeout(3)->timeout(8)->get($url);

        return $response->successful()
            ? ['ok' => true, 'message' => $label.' accepted the secret key.']
            : ['ok' => false, 'message' => $label.' returned HTTP '.$response->status().'.'];
    }
}
