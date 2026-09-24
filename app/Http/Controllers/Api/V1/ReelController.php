<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\TranscodeReelMedia;
use App\Models\CreatorProfile;
use App\Support\ApiResponse;
use App\Support\PublishedReelPresenter;
use App\Support\ReelLimits;
use App\Support\ReelPlayback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReelController extends Controller
{
    public function feed(Request $request, PublishedReelPresenter $presenter): JsonResponse
    {
        return $this->publishedFeed($request, 'recent', $presenter);
    }

    public function following(Request $request, PublishedReelPresenter $presenter): JsonResponse
    {
        return $this->publishedFeed($request, 'following', $presenter);
    }

    public function trending(Request $request, PublishedReelPresenter $presenter): JsonResponse
    {
        return $this->publishedFeed($request, 'trending', $presenter);
    }

    public function saved(Request $request, PublishedReelPresenter $presenter): JsonResponse
    {
        return $this->publishedFeed($request, 'saved', $presenter);
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

    public function show(string $reel, Request $request, PublishedReelPresenter $presenter): JsonResponse
    {
        $row = DB::table('reels')->where('id', $reel)->where('state', 'published')->first();

        if (! $row) {
            return ApiResponse::error('NOT_FOUND', 'Reel not found.', 404);
        }
        $presented = $presenter->present([$row], $request->user()->id, $request);
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

    private function publishedFeed(Request $request, string $mode, PublishedReelPresenter $presenter): JsonResponse
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
        } elseif ($mode === 'saved') {
            $query->join('reel_engagements', function ($join) use ($request): void {
                $join->on('reel_engagements.reel_id', '=', 'reels.id')
                    ->where('reel_engagements.user_id', $request->user()->id)
                    ->where('reel_engagements.saved', true);
            })->select('reels.*')->orderByDesc('reel_engagements.updated_at');
        } else {
            $query->orderByDesc('reels.published_at');
        }
        $items = $query->orderByDesc('reels.id')->cursorPaginate(min($request->integer('limit', 20), 50));

        return ApiResponse::success($presenter->present($items->items(), $request->user()->id, $request), ['cursor' => $items->nextCursor()?->encode(), 'has_more' => $items->hasMorePages()]);
    }
}
