<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

final class MuxMedia
{
    public function enabled(): bool
    {
        return config('services.mux.token_id') !== null
            && config('services.mux.token_id') !== ''
            && config('services.mux.token_secret') !== null
            && config('services.mux.token_secret') !== '';
    }

    /**
     * @return array{upload_id: string, url: string, headers: array<string, string>}|null
     */
    public function createDirectUpload(string $passthrough): ?array
    {
        if (! $this->enabled()) {
            return null;
        }
        try {
            $created = $this->client()->timeout(20)->post('https://api.mux.com/video/v1/uploads', [
                'cors_origin' => '*',
                'timeout' => 3600,
                'new_asset_settings' => [
                    'playback_policy' => ['public'],
                    'passthrough' => $passthrough,
                ],
            ]);
            if (! $created->successful()) {
                return null;
            }
            $uploadUrl = (string) $created->json('data.url');
            $uploadId = (string) $created->json('data.id');
            if ($uploadUrl === '' || $uploadId === '') {
                return null;
            }

            return [
                'upload_id' => $uploadId,
                'url' => $uploadUrl,
                'headers' => ['Content-Type' => 'application/octet-stream'],
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{duration_ms: int, mime: string, width: int, height: int, playback_url: string, thumbnail_url: string, mux_asset_id: string, mux_playback_id: string}|null
     */
    public function waitForDirectUpload(string $uploadId): ?array
    {
        if (! $this->enabled() || $uploadId === '') {
            return null;
        }
        try {
            $assetId = null;
            for ($i = 0; $i < 30; $i++) {
                $upload = $this->client()->timeout(15)->get('https://api.mux.com/video/v1/uploads/'.$uploadId);
                $status = (string) $upload->json('data.status');
                $assetId = $upload->json('data.asset_id');
                if ($status === 'errored' || $status === 'cancelled' || $status === 'timed_out') {
                    return null;
                }
                if (is_string($assetId) && $assetId !== '') {
                    break;
                }
                $this->pause();
            }
            if (! is_string($assetId) || $assetId === '') {
                return null;
            }

            return $this->waitForReadyAsset($assetId);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{playback_url: string, thumbnail_url: string, asset_id: string}|null
     */
    public function publishVideo(string $absolutePath): ?array
    {
        if (! $this->enabled() || ! is_file($absolutePath)) {
            return null;
        }
        try {
            $created = $this->createDirectUpload('server-publish');
            if ($created === null) {
                return null;
            }
            $stream = fopen($absolutePath, 'rb');
            if (! is_resource($stream)) {
                return null;
            }
            try {
                $put = Http::withBody($stream, 'application/octet-stream')->timeout(120)->put($created['url']);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            if (! $put->successful() && $put->status() !== 201) {
                return null;
            }
            $ready = $this->waitForDirectUpload($created['upload_id']);
            if ($ready === null) {
                return null;
            }

            return [
                'asset_id' => $ready['mux_asset_id'],
                'playback_url' => $ready['playback_url'],
                'thumbnail_url' => $ready['thumbnail_url'],
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{live_stream_id: string, stream_key: string, ingest_url: string, playback_url: string}|null
     */
    public function createLiveStream(string $title): ?array
    {
        if (! $this->enabled()) {
            return null;
        }
        try {
            $response = $this->client()->timeout(20)->post('https://api.mux.com/video/v1/live-streams', [
                'playback_policy' => ['public'],
                'new_asset_settings' => ['playback_policy' => ['public']],
                'reconnect_window' => 60,
                'passthrough' => $title,
            ]);
            if (! $response->successful()) {
                return null;
            }
            $id = (string) $response->json('data.id');
            $key = (string) $response->json('data.stream_key');
            $playbackId = (string) $response->json('data.playback_ids.0.id');
            if ($id === '' || $key === '' || $playbackId === '') {
                return null;
            }

            return [
                'live_stream_id' => $id,
                'stream_key' => $key,
                'ingest_url' => 'rtmps://global-live.mux.com:443/app',
                'playback_url' => 'https://stream.mux.com/'.$playbackId.'.m3u8',
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{duration_ms: int, mime: string, width: int, height: int, playback_url: string, thumbnail_url: string, mux_asset_id: string, mux_playback_id: string}|null
     */
    private function waitForReadyAsset(string $assetId): ?array
    {
        for ($i = 0; $i < 40; $i++) {
            $asset = $this->client()->timeout(15)->get('https://api.mux.com/video/v1/assets/'.$assetId);
            $data = $asset->json('data');
            if (! is_array($data)) {
                $this->pause();
                continue;
            }
            $status = (string) ($data['status'] ?? '');
            if ($status === 'errored') {
                return null;
            }
            $playbackId = $this->playbackId($data);
            if (is_string($playbackId) && $playbackId !== '') {
                $duration = (float) ($data['duration'] ?? 0);
                [$width, $height] = $this->videoSize($data);

                return [
                    'duration_ms' => max(1, (int) round($duration * 1000)),
                    'mime' => 'video/mp4',
                    'width' => $width,
                    'height' => $height,
                    'mux_asset_id' => $assetId,
                    'mux_playback_id' => $playbackId,
                    'playback_url' => 'https://stream.mux.com/'.$playbackId.'.m3u8',
                    'thumbnail_url' => 'https://image.mux.com/'.$playbackId.'/thumbnail.jpg?time=1',
                ];
            }
            $this->pause();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function playbackId(array $data): ?string
    {
        $ids = $data['playback_ids'] ?? null;
        if (! is_array($ids) || $ids === []) {
            return null;
        }
        $first = $ids[0] ?? null;
        if (is_array($first) && is_string($first['id'] ?? null) && $first['id'] !== '') {
            return $first['id'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: int, 1: int}
     */
    private function videoSize(array $data): array
    {
        $width = 1080;
        $height = 1920;
        $tracks = $data['tracks'] ?? null;
        if (! is_array($tracks)) {
            return [$width, $height];
        }
        foreach ($tracks as $track) {
            if (! is_array($track) || ($track['type'] ?? null) !== 'video') {
                continue;
            }
            $width = max(1, (int) ($track['max_width'] ?? $width));
            $height = max(1, (int) ($track['max_height'] ?? $height));
        }

        return [$width, $height];
    }

    private function pause(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        usleep(400000);
    }

    private function client()
    {
        return Http::withBasicAuth(
            (string) config('services.mux.token_id'),
            (string) config('services.mux.token_secret'),
        )->acceptJson();
    }
}
