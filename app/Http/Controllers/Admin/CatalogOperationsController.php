<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Catalog\InvalidateDiscoveryCache;
use App\Http\Controllers\Controller;
use App\Jobs\HydrateRssFeed;
use App\Support\ApiResponse;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class CatalogOperationsController extends Controller
{
    public function page(Request $request): Response
    {
        return Inertia::render('Admin/Catalog', $this->workspace($request));
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->workspace($request));
    }

    public function show(string $show): JsonResponse
    {
        $record = DB::table('shows')->where('id', $show)->first();
        abort_unless($record, 404);

        return ApiResponse::success(['show' => $record, 'feed' => DB::table('show_feed_states')->where('show_id', $show)->first(), 'episodes' => DB::table('episodes')->where('show_id', $show)->latest('published_at')->paginate(50), 'categories' => DB::table('categories')->join('category_show', 'categories.id', '=', 'category_show.category_id')->where('category_show.show_id', $show)->get(), 'audits' => DB::table('audit_logs')->where('subject_id', $show)->latest()->get()]);
    }

    public function refresh(string $show, Request $request): JsonResponse
    {
        abort_unless(DB::table('shows')->where('id', $show)->exists(), 404);
        HydrateRssFeed::dispatch($show);
        $audit = $this->audit($request, 'catalog.feed_refresh_requested', 'App\\Models\\Show', $show, 'Manual feed diagnostic refresh.', []);

        return ApiResponse::success(['queued' => true, 'audit_reference' => $audit], status: 202);
    }

    public function category(Request $request, InvalidateDiscoveryCache $cache): JsonResponse
    {
        $data = $request->validate(['id' => ['nullable', 'integer', 'exists:categories,id'], 'name' => ['required', 'string', 'max:100'], 'slug' => ['required', 'alpha_dash', 'max:100'], 'position' => ['required', 'integer', 'min:0'], 'active' => ['required', 'boolean']]);
        $values = ['name' => $data['name'], 'slug' => Str::lower($data['slug']), 'position' => $data['position'], 'active' => $data['active'], 'updated_at' => now()];
        if (isset($data['id'])) {
            DB::table('categories')->where('id', $data['id'])->update($values);
        } else {
            $data['id'] = DB::table('categories')->insertGetId([...$values, 'created_at' => now()]);
        }
        $cache->public();
        $audit = $this->audit($request, 'catalog.category_saved', 'App\\Models\\Category', (string) $data['id'], 'Catalog taxonomy update.', $values);

        return ApiResponse::success(['category' => DB::table('categories')->find($data['id']), 'audit_reference' => $audit]);
    }

    public function playlist(Request $request, InvalidateDiscoveryCache $cache): JsonResponse
    {
        $data = $request->validate(['id' => ['nullable', 'string', 'exists:editorial_playlists,id'], 'title' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:1000'], 'published' => ['required', 'boolean'], 'position' => ['required', 'integer', 'min:0'], 'episode_ids' => ['array', 'max:100'], 'episode_ids.*' => ['distinct', 'exists:episodes,id']]);
        $id = $data['id'] ?? (string) Str::ulid();
        DB::transaction(function () use ($data, $id): void {
            DB::table('editorial_playlists')->updateOrInsert(['id' => $id], ['title' => $data['title'], 'description' => $data['description'] ?? null, 'published' => $data['published'], 'position' => $data['position'], 'created_at' => now(), 'updated_at' => now()]);
            DB::table('editorial_playlist_items')->where('editorial_playlist_id', $id)->delete();
            foreach ($data['episode_ids'] ?? [] as $position => $episodeId) {
                DB::table('editorial_playlist_items')->insert(['id' => (string) Str::ulid(), 'editorial_playlist_id' => $id, 'episode_id' => $episodeId, 'position' => $position, 'created_at' => now(), 'updated_at' => now()]);
            }
        }, 3);
        $cache->public();
        $audit = $this->audit($request, 'catalog.editorial_playlist_saved', 'App\\Models\\EditorialPlaylist', $id, 'Editorial programming update.', ['published' => $data['published']]);

        return ApiResponse::success(['playlist' => DB::table('editorial_playlists')->find($id), 'audit_reference' => $audit]);
    }

    public function synonym(Request $request): JsonResponse
    {
        $data = $request->validate(['term' => ['required', 'string', 'max:100'], 'synonym' => ['required', 'string', 'max:100'], 'active' => ['required', 'boolean']]);
        DB::table('search_synonyms')->updateOrInsert(['term' => Str::lower($data['term']), 'synonym' => Str::lower($data['synonym'])], ['active' => $data['active'], 'created_at' => now(), 'updated_at' => now()]);
        $id = (string) DB::table('search_synonyms')->where('term', Str::lower($data['term']))->where('synonym', Str::lower($data['synonym']))->value('id');
        $audit = $this->audit($request, 'catalog.search_synonym_saved', 'App\\Models\\SearchSynonym', $id, 'Search relevance configuration.', $data);

        return ApiResponse::success(['synonym' => DB::table('search_synonyms')->find($id), 'audit_reference' => $audit]);
    }

    public function homeModule(Request $request, string $module): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'subtitle' => ['nullable', 'string', 'max:250'],
            'position' => ['required', 'integer', 'min:0', 'max:1000'],
            'active' => ['required', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);
        abort_unless(DB::table('home_modules')->where('id', $module)->exists(), 404);
        DB::transaction(function () use ($module, $data): void {
            DB::table('home_modules')->where('id', $module)->update([...$data, 'updated_at' => now()]);
            DB::table('home_feed_snapshots')->delete();
        }, 3);
        $audit = $this->audit($request, 'catalog.home_module_saved', 'App\\Models\\HomeModule', $module, 'Home merchandising update.', $data);

        return ApiResponse::success(['module' => DB::table('home_modules')->find($module), 'audit_reference' => $audit]);
    }

    private function workspace(Request $request): array
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:active,inactive,blocked'], 'feed_state' => ['nullable', 'string', 'max:40'], 'sort' => ['nullable', 'in:title,created_at,updated_at'], 'direction' => ['nullable', 'in:asc,desc'], 'per_page' => ['nullable', 'integer', 'min:10', 'max:100']]);
        $query = DB::table('shows')->leftJoin('show_feed_states', 'shows.id', '=', 'show_feed_states.show_id')->select('shows.*', 'show_feed_states.state as feed_state', 'show_feed_states.last_success_at', 'show_feed_states.last_failure_at', 'show_feed_states.consecutive_failures', 'show_feed_states.last_error');
        $query->when($filters['q'] ?? null, fn (Builder $q, string $term) => $q->where(fn (Builder $q) => $q->where('shows.title', 'like', "%{$term}%")->orWhere('shows.author', 'like', "%{$term}%")))->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('shows.status', $status))->when($filters['feed_state'] ?? null, fn (Builder $q, string $state) => $q->where('show_feed_states.state', $state));
        $sort = $filters['sort'] ?? 'updated_at';
        $direction = $filters['direction'] ?? 'desc';

        return ['shows' => $query->orderBy("shows.{$sort}", $direction)->orderBy('shows.id')->paginate($filters['per_page'] ?? 25)->withQueryString(), 'feedHealth' => DB::table('show_feed_states')->select('state', DB::raw('COUNT(*) as total'))->groupBy('state')->get(), 'categories' => DB::table('categories')->orderBy('position')->get(), 'playlists' => DB::table('editorial_playlists')->orderBy('position')->get(), 'homeModules' => DB::table('home_modules')->orderBy('position')->orderBy('id')->get(), 'searchSynonyms' => DB::table('search_synonyms')->orderBy('term')->get(), 'zeroResults' => DB::table('search_history')->where('result_count', 0)->select('query', DB::raw('COUNT(*) as searches'))->groupBy('query')->orderByDesc('searches')->limit(25)->get(), 'filters' => $filters, 'freshAt' => now()->toIso8601String()];
    }

    private function audit(Request $request, string $action, string $type, string $id, string $reason, array $after): string
    {
        $audit = (string) Str::ulid();
        DB::table('audit_logs')->insert(['id' => $audit, 'admin_id' => auth('admin')->id(), 'action' => $action, 'subject_type' => $type, 'subject_id' => $id, 'reason' => $reason, 'after' => json_encode($after), 'request_id' => $request->attributes->get('request_id'), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);

        return $audit;
    }
}
