<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Horizon;
use Throwable;

final class HealthProbe
{
    public function inspect(): array
    {
        $heartbeat = DB::table('scheduler_heartbeats')->latest('ran_at')->first();
        $heartbeatAt = $heartbeat?->ran_at;
        $heartbeatAge = $heartbeatAt ? now()->diffInSeconds($heartbeatAt) : null;
        $schedulerHealthy = $heartbeatAge !== null && $heartbeatAge <= config('operations.scheduler_max_age_seconds');

        $cacheHealthy = false;
        try {
            $key = 'operations:probe:'.bin2hex(random_bytes(6));
            Cache::put($key, true, 10);
            $cacheHealthy = Cache::pull($key) === true;
        } catch (Throwable) {
            // Reported below without leaking infrastructure details.
        }

        $queueDepths = [];
        $queueHealthy = true;
        try {
            $connection = Queue::connection();
            foreach (config('operations.queues') as $queue) {
                $queueDepths[$queue] = $connection->size($queue);
            }
        } catch (Throwable) {
            $queueHealthy = false;
        }

        $horizonStatus = 'unavailable';
        try {
            $horizonStatus = Horizon::status();
        } catch (Throwable) {
            // Horizon may not be running in local or test environments.
        }

        return [
            'healthy' => $schedulerHealthy && $cacheHealthy && $queueHealthy && $horizonStatus === 'running',
            'scheduler' => [
                'healthy' => $schedulerHealthy,
                'last_heartbeat_at' => $heartbeatAt,
                'age_seconds' => $heartbeatAge,
                'maximum_age_seconds' => config('operations.scheduler_max_age_seconds'),
                'host' => $heartbeat?->host,
            ],
            'cache' => ['healthy' => $cacheHealthy],
            'queues' => [
                'healthy' => $queueHealthy,
                'connection' => config('queue.default'),
                'depths' => $queueDepths,
                'failed' => DB::table('failed_jobs')->count(),
            ],
            'horizon' => ['status' => $horizonStatus],
            'checked_at' => now()->toIso8601String(),
        ];
    }
}
