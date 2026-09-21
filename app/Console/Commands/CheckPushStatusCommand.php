<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CheckPushStatusCommand extends Command
{
    protected $signature = 'pelevo:push-status';

    protected $description = 'Show whether FCM credentials, device tokens, and recent new-episode alerts are in place for lock-screen push.';

    public function handle(): int
    {
        $path = (string) config('services.fcm.credentials');
        $exists = $path !== '' && is_file($path);
        $projectId = null;
        $complete = false;
        if ($exists) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                $projectId = is_string($decoded['project_id'] ?? null) ? $decoded['project_id'] : null;
                $complete = is_string($decoded['client_email'] ?? null) && $decoded['client_email'] !== ''
                    && is_string($decoded['private_key'] ?? null) && $decoded['private_key'] !== '';
            }
        }

        $this->table(['Check', 'Value'], [
            ['notifications feature', config('features.notifications') ? 'enabled' : 'disabled'],
            ['queue', (string) config('queue.default')],
            ['FCM credentials path', $path === '' ? '(empty)' : $path],
            ['credentials file', $exists ? 'found' : 'MISSING'],
            ['service account', $complete ? 'complete' : 'incomplete'],
            ['Firebase project_id', $projectId ?? '(none)'],
            ['expected project_id', (string) config('services.fcm.project_id')],
            ['active FCM tokens', Schema::hasTable('push_tokens') ? (string) DB::table('push_tokens')->whereNull('revoked_at')->where('provider', 'fcm')->count() : 'n/a'],
            ['active devices', Schema::hasTable('devices') ? (string) DB::table('devices')->whereNull('revoked_at')->count() : 'n/a'],
        ]);

        if (Schema::hasTable('notifications')) {
            $latest = DB::table('notifications')->where('type', 'new_episode')->orderByDesc('created_at')->first(['title', 'created_at', 'user_id']);
            if ($latest) {
                $this->line('Latest new-episode inbox row: '.$latest->title.' at '.$latest->created_at);
            } else {
                $this->line('No new-episode inbox rows yet.');
            }
        }

        $expectedProject = (string) config('services.fcm.project_id');
        if ($projectId !== null && $expectedProject !== '' && $projectId !== $expectedProject) {
            $this->error("Service account project '{$projectId}' does not match the app Firebase project '{$expectedProject}'.");
            $this->line('Download a service-account JSON from Firebase Console → Project settings → Service accounts for pelevo-885af.');
            $this->line('google-services.json / GoogleService-Info.plist already use that project; FCM tokens from the app cannot be sent with a different account.');

            return self::FAILURE;
        }

        if (! $exists || ! $complete) {
            $this->error('Server push cannot run until FCM_CREDENTIALS points at a Firebase service-account JSON for this app\'s project.');
            $this->line('Place the file at storage/app/firebase/service-account.json (or set FCM_CREDENTIALS) and remove any later empty FCM_CREDENTIALS= lines in .env.');
            $this->line('Release APKs talk to production, so Forge must have the same file.');

            return self::FAILURE;
        }

        if (Schema::hasTable('push_tokens') && DB::table('push_tokens')->whereNull('revoked_at')->where('provider', 'fcm')->count() === 0) {
            $this->error('No FCM device tokens are registered. Inbox can still write, but lock-screen push has nowhere to send.');
            $this->line('Open the Android/iOS app while signed in, allow notification permission, then confirm POST /api/v1/me/push-tokens succeeds.');

            return self::FAILURE;
        }

        $this->info('FCM looks configured. If a phone still stays silent, check laravel.log for push.dispatch_rejected and that the app allowed OS notifications.');

        return self::SUCCESS;
    }
}
