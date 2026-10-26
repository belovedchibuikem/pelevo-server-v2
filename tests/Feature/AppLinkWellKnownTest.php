<?php

namespace Tests\Feature;

use Tests\TestCase;

final class AppLinkWellKnownTest extends TestCase
{
    public function test_assetlinks_names_the_android_package_and_release_certificate(): void
    {
        $response = $this->get('/.well-known/assetlinks.json');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/json');
        $body = $response->json();
        $this->assertSame('com.Podemeraldltd.pelevo', $body[0]['target']['package_name']);
        $this->assertContains(
            '52:73:9C:C2:68:F1:86:12:CE:59:C4:B3:4E:78:85:0A:A6:A6:A2:01:8F:F6:0E:1D:5B:C4:37:1A:F0:22:62:C0',
            $body[0]['target']['sha256_cert_fingerprints'],
        );
        $this->assertStringNotContainsString(
            'REPLACE_WITH_UPLOAD_OR_APP_SIGNING_SHA256',
            (string) json_encode($body),
        );
    }

    public function test_apple_app_site_association_requires_the_team_id(): void
    {
        config(['services.apple.team_id' => null]);

        $this->get('/.well-known/apple-app-site-association')->assertNotFound();
    }

    public function test_apple_app_site_association_lists_shared_content_paths(): void
    {
        config([
            'services.apple.team_id' => 'ABCDE12345',
            'services.apple.application_id' => 'com.Podemeraldltd.pelevo',
        ]);

        $response = $this->get('/.well-known/apple-app-site-association');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/json');
        $response->assertJsonPath('applinks.details.0.appID', 'ABCDE12345.com.Podemeraldltd.pelevo');
        $response->assertJsonPath('applinks.details.1.appIDs.0', 'ABCDE12345.com.Podemeraldltd.pelevo');
        $paths = $response->json('applinks.details.0.paths');
        $this->assertContains('/creator/*', $paths);
        $this->assertContains('/creators/*', $paths);
        $this->assertContains('/playlists/*', $paths);
        $this->assertContains('/episodes/*', $paths);
        $this->assertContains('/reels/*', $paths);
    }
}
