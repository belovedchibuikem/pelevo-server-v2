<?php

namespace App\Jobs;

use App\Contracts\MediaTranscoder;
use App\Services\InAppNotificationDelivery;
use App\Services\MuxMedia;
use App\Support\ReelLimits;
use App\Support\ReelPlayback;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class TranscodeReelMedia implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 360;

    public array $backoff = [60, 300];

    public function __construct(public readonly string $reelId)
    {
        $this->onQueue('media');
    }

    public function uniqueId(): string
    {
        return $this->reelId;
    }

    public function handle(MediaTranscoder $transcoder): void
    {
        $media = DB::table('reel_media')->join('media_uploads', 'media_uploads.id', '=', 'reel_media.media_upload_id')->where('reel_media.reel_id', $this->reelId)->select('reel_media.*', 'media_uploads.disk', 'media_uploads.path', 'media_uploads.probe', 'media_uploads.user_id')->first();
        if (! $media || $media->processing_state === 'ready') {
            return;
        }
        if ($media->disk === 'mux') {
            $this->publishFromMux($media);

            return;
        }
        $disk = Storage::disk($media->disk);
        $temporaryDirectory = null;
        if ($media->disk === 'local') {
            $inputPath = $disk->path($media->path);
            $outputDirectory = Storage::disk('local')->path('reels/'.$this->reelId);
        } else {
            $temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pelevo-transcode-'.$this->reelId.'-'.bin2hex(random_bytes(4));
            $outputDirectory = $temporaryDirectory.DIRECTORY_SEPARATOR.'output';
            if (! mkdir($outputDirectory, 0700, true) && ! is_dir($outputDirectory)) {
                throw new RuntimeException('Unable to create the media staging directory.');
            }
            $inputPath = $temporaryDirectory.DIRECTORY_SEPARATOR.'source';
            $this->copyStreamToPath($disk->readStream($media->path), $inputPath);
        }
        try {
            $result = $transcoder->transcode($inputPath, $outputDirectory, ReelLimits::maxDurationMs());
            if ($media->disk !== 'local') {
                foreach (['video.mp4', 'thumbnail.jpg'] as $filename) {
                    $stream = fopen($outputDirectory.DIRECTORY_SEPARATOR.$filename, 'rb');
                    if (! is_resource($stream)) {
                        throw new RuntimeException('Unable to store transcoded media.');
                    }
                    try {
                        if (! $disk->writeStream('reels/'.$this->reelId.'/'.$filename, $stream)) {
                            throw new RuntimeException('Unable to store transcoded media.');
                        }
                    } finally {
                        fclose($stream);
                    }
                }
            }
            $videoAbsolute = $media->disk === 'local'
                ? Storage::disk('local')->path('reels/'.$this->reelId.'/video.mp4')
                : $outputDirectory.DIRECTORY_SEPARATOR.'video.mp4';
            $published = app(MuxMedia::class)->publishVideo($videoAbsolute) ?? [];
        } finally {
            if ($temporaryDirectory !== null) {
                $this->removeDirectory($temporaryDirectory);
            }
        }
        $probe = is_array($media->probe) ? $media->probe : (json_decode((string) $media->probe, true) ?: []);
        $originalMs = (int) ($probe['original_duration_ms'] ?? $probe['duration_ms'] ?? $media->duration_ms ?? 0);
        $truncated = ($probe['truncated'] ?? false) === true || $originalMs > ReelLimits::maxDurationMs();
        $durationMs = ReelLimits::cappedDurationMs($originalMs > 0 ? $originalMs : (int) ($media->duration_ms ?? 0));
        DB::transaction(function () use ($media, $result, $published, $durationMs, $truncated, $originalMs): void {
            $thumbnailPath = $this->resolveThumbnail(
                is_string($media->thumbnail_path ?? null) ? $media->thumbnail_path : null,
                is_string($published['thumbnail_url'] ?? null) ? $published['thumbnail_url'] : null,
                is_string($published['playback_url'] ?? null) ? $published['playback_url'] : null,
            ) ?? 'reels/'.$this->reelId.'/thumbnail.jpg';
            DB::table('reel_media')->where('id', $media->id)->update(['processing_state' => 'ready', 'transcoded_path' => 'reels/'.$this->reelId.'/video.mp4', 'thumbnail_path' => $thumbnailPath, 'duration_ms' => $durationMs, 'safety_results' => json_encode($result['safety'], JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            DB::table('reels')->where('id', $this->reelId)->where('state', 'processing')->update([
                'state' => 'published',
                'published_at' => now(),
                'duration_ms' => $durationMs,
                'media_url' => $published['playback_url'] ?? null,
                'updated_at' => now(),
            ]);
            $this->event('published', [...$result['safety'], 'mux_asset_id' => $published['asset_id'] ?? null, 'truncated' => $truncated, 'original_duration_ms' => $originalMs]);
        });
        if ($truncated) {
            $this->notifyTruncated($media->user_id === null ? null : (string) $media->user_id, $originalMs, $durationMs);
        }
    }

    private function publishFromMux(object $media): void
    {
        $probe = is_array($media->probe) ? $media->probe : (json_decode((string) $media->probe, true) ?: []);
        $originalMs = (int) ($probe['original_duration_ms'] ?? $probe['duration_ms'] ?? $media->duration_ms ?? 0);
        $truncated = ($probe['truncated'] ?? false) === true || $originalMs > ReelLimits::maxDurationMs();
        $durationMs = ReelLimits::cappedDurationMs($originalMs > 0 ? $originalMs : (int) ($media->duration_ms ?? 0));
        $playback = is_string($probe['playback_url'] ?? null) ? $probe['playback_url'] : null;
        $thumbnail = is_string($probe['thumbnail_url'] ?? null) ? $probe['thumbnail_url'] : null;
        $assetId = is_string($probe['mux_asset_id'] ?? null) ? $probe['mux_asset_id'] : null;
        DB::transaction(function () use ($media, $durationMs, $truncated, $originalMs, $playback, $thumbnail, $assetId): void {
            DB::table('reel_media')->where('id', $media->id)->update([
                'processing_state' => 'ready',
                'transcoded_path' => $assetId !== null ? 'mux/'.$assetId : null,
                'thumbnail_path' => $this->resolveThumbnail(
                    is_string($media->thumbnail_path ?? null) ? $media->thumbnail_path : null,
                    $thumbnail,
                    $playback,
                ),
                'duration_ms' => $durationMs,
                'safety_results' => json_encode(['mux' => 'direct'], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            DB::table('reels')->where('id', $this->reelId)->where('state', 'processing')->update([
                'state' => 'published',
                'published_at' => now(),
                'duration_ms' => $durationMs,
                'media_url' => $playback,
                'updated_at' => now(),
            ]);
            $this->event('published', ['mux_asset_id' => $assetId, 'truncated' => $truncated, 'original_duration_ms' => $originalMs, 'direct_upload' => true]);
        });
        if ($truncated) {
            $this->notifyTruncated($media->user_id === null ? null : (string) $media->user_id, $originalMs, $durationMs);
        }
    }

    private function resolveThumbnail(?string $existing, ?string $generated, ?string $playback): ?string
    {
        if (ReelPlayback::keepUploadedCover($existing)) {
            return $existing;
        }
        if (is_string($generated) && $generated !== '') {
            return $generated;
        }

        return ReelPlayback::muxThumbnailFromPlayback($playback);
    }

    private function notifyTruncated(?string $userId, int $originalMs, int $durationMs): void
    {
        if ($userId === null || $userId === '') {
            return;
        }
        app(InAppNotificationDelivery::class)->deliver($userId, [
            'type' => 'reel',
            'key' => 'reel-truncated:'.$this->reelId,
            'title' => 'Reel trimmed to '.max(1, (int) ceil(ReelLimits::maxDurationMs() / 60000)).' minutes',
            'body' => ReelLimits::truncatedMessage(),
            'data' => [
                'reel_id' => $this->reelId,
                'truncated' => true,
                'original_duration_ms' => $originalMs,
                'duration_ms' => $durationMs,
            ],
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('reel_media')->where('reel_id', $this->reelId)->update(['processing_state' => 'failed', 'updated_at' => now()]);
        DB::table('reels')->where('id', $this->reelId)->where('state', 'processing')->update(['state' => 'failed', 'updated_at' => now()]);
        $this->event('failed', ['reason' => mb_substr((string) $exception?->getMessage(), 0, 500)]);
    }

    private function event(string $state, array $details): void
    {
        DB::table('reel_processing_events')->insert(['id' => (string) Str::ulid(), 'reel_id' => $this->reelId, 'state' => $state, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    private function copyStreamToPath(mixed $source, string $path): void
    {
        $destination = fopen($path, 'wb');
        if (! is_resource($source) || ! is_resource($destination)) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($destination)) {
                fclose($destination);
            }
            throw new RuntimeException('Media could not be staged for transcoding.');
        }
        stream_copy_to_stream($source, $destination);
        fclose($source);
        fclose($destination);
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
