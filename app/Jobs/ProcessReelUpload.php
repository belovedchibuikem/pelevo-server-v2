<?php

namespace App\Jobs;

use App\Contracts\MediaProbe;
use App\Models\MediaUpload;
use App\Services\MuxMedia;
use App\Support\ReelLimits;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class ProcessReelUpload implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public int $uniqueFor = 120;

    public array $backoff = [5, 15];

    public function __construct(public readonly string $uploadId)
    {
        $this->onQueue('media');
    }

    public function uniqueId(): string
    {
        return $this->uploadId;
    }

    public static function queueForDisk(string $disk): string
    {
        return $disk === 'mux' ? 'default' : 'media';
    }

    public function handle(MediaProbe $probe): void
    {
        $upload = MediaUpload::findOrFail($this->uploadId);
        if (in_array($upload->state, ['processed', 'rejected'], true)) {
            return;
        }
        Log::info('reel.upload.process.start', [
            'id' => $upload->id,
            'disk' => $upload->disk,
            'state' => $upload->state,
        ]);
        $upload->update(['state' => 'processing', 'failure_reason' => null]);
        if ($upload->disk === 'mux') {
            $ready = app(MuxMedia::class)->waitForDirectUpload($upload->path);
            if ($ready === null) {
                Log::warning('reel.upload.process.mux_unready', [
                    'id' => $upload->id,
                    'mux_upload_id' => $upload->path,
                ]);
                throw new RuntimeException('Mux did not finish processing the reel.');
            }
            $originalMs = (int) ($ready['duration_ms'] ?? 0);
            $truncated = $originalMs > ReelLimits::maxDurationMs();
            $ready['original_duration_ms'] = $originalMs;
            $ready['truncated'] = $truncated;
            $ready['duration_ms'] = ReelLimits::cappedDurationMs($originalMs);
            $upload->update([
                'state' => 'processed',
                'probe' => $ready,
                'failure_reason' => null,
                'processed_at' => now(),
            ]);
            Log::info('reel.upload.process.done', [
                'id' => $upload->id,
                'disk' => 'mux',
                'duration_ms' => $ready['duration_ms'] ?? null,
            ]);

            return;
        }
        $disk = Storage::disk($upload->disk);
        if ($upload->checksum_sha256) {
            $checksumStream = $disk->readStream($upload->path);
            if (! is_resource($checksumStream)) {
                throw new RuntimeException('Media could not be read for verification.');
            }
            $hash = hash_init('sha256');
            hash_update_stream($hash, $checksumStream);
            fclose($checksumStream);
            if (! hash_equals($upload->checksum_sha256, hash_final($hash))) {
                $disk->delete($upload->path);
                $upload->update(['state' => 'rejected', 'failure_reason' => 'CHECKSUM_MISMATCH']);

                return;
            }
        }
        $temporaryPath = null;
        if ($upload->disk === 'local') {
            $absolutePath = $disk->path($upload->path);
        } else {
            $temporaryPath = tempnam(sys_get_temp_dir(), 'pelevo-media-');
            $source = $disk->readStream($upload->path);
            $destination = $temporaryPath ? fopen($temporaryPath, 'wb') : false;
            if (! is_resource($source) || ! is_resource($destination)) {
                throw new RuntimeException('Media could not be staged for probing.');
            }
            stream_copy_to_stream($source, $destination);
            fclose($source);
            fclose($destination);
            $absolutePath = $temporaryPath;
        }
        try {
            $result = $probe->inspect($absolutePath);
        } finally {
            if ($temporaryPath && is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
        $originalMs = (int) ($result['duration_ms'] ?? 0);
        $truncated = $originalMs > ReelLimits::maxDurationMs();
        $result['original_duration_ms'] = $originalMs;
        $result['truncated'] = $truncated;
        $result['duration_ms'] = ReelLimits::cappedDurationMs($originalMs);
        $upload->update([
            'state' => 'processed',
            'probe' => $result,
            'failure_reason' => null,
            'processed_at' => now(),
        ]);
        Log::info('reel.upload.process.done', [
            'id' => $upload->id,
            'disk' => $upload->disk,
            'duration_ms' => $result['duration_ms'] ?? null,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('reel.upload.process.failed', [
            'id' => $this->uploadId,
            'message' => $exception?->getMessage(),
        ]);
        MediaUpload::whereKey($this->uploadId)->whereNotIn('state', ['processed', 'rejected'])->update([
            'state' => 'failed',
            'failure_reason' => mb_substr((string) $exception?->getMessage(), 0, 1000),
            'updated_at' => now(),
        ]);
    }
}
