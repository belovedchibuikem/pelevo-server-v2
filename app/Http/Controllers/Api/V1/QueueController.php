<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Episode;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class QueueController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $queue = DB::table('queues')->where('user_id', $request->user()->id)->first();
        $items = $queue ? DB::table('queue_items')
            ->join('episodes', 'episodes.id', '=', 'queue_items.episode_id')
            ->join('shows', 'shows.id', '=', 'episodes.show_id')
            ->where('queue_id', $queue->id)
            ->orderBy('position')
            ->get(['queue_items.id as queue_item_id', 'queue_items.position', 'episodes.id', 'episodes.show_id', 'episodes.title', 'episodes.duration_seconds', 'shows.title as show_title', 'shows.artwork_url'])
            ->map(fn (object $row): array => [
                'queue_item_id' => $row->queue_item_id,
                'position' => (int) $row->position,
                'id' => $row->id,
                'show_id' => $row->show_id,
                'title' => $row->title,
                'show_title' => $row->show_title,
                'duration_seconds' => $row->duration_seconds === null ? null : (int) $row->duration_seconds,
                'artwork_url' => $row->artwork_url,
            ]) : [];

        return ApiResponse::success(['version' => $queue?->version ?? 0, 'items' => $items]);
    }

    public function replace(Request $request): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:0'], 'episode_ids' => ['required', 'array', 'max:200'], 'episode_ids.*' => ['required', 'distinct', 'exists:episodes,id']]);

        return DB::transaction(function () use ($request, $data): JsonResponse {
            $queue = DB::table('queues')->where('user_id', $request->user()->id)->lockForUpdate()->first();
            if (($queue?->version ?? 0) !== $data['version']) {
                return ApiResponse::error('VERSION_CONFLICT', 'Queue changed on another device.', 409);
            }
            if (! $queue) {
                $queueId = (string) Str::ulid();
                DB::table('queues')->insert(['id' => $queueId, 'user_id' => $request->user()->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $queueId = $queue->id;
                DB::table('queues')->where('id', $queueId)->update(['version' => $queue->version + 1, 'updated_at' => now()]);
                DB::table('queue_items')->where('queue_id', $queueId)->delete();
            }
            foreach ($data['episode_ids'] as $position => $episodeId) {
                DB::table('queue_items')->insert(['id' => (string) Str::ulid(), 'queue_id' => $queueId, 'episode_id' => $episodeId, 'position' => $position, 'created_at' => now(), 'updated_at' => now()]);
            }

            return ApiResponse::success(['version' => ($queue?->version ?? 0) + 1, 'count' => count($data['episode_ids'])]);
        });
    }

    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:0'],
            'episode_ids' => ['required', 'array', 'min:1', 'max:200'],
            'episode_ids.*' => ['required', 'distinct', 'exists:episodes,id'],
        ]);

        return DB::transaction(function () use ($request, $data): JsonResponse {
            $queue = DB::table('queues')->where('user_id', $request->user()->id)->lockForUpdate()->first();
            if (! $queue) {
                return ApiResponse::error('NOT_FOUND', 'Queue is empty.', 404);
            }
            if ($queue->version !== $data['version']) {
                return ApiResponse::error('VERSION_CONFLICT', 'Queue changed on another device.', 409);
            }

            $items = DB::table('queue_items')->where('queue_id', $queue->id)->get(['id', 'episode_id']);
            $byEpisode = $items->keyBy(fn (object $row): string => (string) $row->episode_id);
            $requested = collect($data['episode_ids'])->map(fn (mixed $id): string => (string) $id);
            if ($requested->count() !== $items->count() || $requested->diff($byEpisode->keys())->isNotEmpty()) {
                return ApiResponse::error('QUEUE_MISMATCH', 'Queue items changed. Refresh and try again.', 409);
            }

            foreach ($items as $offset => $row) {
                DB::table('queue_items')->where('id', $row->id)->update([
                    'position' => 1000 + $offset,
                    'updated_at' => now(),
                ]);
            }
            foreach ($data['episode_ids'] as $position => $episodeId) {
                DB::table('queue_items')
                    ->where('id', $byEpisode[(string) $episodeId]->id)
                    ->update(['position' => $position, 'updated_at' => now()]);
            }
            DB::table('queues')->where('id', $queue->id)->update(['version' => $queue->version + 1, 'updated_at' => now()]);

            return ApiResponse::success(['version' => $queue->version + 1, 'count' => $requested->count()]);
        });
    }

    public function add(Request $request): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:0'], 'episode_id' => ['required', 'exists:episodes,id']]);

        return DB::transaction(function () use ($request, $data): JsonResponse {
            $queue = DB::table('queues')->where('user_id', $request->user()->id)->lockForUpdate()->first();
            if (($queue?->version ?? 0) !== $data['version']) {
                return ApiResponse::error('VERSION_CONFLICT', 'Queue changed on another device.', 409);
            }
            if (! $queue) {
                $queueId = (string) Str::ulid();
                DB::table('queues')->insert(['id' => $queueId, 'user_id' => $request->user()->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $queueId = $queue->id;
                DB::table('queues')->where('id', $queueId)->update(['version' => $queue->version + 1, 'updated_at' => now()]);
            }
            DB::table('queue_items')->insertOrIgnore(['id' => (string) Str::ulid(), 'queue_id' => $queueId, 'episode_id' => $data['episode_id'], 'position' => ((int) DB::table('queue_items')->where('queue_id', $queueId)->max('position')) + 1, 'created_at' => now(), 'updated_at' => now()]);

            return ApiResponse::success(['version' => ($queue?->version ?? 0) + 1], status: 201);
        });
    }

    public function addEpisode(Episode $episode, Request $request): JsonResponse
    {
        $request->merge(['episode_id' => $episode->id]);

        return $this->add($request);
    }

    public function removeEpisode(Episode $episode, Request $request): JsonResponse
    {
        $queueId = DB::table('queues')->where('user_id', $request->user()->id)->value('id');
        $item = $queueId ? DB::table('queue_items')->where('queue_id', $queueId)->where('episode_id', $episode->id)->value('id') : null;
        if (! $item) {
            return ApiResponse::error('NOT_FOUND', 'Episode is not in the queue.', 404);
        }

        return $this->remove((string) $item, $request);
    }

    public function remove(string $item, Request $request): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return DB::transaction(function () use ($item, $request, $data): JsonResponse {
            $queue = DB::table('queues')->where('user_id', $request->user()->id)->lockForUpdate()->first();
            if (! $queue || $queue->version !== $data['version']) {
                return ApiResponse::error('VERSION_CONFLICT', 'Queue changed on another device.', 409);
            }
            $deleted = DB::table('queue_items')->where('id', $item)->where('queue_id', $queue->id)->delete();
            if (! $deleted) {
                return ApiResponse::error('NOT_FOUND', 'Queue item not found.', 404);
            }
            DB::table('queues')->where('id', $queue->id)->update(['version' => $queue->version + 1, 'updated_at' => now()]);
            $items = DB::table('queue_items')->where('queue_id', $queue->id)->orderBy('position')->pluck('id');
            foreach ($items as $position => $id) {
                DB::table('queue_items')->where('id', $id)->update(['position' => $position]);
            }

            return ApiResponse::success(['version' => $queue->version + 1]);
        });
    }

    public function clear(Request $request): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return DB::transaction(function () use ($request, $data): JsonResponse {
            $queue = DB::table('queues')->where('user_id', $request->user()->id)->lockForUpdate()->first();
            if (! $queue || $queue->version !== $data['version']) {
                return ApiResponse::error('VERSION_CONFLICT', 'Queue changed on another device.', 409);
            }
            DB::table('queue_items')->where('queue_id', $queue->id)->delete();
            DB::table('queues')->where('id', $queue->id)->update(['version' => $queue->version + 1, 'updated_at' => now()]);

            return ApiResponse::success(['version' => $queue->version + 1, 'count' => 0]);
        });
    }
}
