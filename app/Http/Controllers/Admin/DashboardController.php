<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\ConfigurationVersion;
use App\Models\Device;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $filters = $request->validate(['date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from']]);
        $end = CarbonImmutable::parse($filters['date_to'] ?? now()->toDateString())->endOfDay();
        $start = CarbonImmutable::parse($filters['date_from'] ?? $end->subDays(13)->toDateString())->startOfDay();
        abort_if($start->diffInDays($end) > 93, 422, 'Choose a date range of at most 93 days.');
        $metrics = Cache::remember('admin:command-center:v1', 15, fn (): array => [
            'audience' => ['total_users' => User::count(), 'new_users_24h' => User::where('created_at', '>=', now()->subDay())->count(), 'dau' => DB::table('playback_progress')->where('updated_at', '>=', now()->subDay())->distinct()->count('user_id'), 'wau' => DB::table('playback_progress')->where('updated_at', '>=', now()->subDays(7))->distinct()->count('user_id')],
            'listening' => ['hours_recorded' => round(((int) DB::table('playback_progress')->sum('position_seconds')) / 3600, 1), 'completed_episodes' => DB::table('playback_progress')->where('completed', true)->count(), 'active_devices' => Device::whereNull('revoked_at')->count()],
            'catalog' => ['shows' => DB::table('shows')->count(), 'episodes' => DB::table('episodes')->count(), 'unhealthy_feeds' => DB::table('show_feed_states')->where('consecutive_failures', '>', 0)->count()],
            'creators' => ['active_creators' => DB::table('creator_profiles')->where('status', 'active')->count(), 'open_claims' => DB::table('show_claims')->whereIn('state', ['pending', 'manual_review'])->count(), 'claim_disputes' => DB::table('claim_disputes')->where('state', 'open')->count()],
            'moderation' => ['open_reports' => DB::table('content_reports')->where('state', 'open')->count(), 'processing_reels' => DB::table('reels')->where('state', 'processing')->count(), 'live_now' => DB::table('live_sessions')->where('state', 'live')->count()],
            'money' => ['earn_liability' => (int) DB::table('financial_accounts')->where('type', 'earn_wallet')->sum('balance'), 'withdrawals_queued' => DB::table('withdrawals')->where('state', 'queued')->count(), 'receipt_exceptions' => DB::table('iap_receipts')->whereNotIn('state', ['verified', 'credited'])->count(), 'reconciliation_drift' => DB::table('reconciliation_items')->where('state', 'open')->count()],
            'operations' => ['queued_jobs' => DB::table('jobs')->count(), 'failed_jobs' => DB::table('failed_jobs')->count(), 'pending_uploads' => DB::table('media_uploads')->whereIn('state', ['pending', 'uploaded', 'processing'])->count(), 'active_admins' => Admin::where('status', 'active')->count()],
        ]);

        $permissions = \App\Support\AdminAccess::permissions($request->user('admin'));
        $charts = [];
        $definitions = [
            ['New accounts', 'users', null, 'users.view', '/admin/users?view=directory'],
            ['Earn issued (coins)', 'earn_awards', 'coins', 'finance.view', '/admin/finance-records?view=earn'],
            ['Withdrawal requests (coins)', 'withdrawals', 'coins', 'finance.view', '/admin/finance-records?view=withdrawals'],
            ['Creator revenue (PCN)', 'creator_revenue_events', 'net_amount', 'finance.view', '/admin/finance-records?view=revenue&unit=PCN'],
            ['Creator payout requests (PCN)', 'creator_payouts', 'amount', 'finance.view', '/admin/finance-records?view=payouts&unit=PCN'],
            ['Reel uploads', 'reels', null, 'moderation.act', '/admin/reels-live?view=reels'],
            ['Support tickets', 'support_tickets', null, 'users.view', '/admin/support?view=inbox'],
            ['Gifts sent', 'gifts', null, 'finance.view', '/admin/finance-records?view=gifts'],
            ['Premium subscriptions started', 'premium_subscriptions', null, 'finance.view', '/admin/finance-records?view=premium'],
            ['Notification deliveries created', 'notification_deliveries', null, 'broadcast.send', '/admin/communications?view=delivery-report'],
            ['Referrals started', 'referrals', null, 'settings.write', '/admin/growth?view=directory'],
            ['Moderation reports filed', 'content_reports', null, 'moderation.act', '/admin/community?view=reports'],
            ['AI requests', 'ai_usage', null, 'ai.manage', '/admin/ai?view=overview'],
        ];
        foreach ($definitions as [$label, $table, $column, $permission, $href]) {
            if (! $permissions->contains($permission)) {
                continue;
            }
            $days = (int) $start->diffInDays($end->startOfDay()) + 1;
            $priorStart = $start->subDays($days);
            $query = DB::table($table)->whereBetween('created_at', [$priorStart, $end]);
            if (in_array($table, ['creator_revenue_events', 'creator_payouts'], true)) {
                $query->where('unit', 'PCN');
            }
            $aggregate = $column ? 'SUM('.$column.')' : 'COUNT(*)';
            $values = $query->selectRaw('DATE(created_at) as day, '.$aggregate.' as value')->groupByRaw('DATE(created_at)')->pluck('value', 'day');
            $points = [];
            for ($index = 0; $index < $days; $index++) {
                $date = $start->addDays($index)->toDateString();
                $prior = $priorStart->addDays($index)->toDateString();
                $points[] = ['date' => $date, 'value' => (int) ($values[$date] ?? 0), 'previous_date' => $prior, 'previous' => (int) ($values[$prior] ?? 0)];
            }
            $charts[] = ['label' => $label, 'points' => $points, 'href' => $href.'&date_from='.$start->toDateString().'&date_to='.$end->toDateString()];
        }
        $incidents = array_values(array_filter($this->incidents($metrics), fn (array $incident): bool => $permissions->contains($incident['permission'])));
        $groupPermissions = ['audience' => 'users.view', 'listening' => 'users.view', 'catalog' => 'catalog.write', 'creators' => 'claims.decide', 'moderation' => 'moderation.act', 'money' => 'finance.view', 'operations' => 'audit.view'];
        $visible = array_filter($metrics, fn (string $key): bool => $permissions->contains($groupPermissions[$key]), ARRAY_FILTER_USE_KEY);

        return Inertia::render('Admin/Dashboard', ['metricGroups' => $visible, 'incidents' => $permissions->contains('audit.view') ? $incidents : [], 'recentAudit' => $permissions->contains('audit.view') ? DB::table('audit_logs')->select('id', 'action', 'reason', 'created_at')->latest('created_at')->limit(8)->get() : [], 'charts' => $charts, 'filters' => ['date_from' => $start->toDateString(), 'date_to' => $end->toDateString()], 'configVersion' => ConfigurationVersion::max('version'), 'freshAt' => now()->toIso8601String(), 'environment' => app()->environment()]);
    }

    private function incidents(array $metrics): array
    {
        return collect([['permission' => 'audit.view', 'href' => '/admin/audit?view=failed-jobs', 'severity' => 'critical', 'label' => 'Failed queue jobs', 'count' => $metrics['operations']['failed_jobs']], ['permission' => 'catalog.write', 'href' => '/admin/catalog?feed_state=failed', 'severity' => 'warning', 'label' => 'Unhealthy feeds', 'count' => $metrics['catalog']['unhealthy_feeds']], ['permission' => 'finance.view', 'href' => '/admin/finance-records?view=reconciliation&state=open', 'severity' => 'warning', 'label' => 'Open reconciliation drift', 'count' => $metrics['money']['reconciliation_drift']], ['permission' => 'moderation.act', 'href' => '/admin/community?view=reports&state=open', 'severity' => 'info', 'label' => 'Open moderation reports', 'count' => $metrics['moderation']['open_reports']]])->filter(fn (array $incident): bool => $incident['count'] > 0)->values()->all();
    }
}
