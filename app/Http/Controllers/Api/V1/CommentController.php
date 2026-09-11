<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CreatorProfile;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CommentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'commentable_type' => ['required', 'in:episode,reel'],
            'commentable_id' => ['required', 'string', 'max:26'],
            'parent_id' => ['nullable', 'string', 'max:26'],
            'sort' => ['nullable', 'in:newest,top,creator'],
        ]);
        $query = DB::table('comments')->where('commentable_type', $data['commentable_type'])->where('commentable_id', $data['commentable_id'])->whereNull('hidden_at');
        if (array_key_exists('parent_id', $data)) {
            $query->where('parent_id', $data['parent_id']);
        }
        $rows = $query->orderByDesc('is_pinned')->orderByDesc('created_at')->limit(min($request->integer('limit', 50), 100))->get();

        return ApiResponse::success($this->presentMany($rows, $request, $data['sort'] ?? 'newest'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['commentable_type' => ['required', 'in:episode,reel'], 'commentable_id' => ['required', 'string'], 'parent_id' => ['nullable', 'exists:comments,id'], 'body' => ['required', 'string', 'max:400']]);
        $table = $data['commentable_type'] === 'episode' ? 'episodes' : 'reels';
        abort_unless(DB::table($table)->where('id', $data['commentable_id'])->exists(), 404);
        if (isset($data['parent_id'])) {
            $parent = DB::table('comments')->where('id', $data['parent_id'])->whereNull('hidden_at')->first();
            if (! $parent || $parent->commentable_type !== $data['commentable_type'] || $parent->commentable_id !== $data['commentable_id']) {
                return ApiResponse::error('INVALID_PARENT', 'The parent comment belongs to different content.', 422);
            }
        }
        $id = (string) Str::ulid();
        DB::table('comments')->insert(['id' => $id, 'user_id' => $request->user()->id, ...$data, 'created_at' => now(), 'updated_at' => now()]);

        return ApiResponse::success($this->presentOne(DB::table('comments')->find($id), $request), status: 201);
    }

    public function update(string $comment, Request $request): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:400']]);
        $row = DB::table('comments')->where('id', $comment)->where('user_id', $request->user()->id)->first();
        if (! $row) {
            return ApiResponse::error('NOT_FOUND', 'Comment not found.', 404);
        }
        if (now()->diffInMinutes($row->created_at) > 15) {
            return ApiResponse::error('FORBIDDEN', 'The comment edit window has expired.', 403);
        }
        DB::table('comments')->where('id', $comment)->update(['body' => $data['body'], 'edited_at' => now(), 'updated_at' => now()]);

        return ApiResponse::success($this->presentOne(DB::table('comments')->find($comment), $request));
    }

    public function destroy(string $comment, Request $request): JsonResponse
    {
        $updated = DB::table('comments')->where('id', $comment)->where('user_id', $request->user()->id)->whereNull('hidden_at')->update(['hidden_at' => now(), 'updated_at' => now()]);

        return $updated ? ApiResponse::success(['deleted' => true]) : ApiResponse::error('NOT_FOUND', 'Comment not found.', 404);
    }

    public function like(string $comment, Request $request): JsonResponse
    {
        abort_unless(DB::table('comments')->where('id', $comment)->whereNull('hidden_at')->exists(), 404);
        DB::table('comment_likes')->insertOrIgnore(['comment_id' => $comment, 'user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);

        return ApiResponse::success(['liked' => true]);
    }

    public function unlike(string $comment, Request $request): JsonResponse
    {
        DB::table('comment_likes')->where('comment_id', $comment)->where('user_id', $request->user()->id)->delete();

        return ApiResponse::success(['liked' => false]);
    }

    public function pin(string $comment, Request $request): JsonResponse
    {
        $row = DB::table('comments')->where('id', $comment)->whereNull('parent_id')->whereNull('hidden_at')->first();
        abort_unless($row && $this->creatorOwns($row, $request), 404);
        DB::transaction(function () use ($row, $request): void {
            $existing = DB::table('comment_pins')->where('commentable_type', $row->commentable_type)->where('commentable_id', $row->commentable_id)->lockForUpdate()->first();
            if ($existing) {
                DB::table('comments')->where('id', $existing->comment_id)->update(['is_pinned' => false, 'updated_at' => now()]);
            }
            DB::table('comment_pins')->updateOrInsert(['commentable_type' => $row->commentable_type, 'commentable_id' => $row->commentable_id], ['comment_id' => $row->id, 'pinned_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('comments')->where('id', $row->id)->update(['is_pinned' => true, 'updated_at' => now()]);
        });

        return ApiResponse::success(['pinned' => true]);
    }

    public function unpin(string $comment, Request $request): JsonResponse
    {
        $row = DB::table('comments')->where('id', $comment)->first();
        abort_unless($row && $this->creatorOwns($row, $request), 404);
        DB::transaction(function () use ($row): void {
            DB::table('comment_pins')->where('comment_id', $row->id)->delete();
            DB::table('comments')->where('id', $row->id)->update(['is_pinned' => false, 'updated_at' => now()]);
        });

        return ApiResponse::success(['pinned' => false]);
    }

    public function hide(string $comment, Request $request): JsonResponse
    {
        $row = DB::table('comments')->where('id', $comment)->first();
        abort_unless($row && $this->creatorOwns($row, $request), 404);
        DB::table('comments')->where('id', $comment)->update(['hidden_at' => now(), 'is_pinned' => false, 'updated_at' => now()]);
        DB::table('comment_pins')->where('comment_id', $comment)->delete();

        return ApiResponse::success(['hidden' => true]);
    }

    private function creatorOwns(object $comment, Request $request): bool
    {
        $creator = CreatorProfile::where('user_id', $request->user()->id)->first();
        if (! $creator) {
            return false;
        }
        if ($comment->commentable_type === 'reel') {
            return DB::table('reels')->where('id', $comment->commentable_id)->where('creator_profile_id', $creator->id)->exists();
        }

        return DB::table('episodes')->join('verified_show_claims', 'verified_show_claims.show_id', '=', 'episodes.show_id')->join('show_claims', 'show_claims.id', '=', 'verified_show_claims.show_claim_id')->where('episodes.id', $comment->commentable_id)->where('show_claims.creator_profile_id', $creator->id)->exists();
    }

    private function presentOne(object $row, Request $request): array
    {
        return $this->presentMany(collect([$row]), $request, 'newest')[0];
    }

    private function presentMany(iterable $rows, Request $request, string $sort): array
    {
        $items = collect($rows)->values();
        if ($items->isEmpty()) {
            return [];
        }
        $ids = $items->pluck('id');
        $users = DB::table('users')->whereIn('id', $items->pluck('user_id')->unique())->get(['id', 'name', 'handle'])->keyBy('id');
        $likeCounts = DB::table('comment_likes')->whereIn('comment_id', $ids)->select('comment_id', DB::raw('count(*) as like_count'))->groupBy('comment_id')->pluck('like_count', 'comment_id');
        $liked = DB::table('comment_likes')->where('user_id', $request->user()->id)->whereIn('comment_id', $ids)->pluck('comment_id')->all();
        $replyCounts = DB::table('comments')->whereIn('parent_id', $ids)->whereNull('hidden_at')->select('parent_id', DB::raw('count(*) as replies_count'))->groupBy('parent_id')->pluck('replies_count', 'parent_id');
        $first = $items->first();
        $creatorUserId = $this->contentCreatorUserId($first->commentable_type, $first->commentable_id);
        $likedLookup = array_flip($liked);
        $presented = $items->map(function (object $row) use ($users, $likeCounts, $likedLookup, $replyCounts, $request, $creatorUserId): array {
            $user = $users->get($row->user_id);
            $handle = $user->handle ?? null;

            return [
                'id' => $row->id,
                'body' => $row->body,
                'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
                'edited_at' => $row->edited_at ? Carbon::parse($row->edited_at)->toIso8601String() : null,
                'parent_id' => $row->parent_id,
                'is_pinned' => (int) $row->is_pinned === 1,
                'author_name' => $user->name ?? '',
                'author_handle' => $handle === null || $handle === '' ? '' : $handle,
                'like_count' => (int) ($likeCounts[$row->id] ?? 0),
                'liked_by_me' => isset($likedLookup[$row->id]),
                'is_mine' => $row->user_id === $request->user()->id,
                'is_creator' => $creatorUserId !== null && $row->user_id === $creatorUserId,
                'replies_count' => $row->parent_id ? 0 : (int) ($replyCounts[$row->id] ?? 0),
            ];
        });
        $top = $presented->filter(fn (array $row): bool => $row['parent_id'] === null)->values();
        $replies = $presented->filter(fn (array $row): bool => $row['parent_id'] !== null)->values();
        if ($top->isEmpty()) {
            return $replies->values()->all();
        }
        if ($sort === 'creator') {
            $top = $top->filter(fn (array $row): bool => $row['is_creator'])->values();
        }
        if ($sort === 'top') {
            $top = $top->sort(function (array $left, array $right): int {
                if ($left['is_pinned'] !== $right['is_pinned']) {
                    return $right['is_pinned'] <=> $left['is_pinned'];
                }
                if ($left['like_count'] !== $right['like_count']) {
                    return $right['like_count'] <=> $left['like_count'];
                }

                return strcmp($right['created_at'], $left['created_at']);
            })->values();
        }
        $allowedParents = $top->pluck('id')->all();

        return $top->concat($replies->filter(fn (array $row): bool => in_array($row['parent_id'], $allowedParents, true)))->values()->all();
    }

    private function contentCreatorUserId(string $type, string $id): ?string
    {
        if ($type === 'reel') {
            return DB::table('reels')->join('creator_profiles', 'creator_profiles.id', '=', 'reels.creator_profile_id')->where('reels.id', $id)->value('creator_profiles.user_id');
        }

        return DB::table('episodes')->join('verified_show_claims', 'verified_show_claims.show_id', '=', 'episodes.show_id')->join('show_claims', 'show_claims.id', '=', 'verified_show_claims.show_claim_id')->join('creator_profiles', 'creator_profiles.id', '=', 'show_claims.creator_profile_id')->where('episodes.id', $id)->value('creator_profiles.user_id');
    }
}
