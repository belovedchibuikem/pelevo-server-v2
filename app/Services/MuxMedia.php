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
     * @return array{playback_url: string, thumbnail_url: string, asset_id: string}|null
     */
    public function publishVideo(string $absolutePath): ?array
    {
        if (! $this->enabled() || ! is_file($absolutePath)) {
            return null;
        }
        try {
            $created = $this->client()->timeout(20)->post('https://api.mux.com/video/v1/uploads', [
                'cors_origin' => '*',
                'new_asset_settings' => ['playback_policy' => ['public']],
            ]);
            if (! $created->successful()) {
                return null;
            }
            $uploadUrl = (string) $created->json('data.url');
            $uploadId = (string) $created->json('data.id');
            if ($uploadUrl === '' || $uploadId === '') {
                return null;
            }
            $body = file_get_contents($absolutePath);
            if ($body === false) {
                return null;
            }
            $put = Http::withBody($body, 'video/mp4')->timeout(120)->put($uploadUrl);
            if (! $put->successful() && $put->status() !== 201) {
                return null;
            }
            $assetId = null;
            for ($i = 0; $i < 20; $i++) {
                $upload = $this->client()->timeout(15)->get('https://api.mux.com/video/v1/uploads/'.$uploadId);
                $assetId = $upload->json('data.asset_id');
                $status = $upload->json('data.status');
                if (is_string($assetId) && $assetId !== '') {
                    break;
                }
                if ($status === 'errored' || $status === 'cancelled') {
                    return null;
                }
                sleep(2);
            }
            if (! is_string($assetId) || $assetId === '') {
                return null;
            }
            $playbackId = null;
            for ($i = 0; $i < 30; $i++) {
                $asset = $this->client()->timeout(15)->get('https://api.mux.com/video/v1/assets/'.$assetId);
                $status = $asset->json('data.status');
                $playbackId = $asset->json('data.playback_ids.0.id');
                if ($status === 'ready' && is_string($playbackId) && $playbackId !== '') {
                    break;
                }
                if ($status === 'errored') {
                    return null;
                }
                sleep(2);
            }
            if (! is_string($playbackId) || $playbackId === '') {
                return null;
            }

            return [
                'asset_id' => $assetId,
                'playback_url' => 'https://stream.mux.com/'.$playbackId.'.m3u8',
                'thumbnail_url' => 'https://image.mux.com/'.$playbackId.'/thumbnail.jpg?time=1',
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

    private function client()
    {
        return Http::withBasicAuth(
            (string) config('services.mux.token_id'),
            (string) config('services.mux.token_secret'),
        )->acceptJson();
    }
}
