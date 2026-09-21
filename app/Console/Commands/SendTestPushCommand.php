<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PushDispatch;
use Illuminate\Console\Command;

final class SendTestPushCommand extends Command
{
    protected $signature = 'pelevo:push-test {user : User email or id}';

    protected $description = 'Send a lock-screen test alert to one account\'s registered FCM devices.';

    public function handle(PushDispatch $push): int
    {
        $lookup = trim((string) $this->argument('user'));
        $account = User::query()->where('id', $lookup)->orWhere('email', $lookup)->first();
        if (! $account) {
            $this->error('No user matched that id or email.');

            return self::FAILURE;
        }

        $sent = $push->notifyUser($account->id, [
            'type' => 'new_episode',
            'title' => 'Pelevo test alert',
            'body' => 'Lock-screen push is working.',
            'data' => ['type' => 'new_episode'],
        ]);

        if ($sent < 1) {
            $this->error('FCM accepted 0 tokens for '.$account->email.'. Check laravel.log for push.skipped_no_tokens, push.skipped_disabled, or push.dispatch_rejected.');

            return self::FAILURE;
        }

        $this->info("FCM accepted {$sent} device(s) for {$account->email}. The phone should show a lock-screen / banner alert now.");

        return self::SUCCESS;
    }
}
