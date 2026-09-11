<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\Show;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class BrowserTestSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment('testing') || config('database.connections.mysql.database') !== 'pelevo_browser_test') {
            throw new \RuntimeException('Browser fixtures require the isolated pelevo_browser_test database.');
        }
        $this->call(DatabaseSeeder::class);
        $admin = Admin::firstOrCreate(['email' => 'browser-admin@example.test'], ['name' => 'Browser Operator', 'password' => 'Browser-test-only-9!', 'status' => 'active', 'mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now()]);
        DB::table('admins')->where('id', $admin->id)->update(['mfa_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP'), 'mfa_confirmed_at' => now()]);
        DB::table('admin_role')->insertOrIgnore(['admin_id' => $admin->id, 'role_id' => DB::table('roles')->where('name', 'superadmin')->value('id')]);
        $user = User::firstOrCreate(['email' => 'browser-listener@example.test'], ['name' => 'Amina Listener', 'handle' => 'amina-listener', 'password' => 'Browser-test-only-9!', 'status' => 'active', 'country_code' => 'NG']);
        DB::table('support_tickets')->updateOrInsert(['id' => '01M20000000000000000000001'], ['user_id' => $user->id, 'subject' => 'Episode playback assistance', 'message' => 'The episode stops before the end. Please help me resume listening.', 'state' => 'open', 'priority' => 'high', 'version' => 1, 'sla_due_at' => '2026-09-10 12:00:00', 'created_at' => '2026-09-08 12:00:00', 'updated_at' => '2026-09-08 12:00:00']);
        DB::table('notification_templates')->updateOrInsert(['id' => '01M20000000000000000000002'], ['key' => 'weekly-discovery', 'version' => 1, 'title' => 'Your next great listen', 'body' => 'Discover new conversations from the creators you follow.', 'active' => true, 'created_at' => '2026-09-08 12:00:00', 'updated_at' => '2026-09-08 12:00:00']);
        Show::firstOrCreate(['rss_url' => 'https://example.test/browser-feed.xml'], ['title' => 'The Listening Room', 'author' => 'Pelevo Studio', 'status' => 'active']);
    }
}
