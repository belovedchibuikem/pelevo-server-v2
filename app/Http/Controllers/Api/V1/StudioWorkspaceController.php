<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CreatorProfile;
use App\Support\ApiResponse;
use App\Support\ReelPlayback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StudioWorkspaceController extends Controller
{
    public function shows(Request $request): JsonResponse
    {
        $items = DB::table('verified_show_claims')->join('show_claims', 'show_claims.id', '=', 'verified_show_claims.show_claim_id')->join('shows', 'shows.id', '=', 'verified_show_claims.show_id')->whereIn('show_claims.creator_profile_id', $this->creatorIds($request))->select('shows.id', 'shows.title', 'shows.author', 'shows.artwork_url', 'shows.updated_at', 'show_claims.creator_profile_id', 'show_claims.verified_at', DB::raw('(select count(*) from episodes where episodes.show_id = shows.id) as episodes_count'), DB::raw('(select count(*) from follows where follows.show_id = shows.id) as follower_count'))->orderBy('shows.title')->orderBy('shows.id')->cursorPaginate($this->limit($request));

        return $this->page($items);
    }

    public function episodes(Request $request): JsonResponse
    {
        $items = DB::table('episodes')->join('shows', 'shows.id', '=', 'episodes.show_id')->whereIn('episodes.show_id', $this->showIds($request))->select('episodes.id', 'episodes.show_id', 'episodes.title', 'episodes.description', 'episodes.duration_seconds', 'episodes.published_at', 'episodes.availability', 'shows.title as show_title', 'shows.artwork_url as artwork_url')->when($request->string('availability')->isNotEmpty(), fn ($q) => $q->where('episodes.availability', $request->string('availability')->toString()))->orderByDesc('episodes.published_at')->orderByDesc('episodes.id')->cursorPaginate($this->limit($request));

        return $this->page($items);
    }

    public function episode(string $episode, Request $request): JsonResponse
    {
        $row = $this->episodeRow($episode, $request);

        return $row ? ApiResponse::success($row) : ApiResponse::error('NOT_FOUND', 'Episode not found.', 404);
    }

    public function storeEpisode(Request $request): JsonResponse
    {
        $creator = CreatorProfile::where('user_id', $request->user()->id)->first();
        if (! $creator) {
            return ApiResponse::error('CLAIM_REQUIRED', 'A verified show claim is required.', 403);
        }
        $data = $request->validate(['show_id' => ['required', 'exists:shows,id'], 'title' => ['required', 'string', 'max:191'], 'description' => ['nullable', 'string', 'max:2000']]);
        $owned = DB::table('verified_show_claims')->join('show_claims', 'show_claims.id', '=', 'verified_show_claims.show_claim_id')->where('show_claims.creator_profile_id', $creator->id)->where('verified_show_claims.show_id', $data['show_id'])->exists();
        if (! $owned) {
            return ApiResponse::error('UNPROCESSABLE', 'Show is not in your claimed studio.', 422);
        }
        $id = (string) Str::ulid();
        DB::table('episodes')->insert(['id' => $id, 'show_id' => $data['show_id'], 'guid' => 'pelevo-studio-'.$id, 'title' => $data['title'], 'description' => $data['description'] ?? null, 'audio_url' => null, 'availability' => 'draft', 'published_at' => null, 'created_at' => now(), 'updated_at' => now()]);
        $row = $this->episodeRow($id, $request);

        return $row ? ApiResponse::success($row, status: 201) : ApiResponse::error('NOT_FOUND', 'Episode not found.', 404);
    }

    public function uploadEpisodeAudio(string $episode, Request $request): JsonResponse
    {
        $row = $this->episodeRow($episode, $request);
        if (! $row) {
            return ApiResponse::error('NOT_FOUND', 'Episode not found.', 404);
        }
        if (! in_array($row->availability, ['draft', 'scheduled'], true)) {
            return ApiResponse::error('CONFLICT', 'Published episodes cannot replace audio here.', 409);
        }
        $data = $request->validate([
            'audio' => ['required', 'file', 'max:512000', 'mimetypes:audio/mpeg,audio/mp3,audio/mp4,audio/x-m4a,audio/m4a,audio/aac,audio/wav,audio/x-wav,audio/wave'],
        ]);
        $stored = $data['audio']->store('episode-audio/'.$request->user()->id.'/'.$episode, 'public');
        $url = url(\Illuminate\Support\Facades\Storage::disk('public')->url($stored));
        DB::table('episodes')->where('id', $episode)->update([
            'audio_url' => $url,
            'updated_at' => now(),
        ]);
        $fresh = $this->episodeRow($episode, $request);

        return $fresh ? ApiResponse::success($fresh) : ApiResponse::error('NOT_FOUND', 'Episode not found.', 404);
    }

    public function publishEpisode(string $episode, Request $request): JsonResponse
    {
        $row = $this->episodeRow($episode, $request);
        if (! $row) {
            return ApiResponse::error('NOT_FOUND', 'Episode not found.', 404);
        }
        if (empty($row->audio_url)) {
            return ApiResponse::error('UNPROCESSABLE', 'Episode audio is required to publish.', 422);
        }
        $data = $request->validate([
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ]);
        if (! empty($data['scheduled_at'])) {
            DB::table('episodes')->where('id', $episode)->update([
                'availability' => 'scheduled',
                'published_at' => $data['scheduled_at'],
                'updated_at' => now(),
            ]);
        } else {
            DB::table('episodes')->where('id', $episode)->update([
                'availability' => 'available',
                'published_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $fresh = $this->episodeRow($episode, $request);

        return $fresh ? ApiResponse::success($fresh) : ApiResponse::error('NOT_FOUND', 'Episode not found.', 404);
    }

    public function reels(Request $request): JsonResponse
    {
        $items = DB::table('reels')->whereIn('reels.creator_profile_id', $this->creatorIds($request))->leftJoin('episodes', 'episodes.id', '=', 'reels.episode_id')->select($this->reelColumns())->when($request->string('state')->isNotEmpty(), fn ($q) => $q->where('reels.state', $request->string('state')->toString()))->when($request->boolean('linked'), fn ($q) => $q->where(fn ($inner) => $inner->whereNotNull('reels.episode_id')->orWhereExists(fn ($exists) => $exists->selectRaw('1')->from('reel_episode_links')->whereColumn('reel_episode_links.reel_id', 'reels.id'))))->orderByDesc('reels.updated_at')->orderByDesc('reels.id')->cursorPaginate($this->limit($request));

        return ApiResponse::success(
            collect($items->items())->map(fn (object $row): array => $this->presentReel($row, $request))->values()->all(),
            ['cursor' => $items->nextCursor()?->encode(), 'has_more' => $items->hasMorePages()],
        );
    }

    public function reel(string $reel, Request $request): JsonResponse
    {
        $row = DB::table('reels')->where('reels.id', $reel)->whereIn('reels.creator_profile_id', $this->creatorIds($request))->leftJoin('episodes', 'episodes.id', '=', 'reels.episode_id')->select($this->reelColumns())->first();

        return $row ? ApiResponse::success($this->presentReel($row, $request)) : ApiResponse::error('NOT_FOUND', 'Reel not found.', 404);
    }

    public function audience(Request $request): JsonResponse
    {
        $creators = $this->creatorIds($request);
        $shows = $this->showIds($request);

        return ApiResponse::success(['followers' => DB::table('creator_followers')->whereIn('creator_profile_id', $creators)->count(), 'show_followers' => DB::table('follows')->whereIn('show_id', $shows)->count(), 'listeners_30d' => DB::table('playback_progress')->join('episodes', 'episodes.id', '=', 'playback_progress.episode_id')->whereIn('episodes.show_id', $shows)->where('playback_progress.updated_at', '>=', now()->subDays(30))->distinct()->count('playback_progress.user_id')]);
    }

    public function monetization(Request $request): JsonResponse
    {
        $creators = $this->creatorIds($request);

        return ApiResponse::success(['gifts_received' => DB::table('gifts')->whereIn('creator_profile_id', $creators)->count(), 'payouts_count' => DB::table('creator_payouts')->whereIn('creator_profile_id', $creators)->count(), 'accounts' => DB::table('financial_accounts')->where('owner_type', 'App\\Models\\CreatorProfile')->whereIn('owner_id', $creators)->select('id', 'owner_id', 'type', 'unit', 'balance')->get(), 'tax_profiles' => DB::table('tax_profiles')->whereIn('creator_profile_id', $creators)->select('id', 'creator_profile_id', 'country_code', 'state')->get()]);
    }

    public function followers(Request $request): JsonResponse
    {
        $query = $request->string('q')->trim()->toString();
        $items = DB::table('creator_followers')->join('users', 'users.id', '=', 'creator_followers.user_id')->whereIn('creator_followers.creator_profile_id', $this->creatorIds($request))->select('users.id', 'users.name', 'users.handle', 'creator_followers.created_at')->when($query !== '', function ($q) use ($query) {
            $term = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $query);
            $q->where(fn ($inner) => $inner->where('users.name', 'like', "%{$term}%")->orWhere('users.handle', 'like', "%{$term}%"));
        })->orderByDesc('creator_followers.created_at')->orderByDesc('users.id')->cursorPaginate($this->limit($request));

        return ApiResponse::success(collect($items->items())->map(fn ($row): array => ['id' => $row->id, 'name' => $row->name, 'handle' => $row->handle, 'created_at' => $row->created_at])->values()->all(), ['cursor' => $items->nextCursor()?->encode(), 'has_more' => $items->hasMorePages()]);
    }

    public function supporters(Request $request): JsonResponse
    {
        $creators = $this->creatorIds($request);
        $items = DB::table('gifts')->join('users', 'users.id', '=', 'gifts.sender_id')->join('gift_types', 'gift_types.id', '=', 'gifts.gift_type_id')->whereIn('gifts.creator_profile_id', $creators)->select('users.id', 'users.name', 'users.handle', DB::raw('count(*) as gifts_count'), DB::raw('sum(gift_types.coins) as coins'))->groupBy('users.id', 'users.name', 'users.handle')->orderByDesc(DB::raw('sum(gift_types.coins)'))->orderBy('users.id')->cursorPaginate($this->limit($request));
        $totals = DB::table('gifts')->join('gift_types', 'gift_types.id', '=', 'gifts.gift_type_id')->whereIn('gifts.creator_profile_id', $creators)->selectRaw('count(distinct gifts.sender_id) as supporters_count, coalesce(sum(gift_types.coins), 0) as coins_received')->first();

        return ApiResponse::success(collect($items->items())->map(fn ($row): array => ['id' => $row->id, 'name' => $row->name, 'handle' => $row->handle, 'gifts_count' => (int) $row->gifts_count, 'coins' => (int) $row->coins])->values()->all(), ['cursor' => $items->nextCursor()?->encode(), 'has_more' => $items->hasMorePages(), 'supporters_count' => (int) ($totals->supporters_count ?? 0), 'coins_received' => (int) ($totals->coins_received ?? 0)]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $kind = $request->string('kind')->toString();
        if ($kind !== '' && ! in_array($kind, ['earnings', 'payouts', 'gifts', 'reels'], true)) {
            return ApiResponse::error('UNPROCESSABLE', 'Invalid transaction kind.', 422);
        }
        $accountIds = $this->accountIds($request);
        if ($accountIds->isEmpty()) {
            return ApiResponse::success([], ['cursor' => null, 'has_more' => false]);
        }
        $items = DB::table('ledger_transactions')->whereExists(fn ($q) => $q->selectRaw('1')->from('ledger_entries')->whereColumn('ledger_entries.ledger_transaction_id', 'ledger_transactions.id')->whereIn('financial_account_id', $accountIds))->when($kind === 'gifts', fn ($q) => $q->where('event_type', 'gift.sent'))->when($kind === 'payouts', fn ($q) => $q->where('event_type', 'like', 'creator_payout.%'))->when($kind === 'reels', fn ($q) => $q->where('event_type', 'reel.revenue'))->when($kind === 'earnings', fn ($q) => $q->whereIn('event_type', ['gift.sent', 'reel.revenue']))->select('id', 'reference', 'event_type', 'created_at')->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate($this->limit($request));

        return ApiResponse::success($this->presentTransactions(collect($items->items()), $accountIds), ['cursor' => $items->nextCursor()?->encode(), 'has_more' => $items->hasMorePages()]);
    }

    public function transaction(string $transaction, Request $request): JsonResponse
    {
        $accountIds = $this->accountIds($request);
        $row = DB::table('ledger_transactions')->where('id', $transaction)->whereExists(fn ($q) => $q->selectRaw('1')->from('ledger_entries')->whereColumn('ledger_entries.ledger_transaction_id', 'ledger_transactions.id')->whereIn('financial_account_id', $accountIds))->select('id', 'reference', 'event_type', 'created_at')->first();
        if (! $row) {
            return ApiResponse::error('NOT_FOUND', 'Transaction not found.', 404);
        }
        $presented = $this->presentTransactions(collect([$row]), $accountIds);

        return ApiResponse::success($presented[0] ?? null);
    }

    private function creatorIds(Request $request): Collection
    {
        $direct = DB::table('creator_profiles')->where('user_id', $request->user()->id)->pluck('id');
        $ids = DB::table('studios')->join('studio_members', 'studio_members.studio_id', '=', 'studios.id')->where('studio_members.user_id', $request->user()->id)->pluck('studios.creator_profile_id')->merge($direct)->unique()->values();
        abort_unless(DB::table('verified_show_claims')->join('show_claims', 'show_claims.id', '=', 'verified_show_claims.show_claim_id')->whereIn('show_claims.creator_profile_id', $ids)->exists(), 403, 'A verified show claim is required.');

        return $ids;
    }

    private function showIds(Request $request): Collection
    {
        return DB::table('verified_show_claims')->join('show_claims', 'show_claims.id', '=', 'verified_show_claims.show_claim_id')->whereIn('show_claims.creator_profile_id', $this->creatorIds($request))->pluck('verified_show_claims.show_id');
    }

    private function accountIds(Request $request): Collection
    {
        return DB::table('financial_accounts')->where('owner_type', 'App\\Models\\CreatorProfile')->whereIn('owner_id', $this->creatorIds($request))->pluck('id');
    }

    private function presentTransactions(Collection $rows, Collection $accountIds): array
    {
        if ($rows->isEmpty()) {
            return [];
        }
        $ids = $rows->pluck('id');
        $amounts = DB::table('ledger_entries')->whereIn('ledger_transaction_id', $ids)->whereIn('financial_account_id', $accountIds)->groupBy('ledger_transaction_id')->select('ledger_transaction_id', DB::raw('sum(amount) as amount'), DB::raw('min(unit) as unit'))->get()->keyBy('ledger_transaction_id');
        $gifts = DB::table('gifts')->join('users', 'users.id', '=', 'gifts.sender_id')->leftJoin('gift_types', 'gift_types.id', '=', 'gifts.gift_type_id')->whereIn('gifts.ledger_transaction_id', $ids)->select('gifts.ledger_transaction_id', 'gifts.message', 'gift_types.name as gift_type_name', 'users.name as counterpart_name', 'users.handle as counterpart_handle')->get()->keyBy('ledger_transaction_id');
        $payouts = DB::table('creator_payouts')->leftJoin('payout_methods', 'payout_methods.id', '=', 'creator_payouts.payout_method_id')->whereIn('creator_payouts.ledger_transaction_id', $ids)->select('creator_payouts.ledger_transaction_id', 'creator_payouts.state as payout_state', 'payout_methods.label as payout_label', 'payout_methods.destination_last_four as payout_last_four')->get()->keyBy('ledger_transaction_id');

        return $rows->map(function ($row) use ($amounts, $gifts, $payouts): array {
            $amount = $amounts->get($row->id);
            $gift = $gifts->get($row->id);
            $payout = $payouts->get($row->id);

            return ['id' => $row->id, 'reference' => $row->reference, 'event_type' => $row->event_type, 'created_at' => $row->created_at, 'amount' => (int) ($amount?->amount ?? 0), 'unit' => $amount?->unit ?? 'PCN', 'counterpart_name' => $gift?->counterpart_name, 'counterpart_handle' => $gift?->counterpart_handle, 'message' => $gift?->message, 'gift_type_name' => $gift?->gift_type_name, 'payout_state' => $payout?->payout_state, 'payout_label' => $payout?->payout_label, 'payout_last_four' => $payout?->payout_last_four];
        })->values()->all();
    }

    private function episodeRow(string $episode, Request $request): ?object
    {
        return DB::table('episodes')->join('shows', 'shows.id', '=', 'episodes.show_id')->where('episodes.id', $episode)->whereIn('episodes.show_id', $this->showIds($request))->select('episodes.id', 'episodes.show_id', 'episodes.title', 'episodes.description', 'episodes.duration_seconds', 'episodes.audio_url', 'episodes.published_at', 'episodes.availability', 'shows.title as show_title', 'shows.artwork_url as artwork_url')->first();
    }

    private function limit(Request $request): int
    {
        return min(max($request->integer('limit', 20), 1), 50);
    }

    private function page($items): JsonResponse
    {
        return ApiResponse::success($items->items(), ['cursor' => $items->nextCursor()?->encode(), 'has_more' => $items->hasMorePages()]);
    }

    private function presentReel(object $row, Request $request): array
    {
        $thumb = is_string($row->thumbnail_path ?? null) ? $row->thumbnail_path : null;
        $media = is_string($row->media_url ?? null) ? $row->media_url : null;
        $id = (string) $row->id;

        return [
            'id' => $id,
            'title' => $row->title ?? null,
            'caption' => $row->caption,
            'state' => $row->state,
            'duration_ms' => $row->duration_ms === null ? null : (int) $row->duration_ms,
            'published_at' => $row->published_at,
            'episode_id' => $row->episode_id,
            'show_id' => $row->show_id,
            'episode_title' => $row->episode_title,
            'linked' => (bool) $row->linked,
            'media_url' => $media !== null && $media !== ''
                ? ReelPlayback::resolve($media, ReelPlayback::videoUrl($id, $request))
                : ($thumb ? ReelPlayback::videoUrl($id, $request) : null),
            'thumbnail_path' => $thumb !== null && $thumb !== ''
                ? ReelPlayback::resolve($thumb, ReelPlayback::thumbnailUrl($id, $request))
                : null,
        ];
    }

    private function reelColumns(): array
    {
        return ['reels.id', 'reels.title', 'reels.caption', 'reels.state', 'reels.duration_ms', 'reels.published_at', 'reels.episode_id', 'reels.show_id', 'reels.media_url', DB::raw('coalesce(episodes.title, (select episodes.title from reel_episode_links join episodes on episodes.id = reel_episode_links.episode_id where reel_episode_links.reel_id = reels.id limit 1)) as episode_title'), DB::raw('(select thumbnail_path from reel_media where reel_media.reel_id = reels.id order by reel_media.created_at desc limit 1) as thumbnail_path'), DB::raw('(reels.episode_id is not null or exists (select 1 from reel_episode_links where reel_episode_links.reel_id = reels.id)) as linked')];
    }
}
