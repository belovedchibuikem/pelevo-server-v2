<?php

use App\Support\MarketingLegal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->publish('privacy', 'Privacy Policy', MarketingLegal::privacyCmsBody());
        $this->publish('terms', 'Terms and Conditions of Use', MarketingLegal::termsCmsBody());
    }

    public function down(): void
    {
        // Published legal documents are append-forward records. Rollback does not
        // restore superseded legal language without an explicit counsel-approved version.
    }

    private function publish(string $slug, string $title, string $body): void
    {
        $existing = DB::table('cms_pages')->where('slug', $slug)->first();
        if ($existing) {
            DB::table('cms_pages')->where('id', $existing->id)->update([
                'title' => $title,
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
            'slug' => $slug,
            'title' => $title,
            'body' => $body,
            'version' => 1,
            'state' => 'published',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
