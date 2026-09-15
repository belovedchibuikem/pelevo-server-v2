<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class ModuleWorkspaceController extends Controller
{
    /** @var array<string, array{title:string,description:string,views:array<string, array{label:string,table:string}>}> */
    private const MODULES = [
        'finance-records' => ['title' => 'Finance records', 'description' => 'Filtered financial evidence in recorded coin and currency units.', 'views' => [
            'accounts' => ['label' => 'Accounts & wallets', 'table' => 'financial_accounts'],
            'gifts' => ['label' => 'Gifts', 'table' => 'gifts'],
            'premium' => ['label' => 'Premium subscriptions', 'table' => 'premium_subscriptions'],
            'ledger' => ['label' => 'Ledger transactions', 'table' => 'ledger_transactions'],
            'earn' => ['label' => 'Earn awards', 'table' => 'earn_awards'],
            'withdrawals' => ['label' => 'Withdrawals', 'table' => 'withdrawals'],
            'revenue' => ['label' => 'Creator revenue', 'table' => 'creator_revenue_events'],
            'payouts' => ['label' => 'Creator payouts', 'table' => 'creator_payouts'],
            'receipts' => ['label' => 'IAP receipts', 'table' => 'iap_receipts'],
            'reconciliation' => ['label' => 'Reconciliation', 'table' => 'reconciliation_items'],
        ]],
        'users' => ['title' => 'Users', 'description' => 'Identity, access, activity, money surfaces, safety and privacy operations.', 'views' => [
            'directory' => ['label' => 'User directory', 'table' => 'users'], 'user-360' => ['label' => 'User 360', 'table' => 'users'], 'identity' => ['label' => 'Profile & identity', 'table' => 'user_profiles'], 'devices' => ['label' => 'Devices & sessions', 'table' => 'devices'], 'library' => ['label' => 'Following & library', 'table' => 'follows'], 'listening' => ['label' => 'Playback & listening', 'table' => 'listening_daily_stats'], 'gift-wallet' => ['label' => 'Gift wallet', 'table' => 'financial_accounts'], 'earn-wallet' => ['label' => 'Earn wallet', 'table' => 'earn_awards'], 'creator-balance' => ['label' => 'Creator balance', 'table' => 'creator_revenue_events'], 'premium' => ['label' => 'Premium & invoices', 'table' => 'premium_entitlements'], 'referrals' => ['label' => 'Referral history', 'table' => 'referrals'], 'sanctions' => ['label' => 'Reports & sanctions', 'table' => 'user_sanctions'], 'support' => ['label' => 'Support history', 'table' => 'support_tickets'], 'privacy' => ['label' => 'Privacy requests', 'table' => 'data_export_requests'], 'access' => ['label' => 'Disable, restore & logout', 'table' => 'users'],
        ]],
        'reels-live' => ['title' => 'Reels & Live', 'description' => 'Media processing, review, appeals, episode links and real-time live safety.', 'views' => [
            'reels' => ['label' => 'Reel directory', 'table' => 'reels'], 'processing' => ['label' => 'Processing queue', 'table' => 'reel_processing_events'], 'moderation' => ['label' => 'Moderation queue', 'table' => 'moderation_actions'], 'review' => ['label' => 'Reel review', 'table' => 'reels'], 'reports' => ['label' => 'Reported reels', 'table' => 'content_reports'], 'appeals' => ['label' => 'Appeals', 'table' => 'appeals'], 'broken-links' => ['label' => 'Broken episode links', 'table' => 'reel_episode_links'], 'live' => ['label' => 'Live monitor', 'table' => 'live_sessions'], 'live-detail' => ['label' => 'Live session detail', 'table' => 'live_health_snapshots'], 'takedowns' => ['label' => 'Live takedown history', 'table' => 'live_takedowns'],
        ]],
        'community' => ['title' => 'Community', 'description' => 'Comment context, reports, sanctions, appeals and moderation service levels.', 'views' => [
            'comments' => ['label' => 'Comments directory', 'table' => 'comments'], 'threads' => ['label' => 'Thread context', 'table' => 'comments'], 'reports' => ['label' => 'Content reports', 'table' => 'content_reports'], 'report-detail' => ['label' => 'Report detail', 'table' => 'content_reports'], 'queue' => ['label' => 'Moderation queue', 'table' => 'moderation_actions'], 'sanctions' => ['label' => 'User sanctions', 'table' => 'user_sanctions'], 'appeals' => ['label' => 'Appeals', 'table' => 'appeals'], 'sla' => ['label' => 'Moderation SLA', 'table' => 'moderation_actions'],
        ]],
        'communications' => ['title' => 'Communications', 'description' => 'Audience-safe notification composition, delivery health and provider operations.', 'views' => [
            'overview' => ['label' => 'Overview', 'table' => 'notifications'], 'composer' => ['label' => 'Notification composer', 'table' => 'notification_broadcasts'], 'audiences' => ['label' => 'Audience builder', 'table' => 'notification_preferences'], 'templates' => ['label' => 'Templates', 'table' => 'notification_templates'], 'scheduled' => ['label' => 'Scheduled broadcasts', 'table' => 'notification_broadcasts'], 'delivery-queue' => ['label' => 'Delivery queue', 'table' => 'notification_deliveries'], 'delivery-report' => ['label' => 'Delivery reports', 'table' => 'notification_deliveries'], 'failed' => ['label' => 'Failed delivery', 'table' => 'notification_deliveries'], 'digest-policy' => ['label' => 'Digest & frequency', 'table' => 'notification_preferences'], 'lock-screen' => ['label' => 'Lock-screen rules', 'table' => 'notification_lock_rules'], 'providers' => ['label' => 'Provider health', 'table' => 'provider_webhook_events'],
        ]],
        'growth' => ['title' => 'Referrals & Growth', 'description' => 'Versioned programs, qualification funnels, rewards, caps and fraud clusters.', 'views' => [
            'overview' => ['label' => 'Overview & funnel', 'table' => 'referrals'], 'programs' => ['label' => 'Program versions', 'table' => 'referral_programs'], 'directory' => ['label' => 'Referral directory', 'table' => 'referrals'], 'qualification' => ['label' => 'Qualification events', 'table' => 'referral_qualification_events'], 'fraud' => ['label' => 'Suspicious clusters', 'table' => 'referral_fraud_clusters'], 'caps' => ['label' => 'Caps & configuration', 'table' => 'configuration_versions'],
        ]],
        'cms' => ['title' => 'CMS & Discovery', 'description' => 'Versioned content, help, merchandising, editorial rails and share-card templates.', 'views' => [
            'pages' => ['label' => 'Pages & versions', 'table' => 'cms_pages'], 'help' => ['label' => 'Help centre', 'table' => 'cms_pages'], 'guidelines' => ['label' => 'Guidelines', 'table' => 'cms_pages'], 'home-rails' => ['label' => 'Home-rail builder', 'table' => 'editorial_overrides'], 'playlists' => ['label' => 'Editorial playlists', 'table' => 'editorial_playlists'], 'share-cards' => ['label' => 'Share-card templates', 'table' => 'share_card_templates'], 'announcements' => ['label' => 'Feature announcements', 'table' => 'cms_pages'],
        ]],
        'ai' => ['title' => 'AI Desk', 'description' => 'Usage, cost, safety, controlled prompt rollout and provider safeguards.', 'views' => [
            'overview' => ['label' => 'Usage & cost', 'table' => 'ai_usage'], 'jobs' => ['label' => 'Job directory', 'table' => 'ai_jobs'], 'prompts' => ['label' => 'Prompt versions', 'table' => 'prompt_versions'], 'providers' => ['label' => 'Provider & model config', 'table' => 'configuration_versions'], 'quotas' => ['label' => 'User & tier quotas', 'table' => 'configuration_versions'], 'safety' => ['label' => 'Failure & safety review', 'table' => 'ai_jobs'], 'kill-switch' => ['label' => 'Kill switch', 'table' => 'configuration_versions'],
        ]],
        'support' => ['title' => 'Support', 'description' => 'Website contact, in-app tickets, assignment, SLA and structured feedback.', 'views' => [
            'contact' => ['label' => 'Website contact', 'table' => 'contact_inquiries'], 'inbox' => ['label' => 'In-app tickets', 'table' => 'support_tickets'], 'tickets' => ['label' => 'Ticket detail', 'table' => 'support_tickets'], 'sla' => ['label' => 'Assignment & SLA', 'table' => 'support_tickets'], 'feedback' => ['label' => 'App feedback', 'table' => 'feedback'], 'responses' => ['label' => 'Responses & categories', 'table' => 'cms_pages'],
        ]],
        'analytics' => ['title' => 'Analytics', 'description' => 'Exact operational evidence for audience, content, revenue and scheduled exports.', 'views' => [
            'audience' => ['label' => 'Audience retention', 'table' => 'listening_daily_stats'], 'listening' => ['label' => 'Listening & completion', 'table' => 'listening_daily_stats'], 'search' => ['label' => 'Search success', 'table' => 'search_history'], 'recommendations' => ['label' => 'Recommendation quality', 'table' => 'recommendation_snapshots'], 'library' => ['label' => 'Library conversion', 'table' => 'follows'], 'reels' => ['label' => 'Reels funnel', 'table' => 'reel_engagements'], 'creators' => ['label' => 'Creator funnel', 'table' => 'creator_profiles'], 'earn' => ['label' => 'Earn & fraud', 'table' => 'earn_sessions'], 'gifts' => ['label' => 'Gifts & revenue', 'table' => 'gifts'], 'premium' => ['label' => 'Premium MRR & churn', 'table' => 'premium_subscriptions'], 'regional' => ['label' => 'Regional comparisons', 'table' => 'listening_daily_stats'], 'exports' => ['label' => 'Scheduled exports', 'table' => 'scheduled_exports'],
        ]],
        'settings' => ['title' => 'Settings', 'description' => 'Versioned product policy, providers, localization, access control and system evidence.', 'views' => [
            'flags' => ['label' => 'Feature flags', 'table' => 'configuration_versions'], 'limits' => ['label' => 'Product limits', 'table' => 'configuration_versions'], 'payments' => ['label' => 'Payment providers', 'table' => 'provider_webhook_events'], 'podcasts' => ['label' => 'Podcast & RSS', 'table' => 'feed_sync_runs'], 'media' => ['label' => 'Media & storage', 'table' => 'media_uploads'], 'localization' => ['label' => 'Languages & countries', 'table' => 'categories'], 'deep-links' => ['label' => 'Deep links & notifications', 'table' => 'notifications'], 'roles' => ['label' => 'Roles & permissions', 'table' => 'roles'], 'administrators' => ['label' => 'Administrators', 'table' => 'admins'], 'security' => ['label' => 'Security & sessions', 'table' => 'admin_sessions'], 'audit' => ['label' => 'Audit explorer', 'table' => 'audit_logs'], 'approvals' => ['label' => 'Maker-checker approvals', 'table' => 'admin_approvals'], 'privacy' => ['label' => 'Retention & privacy', 'table' => 'account_deletion_requests'], 'system' => ['label' => 'System information', 'table' => 'configuration_versions'],
        ]],
        'audit' => ['title' => 'Audit & Security', 'description' => 'Immutable administrator, approval, webhook and security event evidence.', 'views' => [
            'queue' => ['label' => 'Queued jobs', 'table' => 'jobs'], 'failed-jobs' => ['label' => 'Failed jobs', 'table' => 'failed_jobs'],
            'audit-log' => ['label' => 'Audit log', 'table' => 'audit_logs'], 'admin-logins' => ['label' => 'Admin sessions', 'table' => 'admin_sessions'], 'approvals' => ['label' => 'Approvals', 'table' => 'admin_approvals'], 'webhooks' => ['label' => 'Webhook events', 'table' => 'provider_webhook_events'], 'privacy' => ['label' => 'Privacy operations', 'table' => 'account_deletion_requests'],
        ]],
    ];

    private const SAFE_COLUMNS = ['id', 'reference', 'title', 'name', 'slug', 'handle', 'subject', 'category', 'type', 'action', 'reason', 'priority', 'state', 'status', 'provider', 'store', 'currency', 'coins', 'unit', 'amount', 'net_amount', 'gross_amount', 'fee_amount', 'amount_minor', 'risk_score', 'version', 'last_seen_at', 'started_at', 'completed_at', 'published_at', 'scheduled_at', 'effective_at', 'created_at', 'updated_at'];

    /** Extra operator columns that are only safe on a specific table. */
    private const TABLE_COLUMNS = [
        'contact_inquiries' => ['email', 'audience', 'message'],
    ];

    public function __invoke(Request $request, string $module): Response
    {
        abort_unless(isset(self::MODULES[$module]), 404);
        $definition = self::MODULES[$module];
        $viewKeys = array_keys($definition['views']);
        $validated = $request->validate([
            'view' => ['nullable', Rule::in($viewKeys)],
            'q' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:40'],
            'unit' => ['nullable', 'string', 'size:3', 'alpha:ascii'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'sort' => ['nullable', Rule::in(self::SAFE_COLUMNS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', Rule::in([25, 50, 100])],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);
        $activeView = $validated['view'] ?? match ($module) {
            'audit' => 'audit-log',
            'finance-records' => 'ledger',
            'support' => 'contact',
            default => $viewKeys[0],
        };
        $table = $definition['views'][$activeView]['table'];
        $counts = Cache::remember("admin:module-counts:{$module}", 30, function () use ($definition): array {
            return collect($definition['views'])->pluck('table')->unique()->mapWithKeys(fn (string $source): array => [$source => Schema::hasTable($source) ? DB::table($source)->count() : null])->all();
        });

        return Inertia::render('Admin/ModuleWorkspace', [
            'module' => $module,
            'title' => $definition['title'],
            'description' => $definition['description'],
            'views' => collect($definition['views'])->map(fn (array $item, string $key): array => ['key' => $key, 'label' => $item['label'], 'count' => $counts[$item['table']]])->values(),
            'activeView' => $activeView,
            'records' => $this->records($table, $validated),
            'filters' => [
                'unit' => $validated['unit'] ?? '',
                'q' => $validated['q'] ?? '',
                'state' => $validated['state'] ?? '',
                'date_from' => $validated['date_from'] ?? '',
                'date_to' => $validated['date_to'] ?? '',
                'sort' => $validated['sort'] ?? '',
                'direction' => $validated['direction'] ?? 'desc',
                'per_page' => (int) ($validated['per_page'] ?? 25),
            ],
            'freshAt' => now()->toIso8601String(),
        ]);
    }

    public function exportQuery(string $module, array $filters): Builder
    {
        $definition = self::MODULES[$module] ?? null;
        abort_unless($definition, 404);
        $view = $filters['view'] ?? array_key_first($definition['views']);
        $table = $definition['views'][$view]['table'] ?? null;
        abort_unless($table && Schema::hasTable($table), 422);
        $available = Schema::getColumnListing($table);
        $columns = $this->safeColumns($table, $available);
        abort_if($columns === [], 422);
        $query = DB::table($table)->select($columns);
        $this->applyFilters($query, $available, $filters, $table);

        $defaultOrder = in_array('updated_at', $available, true) ? 'updated_at' : (in_array('created_at', $available, true) ? 'created_at' : $columns[0]);

        return $query->orderBy(in_array($filters['sort'] ?? '', $columns, true) ? $filters['sort'] : $defaultOrder, ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc');
    }

    /** @param array<string, mixed> $filters */
    private function records(string $table, array $filters): array
    {
        if (! Schema::hasTable($table)) {
            return ['data' => [], 'columns' => [], 'current_page' => 1, 'last_page' => 1, 'total' => 0, 'degraded' => true];
        }
        $available = Schema::getColumnListing($table);
        $columns = $this->safeColumns($table, $available);
        if ($columns === []) {
            return ['data' => [], 'columns' => [], 'current_page' => 1, 'last_page' => 1, 'total' => DB::table($table)->count(), 'degraded' => true];
        }
        $query = DB::table($table)->select($columns);
        $this->applyFilters($query, $available, $filters, $table);
        $defaultOrder = in_array('updated_at', $available, true) ? 'updated_at' : (in_array('created_at', $available, true) ? 'created_at' : $columns[0]);
        $order = in_array($filters['sort'] ?? '', $columns, true) ? $filters['sort'] : $defaultOrder;
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $page = $query->orderBy($order, $direction)->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();

        return array_merge($page->toArray(), ['columns' => $columns, 'degraded' => false]);
    }

    /** @param string[] $available */
    private function safeColumns(string $table, array $available): array
    {
        $allowed = [...self::SAFE_COLUMNS, ...(self::TABLE_COLUMNS[$table] ?? [])];

        return array_values(array_intersect($allowed, $available));
    }

    /** @param string[] $available @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $available, array $filters, string $table = ''): void
    {
        $searchKeys = ['id', 'reference', 'title', 'name', 'slug', 'handle', 'subject'];
        if ($table === 'contact_inquiries') {
            $searchKeys = [...$searchKeys, 'email', 'audience'];
        }
        $searchable = array_values(array_intersect($searchKeys, $available));
        if (($filters['q'] ?? '') !== '' && $searchable !== []) {
            $query->where(function (Builder $nested) use ($filters, $searchable): void {
                foreach ($searchable as $index => $column) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $nested->{$method}($column, 'like', '%'.$filters['q'].'%');
                }
            });
        }
        if (($filters['unit'] ?? '') !== '') {
            abort_unless(in_array('unit', $available, true), 422, 'This source does not have a monetary unit.');
            $query->where('unit', strtoupper($filters['unit']));
        }
        $stateColumn = in_array('state', $available, true) ? 'state' : (in_array('status', $available, true) ? 'status' : null);
        if (($filters['state'] ?? '') !== '' && $stateColumn) {
            $query->where($stateColumn, $filters['state']);
        }
        $dateColumn = collect(['created_at', 'updated_at', 'published_at', 'started_at'])->first(fn (string $column): bool => in_array($column, $available, true));
        if ($dateColumn && ($filters['date_from'] ?? '') !== '') {
            $query->whereDate($dateColumn, '>=', $filters['date_from']);
        }
        if ($dateColumn && ($filters['date_to'] ?? '') !== '') {
            $query->whereDate($dateColumn, '<=', $filters['date_to']);
        }
    }
}
