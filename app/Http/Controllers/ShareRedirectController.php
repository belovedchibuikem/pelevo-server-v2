<?php

namespace App\Http\Controllers;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Resolves public share short links (https://pelevo.com/s/{token}) for browsers
 * and App Links / Universal Links clients.
 */
final class ShareRedirectController extends Controller
{
    public function show(string $token, Request $request): JsonResponse|Response
    {
        $resolved = $this->resolveReadyCard($token);
        if ($resolved === null) {
            if ($request->expectsJson()) {
                return ApiResponse::error('NOT_FOUND', 'This share link is invalid or no longer available.', 404);
            }

            return response()->view('share.missing', [], 404);
        }

        $this->recordOpen($resolved['card_id'], $request);

        if ($request->expectsJson() || $request->query('format') === 'json') {
            return ApiResponse::success($resolved);
        }

        return response()->view('share.open', [
            'resolved' => $resolved,
            'appSchemeUrl' => $resolved['app_scheme_url'],
            'httpsUrl' => $resolved['https_url'],
            'webPath' => $resolved['web_path'],
            'packageId' => 'com.Podemeraldltd.pelevo',
        ]);
    }

    public function resolve(string $token, Request $request): JsonResponse
    {
        $resolved = $this->resolveReadyCard($token);
        if ($resolved === null) {
            return ApiResponse::error('NOT_FOUND', 'This share link is invalid or no longer available.', 404);
        }
        $this->recordOpen($resolved['card_id'], $request);

        return ApiResponse::success($resolved);
    }

    public function episode(string $episode, Request $request): JsonResponse|Response
    {
        $row = DB::table('episodes')->where('id', $episode)->first(['id', 'show_id', 'title']);
        if (! $row) {
            return $this->missing($request);
        }

        return $this->presentSubject($request, [
            'card_id' => null,
            'subject_type' => 'episode',
            'subject_id' => (string) $row->id,
            'show_id' => (string) $row->show_id,
            'title' => (string) $row->title,
            'web_path' => '/episodes/'.$row->id,
            'https_url' => rtrim((string) config('app.url'), '/').'/episodes/'.$row->id,
            'app_scheme_url' => 'pelevo://episode/'.$row->id,
        ]);
    }

    public function showSubject(string $show, Request $request): JsonResponse|Response
    {
        $row = DB::table('shows')->where('id', $show)->first(['id', 'title']);
        if (! $row) {
            return $this->missing($request);
        }

        return $this->presentSubject($request, [
            'card_id' => null,
            'subject_type' => 'show',
            'subject_id' => (string) $row->id,
            'show_id' => (string) $row->id,
            'title' => (string) $row->title,
            'web_path' => '/shows/'.$row->id,
            'https_url' => rtrim((string) config('app.url'), '/').'/shows/'.$row->id,
            'app_scheme_url' => 'pelevo://show/'.$row->id,
        ]);
    }

    public function creator(string $creator, Request $request): JsonResponse|Response
    {
        $creator = trim(rawurldecode($creator));
        if ($creator === '' || strlen($creator) > 40 || ! preg_match('/^[A-Za-z0-9_-]+$/', $creator)) {
            return $this->missing($request);
        }
        $row = DB::table('creator_profiles')
            ->leftJoin('users', 'users.id', '=', 'creator_profiles.user_id')
            ->where('creator_profiles.status', 'active')
            ->where(function ($query) use ($creator): void {
                $query->where('creator_profiles.id', $creator)->orWhere('users.handle', $creator);
            })
            ->first([
                'creator_profiles.id',
                'creator_profiles.display_name',
                'users.name',
            ]);
        if (! $row) {
            return $this->missing($request);
        }
        $name = is_string($row->name) && $row->name !== '' ? $row->name : (string) $row->display_name;
        $id = (string) $row->id;
        $origin = rtrim((string) config('app.url'), '/');

        return $this->presentSubject($request, [
            'card_id' => null,
            'subject_type' => 'creator',
            'subject_id' => $id,
            'show_id' => null,
            'title' => $name,
            'web_path' => '/creators/'.$id,
            'https_url' => $origin.'/creators/'.rawurlencode($id),
            'app_scheme_url' => 'pelevo://creator/'.rawurlencode($id),
        ]);
    }

    public function playlist(string $playlist, Request $request): JsonResponse|Response
    {
        $row = DB::table('playlists')
            ->where('id', $playlist)
            ->where('is_public', true)
            ->first(['id', 'name']);
        if (! $row) {
            return $this->missing($request);
        }

        return $this->presentLibrarySubject($request, 'playlist', (string) $row->id, (string) $row->name);
    }

    public function collection(string $collection, Request $request): JsonResponse|Response
    {
        $row = DB::table('collections')
            ->where('id', $collection)
            ->where('is_public', true)
            ->first(['id', 'name']);
        if (! $row) {
            return $this->missing($request);
        }

        return $this->presentLibrarySubject($request, 'collection', (string) $row->id, (string) $row->name);
    }

    public function reel(string $reel, Request $request): JsonResponse|Response
    {
        $exists = DB::table('reels')->where('id', $reel)->exists();
        if (! $exists) {
            return $this->missing($request);
        }

        return $this->presentSubject($request, [
            'card_id' => null,
            'subject_type' => 'reel',
            'subject_id' => $reel,
            'show_id' => null,
            'title' => 'Pelevo Reel',
            'web_path' => '/reels/'.$reel,
            'https_url' => rtrim((string) config('app.url'), '/').'/reels/'.$reel,
            'app_scheme_url' => 'pelevo://reel/'.$reel,
        ]);
    }

    /**
     * @param  array{
     *   card_id: ?string,
     *   subject_type: string,
     *   subject_id: string,
     *   show_id: ?string,
     *   title: ?string,
     *   web_path: string,
     *   https_url: string,
     *   app_scheme_url: string
     * }  $resolved
     */
    private function presentLibrarySubject(Request $request, string $type, string $id, string $title): JsonResponse|Response
    {
        $origin = rtrim((string) config('app.url'), '/');
        $webPath = '/'.$type.'s/'.$id;

        return $this->presentSubject($request, [
            'card_id' => null,
            'subject_type' => $type,
            'subject_id' => $id,
            'show_id' => null,
            'title' => $title,
            'web_path' => $webPath,
            'https_url' => $origin.$webPath,
            'app_scheme_url' => 'pelevo://'.$type.'/'.$id,
        ]);
    }

    private function presentSubject(Request $request, array $resolved): JsonResponse|Response
    {
        if ($request->expectsJson() || $request->query('format') === 'json') {
            return ApiResponse::success($resolved);
        }

        return response()->view('share.open', [
            'resolved' => $resolved,
            'appSchemeUrl' => $resolved['app_scheme_url'],
            'httpsUrl' => $resolved['https_url'],
            'webPath' => $resolved['web_path'],
            'packageId' => 'com.Podemeraldltd.pelevo',
        ]);
    }

    private function missing(Request $request): JsonResponse|Response
    {
        if ($request->expectsJson()) {
            return ApiResponse::error('NOT_FOUND', 'This share link is invalid or no longer available.', 404);
        }

        return response()->view('share.missing', [], 404);
    }

    /**
     * @return array{
     *   card_id: string,
     *   subject_type: string,
     *   subject_id: string,
     *   show_id: ?string,
     *   title: ?string,
     *   web_path: string,
     *   https_url: string,
     *   app_scheme_url: string
     * }|null
     */
    private function resolveReadyCard(string $token): ?array
    {
        if (! preg_match('/^[A-Za-z0-9]{16,64}$/', $token)) {
            return null;
        }

        $row = DB::table('share_cards')
            ->where('public_token', $token)
            ->where('state', 'ready')
            ->first();
        if (! $row) {
            return null;
        }

        $showId = null;
        $title = null;
        if ($row->subject_type === 'episode') {
            $episode = DB::table('episodes')->where('id', $row->subject_id)->first(['show_id', 'title']);
            $showId = $episode?->show_id;
            $title = $episode?->title;
        } elseif ($row->subject_type === 'show') {
            $title = DB::table('shows')->where('id', $row->subject_id)->value('title');
        }

        $webPath = match ($row->subject_type) {
            'episode' => '/episodes/'.$row->subject_id,
            'show' => '/shows/'.$row->subject_id,
            'reel' => '/reels/'.$row->subject_id,
            default => null,
        };
        if ($webPath === null) {
            return null;
        }

        $origin = rtrim((string) config('app.url'), '/');
        $schemeHost = match ($row->subject_type) {
            'episode' => 'episode/'.$row->subject_id,
            'show' => 'show/'.$row->subject_id,
            'reel' => 'reel/'.$row->subject_id,
            default => '',
        };

        return [
            'card_id' => (string) $row->id,
            'subject_type' => (string) $row->subject_type,
            'subject_id' => (string) $row->subject_id,
            'show_id' => $showId !== null ? (string) $showId : null,
            'title' => is_string($title) ? $title : null,
            'web_path' => $webPath,
            'https_url' => $origin.$webPath,
            'app_scheme_url' => 'pelevo://'.$schemeHost,
        ];
    }

    private function recordOpen(string $cardId, Request $request): void
    {
        $channel = $request->expectsJson() ? 'api' : 'link';
        try {
            DB::table('share_card_events')->insert([
                'id' => (string) Str::ulid(),
                'share_card_id' => $cardId,
                'event' => 'open',
                'channel' => $channel,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Analytics must never block opening the destination.
        }
    }
}
