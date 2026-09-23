<?php

namespace App\Services;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class MailPreference
{
    public function alertsEnabled(string $userId): bool
    {
        $row = DB::table('notification_preferences')->where('user_id', $userId)->first();
        $options = $this->decode($row?->mobile_options ?? null);
        if (! array_key_exists('email_enabled', $options)) {
            return true;
        }

        return (bool) $options['email_enabled'];
    }

    public function emailFor(string $userId, bool $activeOnly = true): ?string
    {
        $query = DB::table('users')->where('id', $userId);
        if ($activeOnly) {
            $query->where('status', 'active');
        }

        return $this->normalize((string) $query->value('email'));
    }

    public function queueAlert(string $userId, Mailable $mail): void
    {
        if (! $this->alertsEnabled($userId)) {
            return;
        }
        $this->dispatch($this->emailFor($userId), $mail, true);
    }

    public function queueToUser(string $userId, Mailable $mail): void
    {
        $this->queueTransactional($this->emailFor($userId), $mail);
    }

    public function queueToCreator(string $creatorProfileId, Mailable $mail): void
    {
        $userId = DB::table('creator_profiles')->where('id', $creatorProfileId)->value('user_id');
        if (is_string($userId) && $userId !== '') {
            $this->queueToUser($userId, $mail);
        }
    }

    public function queueTransactional(?string $email, Mailable $mail): void
    {
        $this->dispatch($email, $mail, false);
    }

    private function dispatch(?string $email, Mailable $mail, bool $queue): void
    {
        $email = $this->normalize($email);
        if ($email === null) {
            return;
        }
        try {
            if (! Mail::isFake()) {
                app(IntegrationSettings::class)->applyToConfig();
                if (! config('mail.mailers.smtp.timeout')) {
                    config(['mail.mailers.smtp.timeout' => 15]);
                }
                if (! $queue) {
                    Mail::purge('smtp');
                }
            }
            $pending = Mail::to($email);
            $queue ? $pending->queue($mail) : $pending->sendNow($mail);
        } catch (\Throwable $error) {
            Log::warning($queue ? 'mail.queue_failed' : 'mail.send_failed', [
                'error' => $error->getMessage(),
                'mail' => $mail::class,
                'to' => $email,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $json): array
    {
        if (! is_string($json) || $json === '') {
            return [];
        }
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function normalize(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }
}
