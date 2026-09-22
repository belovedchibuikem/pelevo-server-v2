<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PushDispatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class SendTestPushCommand extends Command
{
    protected $signature = 'pelevo:push-test {user : User email or id} {--latest-episode : Replay the newest new-episode inbox alert instead of the generic test message}';

    protected $description = 'Send a lock-screen test alert to one account\'s registered FCM devices.';

    public function handle(PushDispatch $push): int
    {
        $lookup = trim((string) $this->argument('user'));
        $account = User::query()->where('id', $lookup)->orWhere('email', $lookup)->first();
        if (! $account) {
            $this->error('No user matched that id or email.');

            return self::FAILURE;
        }

        $message = [
            'type' => 'new_episode',
            'title' => 'Pelevo test alert',
            'body' => 'Lock-screen push is working.',
            'data' => ['type' => 'new_episode'],
        ];
        if ($this->option('latest-episode')) {
            $latest = DB::table('notifications')
                ->where('user_id', $account->id)
                ->where('type', 'new_episode')
                ->orderByDesc('created_at')
                ->first();
            if (! $latest) {
                $this->error('That account has no new-episode inbox row to replay.');

                return self::FAILURE;
            }
            $data = json_decode((string) $latest->data, true);
            $message = [
                'type' => 'new_episode',
                'key' => 'push-test:'.$latest->id,
                'title' => $latest->title,
                'body' => $latest->body,
                'data' => is_array($data) ? $data : ['type' => 'new_episode'],
            ];
            $this->line('Replaying inbox alert: '.$latest->title.' ('.$latest->created_at.')');
        }

        $sent = $push->notifyUser($account->id, $message);

        if ($sent < 1) {
            $this->error('FCM accepted 0 tokens for '.$account->email.'. Check laravel.log for push.skipped_no_tokens, push.skipped_disabled, or push.dispatch_rejected.');

            return self::FAILURE;
        }

        $this->info("FCM accepted {$sent} device(s) for {$account->email}. The phone should show a lock-screen / banner alert now.");

        return self::SUCCESS;
    }
}
