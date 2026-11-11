<?php

namespace App\Actions\Catalog;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Sliding one-minute budgets for Apple, Podcast Index, and feed probes.
 * Callers share one process-wide bucket so a busy search cannot stampede
 * either directory.
 */
final class DirectoryRateGate
{
    public function acquire(string $bucket): bool
    {
        $max = $this->max($bucket);
        $key = 'directory-upstream:'.$bucket;
        if (RateLimiter::tooManyAttempts($key, $max)) {
            return false;
        }

        RateLimiter::hit($key, 60);

        return true;
    }

    public function blocked(): DirectoryRateLimited
    {
        return new DirectoryRateLimited('Podcast directories are busy. Try again in a minute.');
    }

    private function max(string $bucket): int
    {
        $configured = match ($bucket) {
            'apple' => config('services.directory.apple_per_minute', 20),
            'podcast_index' => config('services.directory.podcast_index_per_minute', 10),
            'probe' => config('services.directory.probe_per_minute', 12),
            default => 10,
        };

        return max(1, (int) $configured);
    }
}
