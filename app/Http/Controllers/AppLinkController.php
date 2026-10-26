<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Digital Asset Links and Apple App Site Association for pelevo.com.
 *
 * Android verifies assetlinks.json on the device at install time. The Play
 * Store does not publish or check this file. iOS verifies the AASA file for
 * Universal Links the same way.
 */
final class AppLinkController extends Controller
{
    /** @var list<string> */
    private const LINK_PATHS = [
        '/s/*',
        '/episodes/*',
        '/shows/*',
        '/playlists/*',
        '/collections/*',
        '/reels/*',
        '/creators/*',
        '/creator/*',
    ];

    public function assetLinks(): Response
    {
        $path = public_path('.well-known/assetlinks.json');
        abort_unless(is_file($path), 404);

        return response((string) file_get_contents($path), 200, [
            'Content-Type' => 'application/json',
        ]);
    }

    public function appleAppSiteAssociation(): Response
    {
        $teamId = strtoupper(trim((string) config('services.apple.team_id')));
        $bundleId = trim((string) (config('services.apple.application_id') ?: 'com.Podemeraldltd.pelevo'));
        abort_unless($teamId !== '' && preg_match('/^[A-Z0-9]{10}$/', $teamId) === 1, 404);
        abort_unless($bundleId !== '', 404);

        $appId = $teamId.'.'.$bundleId;
        $body = json_encode([
            'applinks' => [
                'apps' => [],
                'details' => [
                    [
                        'appID' => $appId,
                        'paths' => self::LINK_PATHS,
                    ],
                    [
                        'appIDs' => [$appId],
                        'components' => array_map(
                            static fn (string $path): array => ['/' => $path],
                            self::LINK_PATHS,
                        ),
                    ],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return response($body, 200, [
            'Content-Type' => 'application/json',
        ]);
    }
}
