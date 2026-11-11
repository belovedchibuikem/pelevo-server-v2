<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Catalog\DeadFeedException;
use App\Actions\Catalog\DirectoryRateLimited;
use App\Actions\Catalog\SearchPodcastDirectory;
use App\Actions\Catalog\SubscribePodcastFeed;
use App\Http\Controllers\Controller;
use App\Integrations\Rss\UnsafeFeedUrlException;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DirectoryController extends Controller
{
    public function search(Request $request, SearchPodcastDirectory $directory): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        try {
            $result = $directory->handle($data['q']);
        } catch (DirectoryRateLimited $exception) {
            return ApiResponse::error('RATE_LIMITED', $exception->getMessage(), 429);
        }

        return ApiResponse::success($result);
    }

    public function subscribe(Request $request, SubscribePodcastFeed $subscribe): JsonResponse
    {
        $data = $request->validate([
            'candidate_id' => ['nullable', 'string', 'max:120'],
            'source' => ['required', 'string', 'in:apple,podcast_index,rss'],
            'external_id' => ['nullable', 'string', 'max:80'],
            'feed_url' => ['required', 'string', 'max:2000'],
            'title' => ['required', 'string', 'max:300'],
            'author' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $result = $subscribe->handle($data);
        } catch (DirectoryRateLimited $exception) {
            return ApiResponse::error('RATE_LIMITED', $exception->getMessage(), 429);
        } catch (DeadFeedException|UnsafeFeedUrlException $exception) {
            return ApiResponse::error('FEED_UNAVAILABLE', $exception->getMessage(), 422);
        }

        return ApiResponse::success([
            'show_id' => $result['show']->id,
            'already_in_catalog' => $result['already_in_catalog'],
        ]);
    }
}
