<?php

namespace App\Services;

use App\Contracts\MediaProbe;
use RuntimeException;
use Symfony\Component\Process\Process;

final class FfprobeMediaProbe implements MediaProbe
{
    public function inspect(string $absolutePath): array
    {
        $process = new Process([
            config('media.ffprobe_binary'), '-v', 'error', '-select_streams', 'v:0',
            '-show_entries', 'format=duration,format_name:stream=codec_type,width,height',
            '-of', 'json', $absolutePath,
        ]);
        $process->setTimeout(30);
        $process->mustRun();
        $payload = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $stream = collect($payload['streams'] ?? [])->firstWhere('codec_type', 'video');
        $duration = (float) ($payload['format']['duration'] ?? 0);
        if (! is_array($stream) || $duration <= 0) {
            throw new RuntimeException('The uploaded file does not contain a valid video stream.');
        }

        return [
            'duration_ms' => (int) ceil($duration * 1000),
            'mime' => 'video/'.strtolower(strtok((string) ($payload['format']['format_name'] ?? 'unknown'), ',')),
            'width' => isset($stream['width']) ? (int) $stream['width'] : null,
            'height' => isset($stream['height']) ? (int) $stream['height'] : null,
        ];
    }
}
