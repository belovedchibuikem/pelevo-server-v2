<?php

namespace App\Services;

use App\Integrations\Rss\FeedUrlGuard;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class EpisodeMediaStreamer
{
    public function __construct(private readonly FeedUrlGuard $guard) {}

    public function stream(string $url, ?string $range): StreamedResponse
    {
        $sink = tmpfile();
        if (! is_resource($sink)) {
            throw new RuntimeException('Unable to allocate media stream.');
        }
        for ($redirects = 0; $redirects <= 5; $redirects++) {
            $this->guard->ensureSafe($url);
            ftruncate($sink, 0);
            rewind($sink);
            $upstream = Http::withOptions(['allow_redirects' => false, 'sink' => $sink])->withHeaders(array_filter(['Range' => $range, 'Accept' => 'audio/*']))->connectTimeout(3)->timeout(60)->get($url);
            if (in_array($upstream->status(), [301, 302, 303, 307, 308], true)) {
                $location = $upstream->header('Location');
                if (! $location) {
                    fclose($sink);
                    throw new RuntimeException('Media redirect omitted its destination.');
                }
                $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));

                continue;
            }
            if (! in_array($upstream->status(), [200, 206, 416], true)) {
                fclose($sink);
                throw new RuntimeException('Media upstream returned '.$upstream->status().'.');
            }
            if (fstat($sink)['size'] === 0 && $upstream->body() !== '') {
                fwrite($sink, $upstream->body());
            }
            rewind($sink);
            $headers = ['Accept-Ranges' => 'bytes', 'Content-Type' => $upstream->header('Content-Type') ?: 'audio/mpeg', 'Cache-Control' => 'private, no-store'];
            foreach (['Content-Range', 'ETag', 'Last-Modified'] as $header) {
                if ($value = $upstream->header($header)) {
                    $headers[$header] = $value;
                }
            }
            $length = fstat($sink)['size'];
            if ($upstream->status() !== 416) {
                $headers['Content-Length'] = (string) $length;
            }

            return response()->stream(function () use ($sink): void {
                while (! feof($sink)) {
                    echo fread($sink, 65536);
                    flush();
                }
                fclose($sink);
            }, $upstream->status(), $headers);
        }
        fclose($sink);
        throw new RuntimeException('Media redirect limit exceeded.');
    }
}
