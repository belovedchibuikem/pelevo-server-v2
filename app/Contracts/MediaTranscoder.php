<?php

namespace App\Contracts;

interface MediaTranscoder
{
    /**
     * @return array{video:string,thumbnail:string,safety:array<string,mixed>}
     */
    public function transcode(string $source, string $outputDirectory, ?int $maxDurationMs = null): array;
}
