<?php

use App\Support\MarketingLegal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $body = MarketingLegal::privacyCmsBody();
        $existing = DB::table('cms_pages')->where('slug', 'privacy')->first();
        if ($existing) {
            DB::table('cms_pages')->where('id', $existing->id)->update([
                'title' => 'Privacy Policy',
                'body' => $body,
                'version' => ((int) $existing->version) + 1,
                'state' => 'published',
                'published_at' => $existing->published_at ?? now(),
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('cms_pages')->insert([
            'id' => (string) Str::ulid(),
            'slug' => 'privacy',
            'title' => 'Privacy Policy',
            'body' => $body,
            'version' => 1,
            'state' => 'published',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('cms_pages')->where('slug', 'privacy')->update([
            'title' => 'Privacy Policy',
            'body' => "## Overview\nThis placeholder Privacy Policy describes how Pelevo may process account, device, listening, and payment metadata. Replace with counsel-reviewed privacy terms before public launch.\n\n## Data we process\nAccount profile, device identifiers, playback progress, notifications preferences, and transaction receipts needed to operate the app.\n\n## Sharing\nWe share data with infrastructure and payment providers only as needed to deliver the service.\n\n## Retention\nWe retain data for as long as needed for the purposes described here and legal obligations.\n\n## Contact\nPrivacy requests should go to Pelevo support.",
            'updated_at' => now(),
        ]);
    }
};
