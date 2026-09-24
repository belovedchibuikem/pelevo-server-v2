<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CreatorProfile;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReelDraftController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $creator = $this->creator($request);
        $items = DB::table('reels')->where('creator_profile_id', $creator->id)->where('state', 'draft')->orderByDesc('updated_at')->orderByDesc('id')->cursorPaginate(30);

        return ApiResponse::success(array_map($this->presentDraft(...), $items->items()), ['cursor' => $items->nextCursor()?->encode(), 'has_more' => $items->hasMorePages()]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $creator = $this->creator($request);
        $data = $this->payload($request, $creator);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $id = (string) Str::ulid();
        DB::table('reels')->insert(['id' => $id, 'creator_profile_id' => $creator->id, ...$data, 'state' => 'draft', 'created_at' => now(), 'updated_at' => now()]);

        return ApiResponse::success($this->presentDraft(DB::table('reels')->find($id)), status: 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id, Request $request): JsonResponse
    {
        return ApiResponse::success($this->presentDraft($this->draft($id, $request)));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $draft = $this->draft($id, $request);
        $data = $this->payload($request, $this->creator($request));
        if ($data instanceof JsonResponse) {
            return $data;
        }
        DB::table('reels')->where('id', $draft->id)->update([...$data, 'updated_at' => now()]);

        return ApiResponse::success($this->presentDraft(DB::table('reels')->find($id)));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id, Request $request): JsonResponse
    {
        $draft = $this->draft($id, $request);
        DB::table('reels')->where('id', $draft->id)->delete();

        return ApiResponse::success(['deleted' => true]);
    }

    private function creator(Request $request): CreatorProfile
    {
        $creator = CreatorProfile::where('user_id', $request->user()->id)->first();
        abort_unless($creator, 403, 'Creator access is required.');

        return $creator;
    }

    private function draft(string $id, Request $request): object
    {
        $creator = $this->creator($request);

        return DB::table('reels')->where('id', $id)->where('creator_profile_id', $creator->id)->where('state', 'draft')->firstOrFail();
    }

    private function payload(Request $request, CreatorProfile $creator): array|JsonResponse
    {
        $data = $request->validate(['title' => ['nullable', 'string', 'max:120'], 'caption' => ['nullable', 'string', 'max:400'], 'show_id' => ['nullable', 'exists:shows,id'], 'episode_id' => ['nullable', 'exists:episodes,id']]);
        if (! empty($data['episode_id'])) {
            $showId = DB::table('episodes')->join('verified_show_claims', 'verified_show_claims.show_id', '=', 'episodes.show_id')->join('show_claims', 'show_claims.id', '=', 'verified_show_claims.show_claim_id')->where('episodes.id', $data['episode_id'])->where('show_claims.creator_profile_id', $creator->id)->value('episodes.show_id');
            if (! $showId) {
                return ApiResponse::error('UNPROCESSABLE', 'Episode is not in your claimed studio.', 422);
            }
            $data['show_id'] = $showId;
        } elseif (! empty($data['show_id'])) {
            $owned = DB::table('verified_show_claims')->join('show_claims', 'show_claims.id', '=', 'verified_show_claims.show_claim_id')->where('verified_show_claims.show_id', $data['show_id'])->where('show_claims.creator_profile_id', $creator->id)->exists();
            if (! $owned) {
                return ApiResponse::error('UNPROCESSABLE', 'Show is not in your claimed studio.', 422);
            }
        }

        return $data;
    }

    private function presentDraft(?object $row): array
    {
        abort_unless($row, 404);

        return [
            'id' => (string) $row->id,
            'title' => $row->title ?? null,
            'caption' => $row->caption,
            'state' => $row->state,
            'show_id' => $row->show_id,
            'episode_id' => $row->episode_id,
            'duration_ms' => $row->duration_ms === null ? null : (int) $row->duration_ms,
            'updated_at' => $row->updated_at,
            'created_at' => $row->created_at,
        ];
    }
}
