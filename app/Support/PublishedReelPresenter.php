<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PublishedReelPresenter
{
    /**
     * @param  iterable<int, object>  $rows
     * @return list<array<string, mixed>>
     */
    public function present(iterable $rows, string $userId, Request $request): array
    {
        $items = collect($rows)->values();
        if ($items->isEmpty()) {
            return [];
        }
        $ids = $items->pluck('id')->all();
        $creatorIds = $items->pluck('creator_profile_id')->unique()->values()->all();
        $episodeIds = $items->pluck('episode_id')->filter()->unique()->values()->all();
        $linkRows = DB::table('reel_episode_links')->whereIn('reel_id', $ids)->orderBy('created_at')->get(['reel_id', 'episode_id']);
        $linkedByReel = $linkRows->groupBy('reel_id');
        $episodeIds = collect($episodeIds)->merge($linkRows->pluck('episode_id'))->unique()->filter()->values()->all();
        $creators = DB::table('creator_profiles')
            ->leftJoin('users', 'users.id', '=', 'creator_profiles.user_id')
            ->leftJoin('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->whereIn('creator_profiles.id', $creatorIds)
            ->select('creator_profiles.id', 'creator_profiles.display_name', 'users.handle', 'user_profiles.avatar_url')
            ->get()
            ->keyBy('id');
        $thumbnails = DB::table('reel_media')->whereIn('reel_id', $ids)->orderByDesc('created_at')->get(['reel_id', 'thumbnail_path', 'transcoded_path'])->unique('reel_id')->keyBy('reel_id');
        $likes = DB::table('reel_engagements')->whereIn('reel_id', $ids)->where('liked', true)->selectRaw('reel_id, count(*) as aggregate')->groupBy('reel_id')->pluck('aggregate', 'reel_id');
        $saves = DB::table('reel_engagements')->whereIn('reel_id', $ids)->where('saved', true)->selectRaw('reel_id, count(*) as aggregate')->groupBy('reel_id')->pluck('aggregate', 'reel_id');
        $comments = DB::table('comments')->where('commentable_type', 'reel')->whereIn('commentable_id', $ids)->whereNull('hidden_at')->selectRaw('commentable_id, count(*) as aggregate')->groupBy('commentable_id')->pluck('aggregate', 'commentable_id');
        $mine = DB::table('reel_engagements')->where('user_id', $userId)->whereIn('reel_id', $ids)->get()->keyBy('reel_id');
        $following = DB::table('creator_followers')->where('user_id', $userId)->whereIn('creator_profile_id', $creatorIds)->pluck('creator_profile_id')->all();
        $episodes = $episodeIds === []
            ? collect()
            : DB::table('episodes')->whereIn('id', $episodeIds)->get(['id', 'title', 'show_id'])->keyBy('id');

        return $items->map(function (object $row) use ($creators, $thumbnails, $likes, $saves, $comments, $mine, $following, $episodes, $linkedByReel, $request): ?array {
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
            $resolvedEpisodeId = is_string($row->episode_id) && $row->episode_id !== ''
                ? $row->episode_id
                : $linkedByReel->get($row->id)?->first()?->episode_id;
            $episode = $resolvedEpisodeId ? $episodes->get($resolvedEpisodeId) : null;

            return [
                'id' => (string) $row->id,
                'title' => $row->title ?? null,
                'caption' => $row->caption,
                'media_url' => $mediaUrl,
                'thumbnail_path' => $thumbnail,
                'duration_ms' => $row->duration_ms === null ? null : (int) $row->duration_ms,
                'published_at' => $row->published_at,
                'creator_profile_id' => (string) $row->creator_profile_id,
                'creator_name' => $creator->display_name,
                'creator_handle' => is_string($creator->handle) ? $creator->handle : '',
                'creator_avatar_url' => $this->publicUrl(is_string($creator->avatar_url) ? $creator->avatar_url : null, $request),
                'show_id' => $row->show_id ?: ($episode?->show_id),
                'episode_id' => $resolvedEpisodeId,
                'episode_title' => $episode?->title,
                'likes_count' => (int) ($likes[$row->id] ?? 0),
                'comments_count' => (int) ($comments[$row->id] ?? 0),
                'saves_count' => (int) ($saves[$row->id] ?? 0),
                'liked' => $this->flag($engagement?->liked),
                'saved' => $this->flag($engagement?->saved),
                'following' => in_array($row->creator_profile_id, $following, true),
            ];
        })->filter()->values()->all();
    }

    private function publicUrl(?string $stored, Request $request): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }
        if (str_starts_with($stored, 'http://') || str_starts_with($stored, 'https://')) {
            return $stored;
        }

        return rtrim($request->getSchemeAndHttpHost(), '/').'/'.ltrim($stored, '/');
    }

    private function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
