<?php

namespace App\Services;

use App\Contracts\MediaTranscoder;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

final class FfmpegMediaTranscoder implements MediaTranscoder
{
    public function transcode(string $source, string $outputDirectory, ?int $maxDurationMs = null): array
    {
        File::ensureDirectoryExists($outputDirectory);
        $video = $outputDirectory.'/video.mp4';
        $thumbnail = $outputDirectory.'/thumbnail.jpg';
        $command = [config('media.ffmpeg_binary'), '-y', '-i', $source];
        if ($maxDurationMs !== null && $maxDurationMs > 0) {
            $command[] = '-t';
            $command[] = number_format($maxDurationMs / 1000, 3, '.', '');
        }
        $command = array_merge($command, [
            '-vf', 'scale=1080:1920:force_original_aspect_ratio=decrease,pad=1080:1920:(ow-iw)/2:(oh-ih)/2',
            '-c:v', 'libx264',
            '-preset', 'medium',
            '-crf', '23',
            '-c:a', 'aac',
            '-movflags', '+faststart',
            $video,
        ]);
        (new Process($command))->setTimeout(300)->mustRun();
        (new Process([config('media.ffmpeg_binary'), '-y', '-ss', '00:00:01', '-i', $video, '-frames:v', '1', '-vf', 'scale=540:-2', $thumbnail]))->setTimeout(60)->mustRun();

        return ['video' => $video, 'thumbnail' => $thumbnail, 'safety' => ['malware_scan' => 'pending_external', 'blank_frame_scan' => 'not_run', 'content_safety' => 'pending_review']];
    }
}
