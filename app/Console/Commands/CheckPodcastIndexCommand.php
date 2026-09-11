<?php

namespace App\Console\Commands;

use App\Integrations\PodcastIndex\PodcastIndexClient;
use App\Integrations\PodcastIndex\PodcastIndexException;
use App\Services\IntegrationSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CheckPodcastIndexCommand extends Command
{
    protected $signature = 'pelevo:check-podcast-index {--query=wardrobe memo : Sample search term}';

    protected $description = 'Show whether Podcast Index credentials are loaded on this server and run a sample search.';

    public function handle(IntegrationSettings $integrations, PodcastIndexClient $client): int
    {
        $integrations->applyToConfig();

        $enabled = (bool) config('services.podcast_index.enabled');
        $key = trim((string) config('services.podcast_index.api_key'));
        $secret = trim((string) config('services.podcast_index.api_secret'));
        $dbConfigured = Schema::hasTable('integration_settings')
            && DB::table('integration_settings')->where('provider', 'podcast_index')->exists();

        $this->table(['Check', 'Value'], [
            ['enabled', $enabled ? 'yes' : 'no'],
            ['api_key loaded', $key === '' ? 'NO (empty)' : 'yes (len '.strlen($key).', last4 '.substr($key, -4).')'],
            ['api_secret loaded', $secret === '' ? 'NO (empty)' : 'yes (len '.strlen($secret).')'],
            ['admin DB override row', $dbConfigured ? 'present' : 'none (env only)'],
            ['base_url', (string) config('services.podcast_index.base_url')],
            ['user_agent', (string) config('services.podcast_index.user_agent')],
        ]);

        if (! $enabled) {
            $this->error('PODCAST_INDEX_ENABLED is false. Set it to true in Forge Environment, then redeploy (config:cache).');

            return self::FAILURE;
        }

        if ($key === '' || $secret === '') {
            $this->error('API key/secret are empty in the running config.');
            $this->line('Fix one of:');
            $this->line('  1) Forge → Environment: set PODCAST_INDEX_API_KEY and PODCAST_INDEX_API_SECRET, then Deploy');
            $this->line('  2) Or Admin → Integrations → Podcast Index: paste key+secret, Save, then Test');
            $this->line('After editing .env only, you must Redeploy or run: php artisan config:cache');

            return self::FAILURE;
        }

        $query = trim((string) $this->option('query'));
        try {
            $feeds = $client->searchByTerm($query, 5)['feeds'] ?? [];
            $this->info('Podcast Index OK — '.count($feeds).' feed(s) for "'.$query.'".');
            foreach (array_slice($feeds, 0, 5) as $feed) {
                $this->line('  - '.(string) ($feed['title'] ?? 'Untitled').' (id '.(string) ($feed['id'] ?? '?').')');
            }

            return self::SUCCESS;
        } catch (PodcastIndexException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
