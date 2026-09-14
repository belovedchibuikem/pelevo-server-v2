<?php

use App\Jobs\AccrueReelRevenue;
use App\Jobs\DispatchNotificationBroadcast;
use App\Jobs\ExpireClaims;
use App\Jobs\MaterializeHomeFeed;
use App\Jobs\MaterializeRecommendations;
use App\Jobs\QualifyReferrals;
use App\Jobs\RunReconciliation;
use App\Jobs\ScoreEarnSession;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    $ranAt = now();
    cache()->put('scheduler:last_heartbeat_at', $ranAt->toIso8601String(), 300);
    DB::table('scheduler_heartbeats')->insert([
        'id' => (string) Str::ulid(),
        'host' => gethostname() ?: 'unknown',
        'ran_at' => $ranAt,
        'created_at' => $ranAt,
        'updated_at' => $ranAt,
    ]);
    DB::table('scheduler_heartbeats')->where('ran_at', '<', $ranAt->copy()->subDays(7))->delete();
})->name('scheduler-heartbeat')->everyMinute()->onOneServer();
Schedule::command('horizon:snapshot')->everyFiveMinutes()->onOneServer()->withoutOverlapping();
Schedule::call(function (): void {
    User::query()->where('status', 'active')->select('id')->chunkById(500, fn ($users) => $users->each(fn (User $user) => MaterializeHomeFeed::dispatch($user->id)));
})->name('materialize-home-feeds')->everyTenMinutes()->onOneServer()->withoutOverlapping(20);

Schedule::command('catalog:poll-feeds')->name('rss-poll-due-feeds')->everyFiveMinutes()->onOneServer()->withoutOverlapping(10);

Schedule::command('podcast-index:sync-categories')
    ->name('podcast-index-sync-categories')
    ->dailyAt('03:15')
    ->onOneServer()
    ->withoutOverlapping(30);

Schedule::job(new ExpireClaims, 'claims')
    ->name('expire-creator-claims')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::job(new RunReconciliation(now()->toDateString()), 'finance')
    ->name('daily-financial-reconciliation')
    ->dailyAt('02:00')
    ->onOneServer()
    ->withoutOverlapping(180);

Schedule::call(function (): void {
    DB::table('earn_sessions')->whereIn('state', ['active', 'review'])->orderBy('id')->limit(500)->pluck('id')->each(fn (string $id) => ScoreEarnSession::dispatch($id));
})->name('earn-anomaly-scoring')->everyFifteenMinutes()->onOneServer()->withoutOverlapping(10);

Schedule::job(new AccrueReelRevenue, 'finance')->name('accrue-reel-revenue')->hourly()->onOneServer()->withoutOverlapping(30);
Schedule::job(new QualifyReferrals, 'finance')->name('qualify-referrals')->everyFifteenMinutes()->onOneServer()->withoutOverlapping(10);

Schedule::call(function (): void {
    User::query()->select('id')->chunkById(500, function ($users): void {
        foreach ($users as $user) {
            $progress = DB::table('playback_progress')->where('user_id', $user->id);
            DB::table('listening_daily_stats')->updateOrInsert(['user_id' => $user->id, 'date' => now()->toDateString()], ['listening_seconds' => (int) (clone $progress)->sum('position_seconds'), 'episodes_started' => (clone $progress)->count(), 'episodes_completed' => (clone $progress)->where('completed', true)->count(), 'created_at' => now(), 'updated_at' => now()]);
        }
    });
})->name('materialize-listener-stats')->hourly()->onOneServer()->withoutOverlapping(20);

Schedule::call(function (): void {
    User::query()->where('status', 'active')->select('id')->chunkById(500, fn ($users) => $users->each(fn (User $user) => MaterializeRecommendations::dispatch($user->id)));
})->name('materialize-recommendations')->hourly()->onOneServer()->withoutOverlapping(20);

Schedule::call(function (): void {
    DB::table('notification_broadcasts')->where('state', 'scheduled')->where('scheduled_at', '<=', now())->limit(100)->pluck('id')->each(fn (string $id) => DispatchNotificationBroadcast::dispatch($id));
})->name('dispatch-notification-broadcasts')->everyMinute()->onOneServer()->withoutOverlapping(5);

Schedule::call(function (): void {
    DB::table('premium_entitlements')->where('state', 'active')->whereNotNull('ends_at')->where('ends_at', '<=', now())->update(['state' => 'expired', 'updated_at' => now()]);
    DB::table('premium_subscriptions')->where('state', 'active')->where('current_period_end', '<=', now())->update(['state' => 'expired', 'updated_at' => now()]);
    DB::table('premium_checkouts')->where('state', 'pending')->where('expires_at', '<=', now())->update(['state' => 'expired', 'updated_at' => now()]);
})->name('expire-premium-state')->hourly()->onOneServer()->withoutOverlapping(10);

Schedule::call(function (): void {
    DB::table('admin_exports')->where('expires_at', '<=', now())->where('state', '!=', 'expired')->orderBy('id')->chunkById(100, function ($exports): void {
        foreach ($exports as $export) {
            Storage::disk($export->disk)->delete('admin-exports/'.$export->id.'.csv');
            DB::table('admin_exports')->where('id', $export->id)->update(['state' => 'expired', 'path' => null, 'updated_at' => now()]);
        }
    });
    DB::table('data_export_requests')->where('expires_at', '<=', now())->where('state', 'completed')->orderBy('id')->chunkById(100, function ($exports): void {
        foreach ($exports as $export) {
            if ($export->path) {
                Storage::disk($export->disk)->delete($export->path);
            }
            DB::table('data_export_requests')->where('id', $export->id)->update(['state' => 'expired', 'path' => null, 'updated_at' => now()]);
        }
    });
    DB::table('feed_sync_runs')->where('finished_at', '<=', now()->subDays(config('catalog.feed_sync_run_retention_days')))->delete();
})->name('expire-admin-exports')->hourly()->onOneServer()->withoutOverlapping(10);
