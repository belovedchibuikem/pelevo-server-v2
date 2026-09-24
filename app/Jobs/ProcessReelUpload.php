<?php

namespace App\Jobs;

use App\Contracts\MediaProbe;
use App\Models\MediaUpload;
use App\Support\ReelLimits;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class ProcessReelUpload implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public array $backoff = [30, 120];

    public function __construct(public readonly string $uploadId)
    {
        $this->onQueue('media');
    }

    public function uniqueId(): string
    {
        return $this->uploadId;
    }

    public function handle(MediaProbe $probe): void
    {
        $upload = MediaUpload::findOrFail($this->uploadId);
        if (in_array($upload->state, ['processed', 'rejected'], true)) {
            return;
        }
        $upload->update(['state' => 'processing', 'failure_reason' => null]);
        $disk = Storage::disk($upload->disk);
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
    }

    public function failed(?Throwable $exception): void
    {
        MediaUpload::whereKey($this->uploadId)->whereNotIn('state', ['processed', 'rejected'])->update([
            'state' => 'failed',
            'failure_reason' => mb_substr((string) $exception?->getMessage(), 0, 1000),
            'updated_at' => now(),
        ]);
    }
}
