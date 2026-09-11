<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $pages = [
            [
                'slug' => 'terms',
                'title' => 'Terms of Use',
                'body' => "## Agreement\nThese placeholder Terms of Use govern access to Pelevo. Replace this text with counsel-reviewed terms before any public store listing.\n\n## Accounts\nYou are responsible for activity on your account and for keeping credentials secure.\n\n## Content\nCreators retain rights to their podcasts and reels subject to the licenses they grant Pelevo to operate the service.\n\n## Payments\nCoins, gifts, Earn awards, and withdrawals are subject to platform rules, fraud controls, and applicable law.\n\n## Contact\nQuestions about these terms should go to Pelevo support.",
            ],
            [
                'slug' => 'privacy',
                'title' => 'Privacy Policy',
                'body' => "## Overview\nThis placeholder Privacy Policy describes how Pelevo may process account, device, listening, and payment metadata. Replace with counsel-reviewed privacy terms before public launch.\n\n## Data we process\nAccount profile, device identifiers, playback progress, notifications preferences, and transaction receipts needed to operate the app.\n\n## Sharing\nWe share data with infrastructure and payment providers only as needed to deliver the service.\n\n## Retention\nWe retain data for as long as needed for the purposes described here and legal obligations.\n\n## Contact\nPrivacy requests should go to Pelevo support.",
            ],
            [
                'slug' => 'guidelines',
                'title' => 'Community Guidelines',
                'body' => "## Be respectful\nDo not harass, threaten, or exploit others.\n\n## No illegal content\nDo not upload or promote illegal material.\n\n## Creator integrity\nDo not fake claims, manipulate Earn sessions, or abuse gifts and payouts.\n\n## Enforcement\nPelevo may remove content, restrict features, or suspend accounts that break these guidelines.",
            ],
        ];

        foreach ($pages as $page) {
            $existing = DB::table('cms_pages')->where('slug', $page['slug'])->first();
            if ($existing) {
                DB::table('cms_pages')->where('id', $existing->id)->update([
                    'title' => $page['title'],
                    'body' => $page['body'],
                    'version' => ((int) $existing->version) + 1,
                    'state' => 'published',
                    'published_at' => $existing->published_at ?? now(),
                    'updated_at' => now(),
                ]);
                continue;
            }
            DB::table('cms_pages')->insert([
                'id' => (string) Str::ulid(),
                'slug' => $page['slug'],
                'title' => $page['title'],
                'body' => $page['body'],
                'version' => 1,
                'state' => 'published',
                'published_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('cms_pages')->whereIn('slug', ['terms', 'privacy', 'guidelines'])->delete();
    }
};
