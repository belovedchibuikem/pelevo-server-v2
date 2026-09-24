<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\TranscodeReelMedia;
use App\Models\CreatorProfile;
use App\Support\ApiResponse;
use App\Support\ReelLimits;
use App\Support\ReelPlayback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReelController extends Controller
{
    public function feed(Request $request): JsonResponse
    {
        return $this->publishedFeed($request, 'recent');
    }

    public function following(Request $request): JsonResponse
    {
        return $this->publishedFeed($request, 'following');
    }

    public function trending(Request $request): JsonResponse
    {
        return $this->publishedFeed($request, 'trending');
    }

    public function store(Request $request): JsonResponse
    {
        $creator = CreatorProfile::where('user_id', $request->user()->id)->first();
        if (! $creator) {
            return ApiResponse::error('CLAIM_REQUIRED', 'Creator access is required.', 403);
        }
        $data = $request->validate(['draft_id' => ['nullable', 'exists:reels,id'], 'upload_id' => ['required', 'exists:media_uploads,id'], 'caption' => ['nullable', 'string', 'max:400'], 'show_id' => ['nullable', 'exists:shows,id'], 'episode_id' => ['nullable', 'exists:episodes,id']]);
        $created = DB::transaction(function () use ($creator, $request, $data): array {
            $upload = DB::table('media_uploads')->where('id', $data['upload_id'])->where('user_id', $request->user()->id)->lockForUpdate()->first();
            if (! $upload || $upload->state !== 'processed' || DB::table('reel_media')->where('media_upload_id', $data['upload_id'])->exists()) {
                abort(409, 'The processed upload is unavailable.');
            }
            $probe = is_array($upload->probe) ? $upload->probe : json_decode((string) $upload->probe, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($probe)) {
                abort(409, 'The processed upload is unavailable.');
            }
            $originalMs = (int) ($probe['original_duration_ms'] ?? $probe['duration_ms'] ?? 0);
            $truncated = ($probe['truncated'] ?? false) === true || $originalMs > ReelLimits::maxDurationMs();
            $durationMs = ReelLimits::cappedDurationMs($originalMs);
            $id = $data['draft_id'] ?? (string) Str::ulid();
            $reelData = collect($data)->except(['upload_id', 'draft_id'])->all();
            if ($data['draft_id'] ?? null) {
                $updated = DB::table('reels')->where('id', $id)->where('creator_profile_id', $creator->id)->where('state', 'draft')->update([...$reelData, 'state' => 'processing', 'duration_ms' => $durationMs, 'updated_at' => now()]);
                abort_unless($updated, 404);
            } else {
                DB::table('reels')->insert(['id' => $id, 'creator_profile_id' => $creator->id, ...$reelData, 'state' => 'processing', 'duration_ms' => $durationMs, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('reel_media')->insert(['id' => (string) Str::ulid(), 'reel_id' => $id, 'media_upload_id' => $upload->id, 'mime' => $probe['mime'], 'duration_ms' => $durationMs, 'width' => $probe['width'], 'height' => $probe['height'], 'processing_state' => 'processing', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('reel_processing_events')->insert(['id' => (string) Str::ulid(), 'reel_id' => $id, 'state' => 'processing', 'details' => json_encode(['truncated' => $truncated, 'original_duration_ms' => $originalMs], JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return ['id' => $id, 'truncated' => $truncated, 'original_duration_ms' => $originalMs];
        });
        TranscodeReelMedia::dispatch($created['id']);
        $row = (array) DB::table('reels')->find($created['id']);

        return ApiResponse::success([
            ...$row,
            'truncated' => $created['truncated'],
            'original_duration_ms' => $created['truncated'] ? $created['original_duration_ms'] : null,
            'truncated_message' => $created['truncated'] ? ReelLimits::truncatedMessage() : null,
        ], status: 201);
    }

    public function show(string $reel, Request $request): JsonResponse
    {
        $row = DB::table('reels')->where('id', $reel)->where('state', 'published')->first();

        if (! $row) {
            return ApiResponse::error('NOT_FOUND', 'Reel not found.', 404);
        }
        $presented = $this->presentPublishedReels([$row], $request->user()->id, $request);
        if ($presented === []) {
            return ApiResponse::error('NOT_FOUND', 'Reel not found.', 404);
        }
        $presented[0]['episode_links'] = DB::table('reel_episode_links')->where('reel_id', $reel)->pluck('episode_id')->values()->all();

        return ApiResponse::success($presented[0]);
    }

    public function linkEpisode(string $reel, Request $request): JsonResponse
    {
        $data = $request->validate(['episode_id' => ['required', 'exists:episodes,id']]);
        $creator = CreatorProfile::where('user_id', $request->user()->id)->first();
        abort_unless($creator && DB::table('reels')->where('id', $reel)->where('creator_profile_id', $creator->id)->exists(), 404);
        DB::table('reel_episode_links')->insertOrIgnore(['reel_id' => $reel, 'episode_id' => $data['episode_id'], 'created_at' => now(), 'updated_at' => now()]);
        Cache::forget('reel:'.$reel);

        return ApiResponse::success(['linked' => true]);
    }

    public function cover(string $reel, Request $request): JsonResponse
    {
        $creator = CreatorProfile::where('user_id', $request->user()->id)->first();
        $row = $creator ? DB::table('reels')->where('id', $reel)->where('creator_profile_id', $creator->id)->first() : null;
        if (! $row) {
            return ApiResponse::error('NOT_FOUND', 'Reel not found.', 404);
        }
        $data = $request->validate([
            'cover' => ['required', 'file', 'max:8192', 'mimetypes:image/jpeg,image/png,image/webp,image/jpg'],
        ]);
        $disk = (string) config('media.upload_disk', 'local');
        $stored = $data['cover']->store('reels/'.$reel, $disk);
        DB::table('reel_media')->where('reel_id', $reel)->update([
            'thumbnail_path' => $stored,
            'updated_at' => now(),
        ]);
        Cache::forget('reel:'.$reel);

        return ApiResponse::success([
            'id' => $reel,
            'thumbnail_path' => ReelPlayback::thumbnailUrl($reel, $request),
        ]);
    }

    public function relatedShow(string $reel): JsonResponse
    {
        $row = DB::table('reels')->where('reels.id', $reel)->where('reels.state', 'published')->leftJoin('shows', 'shows.id', '=', 'reels.show_id')->select('shows.id', 'shows.title', 'shows.author', 'shows.artwork_url')->first();
        if (! $row?->id) {
            return ApiResponse::error('NOT_FOUND', 'This reel has no available related show.', 404);
        }

        return ApiResponse::success($row);
    }

    private function publishedFeed(Request $request, string $mode): JsonResponse
    {
        $query = DB::table('reels')->where('reels.state', 'published')->whereNotExists(function ($hidden) use ($request): void {
            $hidden->selectRaw('1')->from('reel_engagements')->whereColumn('reel_engagements.reel_id', 'reels.id')->where('reel_engagements.user_id', $request->user()->id)->where('reel_engagements.not_interested', true);
        });
        if ($mode === 'following') {
            $query->join('follows', function ($join) use ($request): void {
                $join->on('follows.show_id', '=', 'reels.show_id')->where('follows.user_id', $request->user()->id);
            })->select('reels.*');
        }
        if ($mode === 'trending') {
            $query->leftJoin('reel_view_credits', 'reel_view_credits.reel_id', '=', 'reels.id')->select('reels.*')->selectRaw('count(reel_view_credits.reel_view_id) as qualified_views')->groupBy('reels.id')->orderByDesc('qualified_views');
        } else {
            $query->orderByDesc('reels.published_at');
        }
        $items = $query->orderByDesc('reels.id')->cursorPaginate(min($request->integer('limit', 20), 50));

        return ApiResponse::success($this->presentPublishedReels($items->items(), $request->user()->id, $request), ['cursor' => $items->nextCursor()?->encode(), 'has_more' => $items->hasMorePages()]);
    }

    /**
     * @param  iterable<int, object>  $rows
     * @return list<array<string, mixed>>
     */
    private function presentPublishedReels(iterable $rows, string $userId, Request $request): array
    {
        $items = collect($rows)->values();
        if ($items->isEmpty()) {
            return [];
        }
        $ids = $items->pluck('id')->all();
        $creatorIds = $items->pluck('creator_profile_id')->unique()->values()->all();
        $episodeIds = $items->pluck('episode_id')->filter()->unique()->values()->all();
        $creators = DB::table('creator_profiles')->leftJoin('users', 'users.id', '=', 'creator_profiles.user_id')->whereIn('creator_profiles.id', $creatorIds)->select('creator_profiles.id', 'creator_profiles.display_name', 'users.handle')->get()->keyBy('id');
        $thumbnails = DB::table('reel_media')->whereIn('reel_id', $ids)->orderByDesc('created_at')->get(['reel_id', 'thumbnail_path', 'transcoded_path'])->unique('reel_id')->keyBy('reel_id');
        $likes = DB::table('reel_engagements')->whereIn('reel_id', $ids)->where('liked', true)->selectRaw('reel_id, count(*) as aggregate')->groupBy('reel_id')->pluck('aggregate', 'reel_id');
        $saves = DB::table('reel_engagements')->whereIn('reel_id', $ids)->where('saved', true)->selectRaw('reel_id, count(*) as aggregate')->groupBy('reel_id')->pluck('aggregate', 'reel_id');
        $comments = DB::table('comments')->where('commentable_type', 'reel')->whereIn('commentable_id', $ids)->whereNull('hidden_at')->selectRaw('commentable_id, count(*) as aggregate')->groupBy('commentable_id')->pluck('aggregate', 'commentable_id');
        $mine = DB::table('reel_engagements')->where('user_id', $userId)->whereIn('reel_id', $ids)->get()->keyBy('reel_id');
        $following = DB::table('creator_followers')->where('user_id', $userId)->whereIn('creator_profile_id', $creatorIds)->pluck('creator_profile_id')->all();
        $episodes = $episodeIds === [] ? collect() : DB::table('episodes')->whereIn('id', $episodeIds)->pluck('title', 'id');

        return $items->map(function (object $row) use ($creators, $thumbnails, $likes, $saves, $comments, $mine, $following, $episodes, $request): ?array {
            $creator = $creators->get($row->creator_profile_id);
            if (! is_string($creator?->display_name) || $creator->display_name === '') {
                return null;
            }
            $engagement = $mine->get($row->id);
            $media = $thumbnails->get($row->id);
            $storedMedia = is_string($row->media_url) ? $row->media_url : null;
            $storedThumb = is_string($media?->thumbnail_path) ? $media->thumbnail_path : null;
            $hasFile = is_string($media?->transcoded_path) && $media->transcoded_path !== '';
            $mediaUrl = $storedMedia !== null && $storedMedia !== ''
                ? ReelPlayback::resolve($storedMedia, ReelPlayback::videoUrl((string) $row->id, $request))
                : ($hasFile ? ReelPlayback::videoUrl((string) $row->id, $request) : null);
            $thumbnail = $storedThumb !== null && $storedThumb !== ''
                ? ReelPlayback::resolve($storedThumb, ReelPlayback::thumbnailUrl((string) $row->id, $request))
                : null;

            return [
                'id' => (string) $row->id,
                'caption' => $row->caption,
                'media_url' => $mediaUrl,
                'thumbnail_path' => $thumbnail,
                'duration_ms' => $row->duration_ms === null ? null : (int) $row->duration_ms,
                'published_at' => $row->published_at,
                'creator_profile_id' => (string) $row->creator_profile_id,
                'creator_name' => $creator->display_name,
                'creator_handle' => is_string($creator->handle) ? $creator->handle : '',
                'show_id' => $row->show_id,
                'episode_id' => $row->episode_id,
                'episode_title' => $row->episode_id ? ($episodes[$row->episode_id] ?? null) : null,
                'likes_count' => (int) ($likes[$row->id] ?? 0),
                'comments_count' => (int) ($comments[$row->id] ?? 0),
                'saves_count' => (int) ($saves[$row->id] ?? 0),
                'liked' => $this->flag($engagement?->liked),
                'saved' => $this->flag($engagement?->saved),
                'following' => in_array($row->creator_profile_id, $following, true),
            ];
        })->filter()->values()->all();
    }

    private function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
