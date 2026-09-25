<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ReelPlayback;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class ReelMediaController extends Controller
{
    public function video(string $reel): Response
    {
        return $this->stream($reel, 'video');
    }

    public function thumbnail(string $reel): Response
    {
        return $this->stream($reel, 'thumbnail');
    }

    private function stream(string $reel, string $kind): Response
    {
        $row = DB::table('reels')->where('id', $reel)->first();
        if (! $row) {
            abort(404);
        }
        if ($kind === 'video' && is_string($row->media_url) && str_starts_with($row->media_url, 'http')) {
            return redirect()->away($row->media_url);
        }
        $media = DB::table('reel_media')->join('media_uploads', 'media_uploads.id', '=', 'reel_media.media_upload_id')->where('reel_media.reel_id', $reel)->orderByDesc('reel_media.created_at')->select('reel_media.transcoded_path', 'reel_media.thumbnail_path', 'media_uploads.disk')->first();
        if (! $media) {
            abort(404);
        }
        $relative = $kind === 'video' ? $media->transcoded_path : $media->thumbnail_path;
        if (is_string($relative) && str_starts_with($relative, 'http')) {
            return redirect()->away($relative);
        }
        if (is_string($relative) && $relative !== '') {
            $disks = [];
            foreach ([
                is_string($media->disk) && $media->disk !== '' && $media->disk !== 'mux' ? $media->disk : null,
                (string) config('media.upload_disk', 'local'),
                'local',
            ] as $candidate) {
                if (is_string($candidate) && $candidate !== '' && ! in_array($candidate, $disks, true)) {
                    $disks[] = $candidate;
                }
            }
            foreach ($disks as $diskName) {
                try {
                    $disk = Storage::disk($diskName);
                    if (! $disk->exists($relative)) {
                        continue;
                    }
                    if ($diskName !== 'local') {
                        return redirect()->away($disk->temporaryUrl($relative, now()->addHours(6)));
                    }

                    return $disk->response($relative, null, [
                        'Content-Type' => $kind === 'video' ? 'video/mp4' : 'image/jpeg',
                        'Cache-Control' => 'private, max-age=3600',
                    ]);
                } catch (\Throwable) {
                    continue;
                }
            }
        }
        if ($kind === 'thumbnail') {
            $mux = ReelPlayback::muxThumbnailFromPlayback(is_string($row->media_url ?? null) ? $row->media_url : null);
            if ($mux !== null) {
                return redirect()->away($mux);
            }
        }
        abort(404);
    }
}
