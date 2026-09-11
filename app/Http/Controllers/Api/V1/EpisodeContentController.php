<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\Rss\UnsafeFeedUrlException;
use App\Models\Device;
use App\Models\Episode;
use App\Services\EpisodeMediaStreamer;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class EpisodeContentController extends Controller
{
    public function chapters(Episode $episode): JsonResponse
    {
        $chapters = DB::table('episode_chapters')->where('episode_id', $episode->id)->orderBy('starts_at_seconds')->orderBy('id')->get(['id', 'title', 'starts_at_seconds']);

        return ApiResponse::success($chapters->map(fn (object $row): array => [
            'id' => $row->id,
            'title' => $row->title,
            'starts_at_seconds' => (int) $row->starts_at_seconds,
        ])->values());
    }

    public function transcript(Episode $episode): JsonResponse
    {
        $transcript = DB::table('episode_transcripts')->where('episode_id', $episode->id)->where('state', 'available')->latest()->first();

        return $transcript ? ApiResponse::success([
            'language' => $transcript->language,
            'format' => $transcript->format,
            'content' => $transcript->content,
        ]) : ApiResponse::error('NOT_FOUND', 'Transcript not available.', 404);
    }

    public function stream(Episode $episode, Request $request, EpisodeMediaStreamer $streamer): StreamedResponse|Response|JsonResponse
    {
        abort_unless($episode->availability === 'available', 404);
        $range = $request->header('Range');
        if ($range !== null && ! preg_match('/^bytes=\d+-\d*$/', $range)) {
            return response('', 416, ['Content-Range' => 'bytes */*', 'Accept-Ranges' => 'bytes']);
        }
        try {
            return $streamer->stream($episode->audio_url, $range);
        } catch (UnsafeFeedUrlException) {
            return ApiResponse::error('MEDIA_SOURCE_UNSAFE', 'The episode media source is not publicly routable.', 422);
        } catch (RuntimeException) {
            return ApiResponse::error('MEDIA_UPSTREAM_UNAVAILABLE', 'The episode media source is unavailable.', 502);
        }
    }

    public function authorizeDownload(Episode $episode, Request $request): JsonResponse
    {
        abort_unless($episode->availability === 'available', 404);
        $device = Device::where('user_id', $request->user()->id)->where('device_identifier', $request->header('X-Device-Id'))->whereNull('revoked_at')->first();
        if (! $device) {
            return ApiResponse::error('FORBIDDEN', 'A registered active device is required.', 403);
        }
        $expires = now()->addMinutes(15);
        $id = (string) Str::ulid();
        DB::table('downloads')->updateOrInsert(['user_id' => $request->user()->id, 'episode_id' => $episode->id, 'device_id' => $device->id], ['id' => $id, 'authorized_until' => $expires, 'created_at' => now(), 'updated_at' => now()]);
        $signature = hash_hmac('sha256', $request->user()->id.'|'.$device->id.'|'.$episode->id.'|'.$expires->timestamp, (string) config('app.key'));

        return ApiResponse::success(['episode_id' => $episode->id, 'url' => $episode->audio_url, 'expires_at' => $expires->toIso8601String(), 'authorization' => $signature]);
    }
}
