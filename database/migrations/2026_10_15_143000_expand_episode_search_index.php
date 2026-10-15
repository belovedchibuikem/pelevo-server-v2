<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('episodes', function (Blueprint $table): void {
            $table->string('creator_name')->nullable();
            $table->text('tags')->nullable();
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('UPDATE episodes INNER JOIN shows ON shows.id = episodes.show_id SET episodes.creator_name = shows.author WHERE episodes.creator_name IS NULL AND shows.author IS NOT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('UPDATE episodes SET creator_name = shows.author FROM shows WHERE shows.id = episodes.show_id AND episodes.creator_name IS NULL AND shows.author IS NOT NULL');
        } else {
            $authors = DB::table('shows')->whereNotNull('author')->pluck('author', 'id');
            foreach ($authors as $showId => $author) {
                if (! is_string($author) || $author === '') {
                    continue;
                }
                DB::table('episodes')->where('show_id', $showId)->whereNull('creator_name')->update(['creator_name' => $author]);
            }
        }

        if (in_array($driver, ['mysql', 'pgsql'], true)) {
            Schema::table('episodes', function (Blueprint $table): void {
                $table->dropFullText('episodes_fulltext_search');
                $table->fullText(['title', 'description', 'tags', 'creator_name'], 'episodes_fulltext_search');
            });
        }

        if (Schema::hasTable('playback_progress') && Schema::hasColumn('playback_progress', 'updated_at')) {
            Schema::table('playback_progress', function (Blueprint $table): void {
                $table->index(['updated_at', 'episode_id'], 'playback_progress_recent_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('playback_progress')) {
            Schema::table('playback_progress', function (Blueprint $table): void {
                $table->dropIndex('playback_progress_recent_idx');
            });
        }

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'pgsql'], true)) {
            Schema::table('episodes', function (Blueprint $table): void {
                $table->dropFullText('episodes_fulltext_search');
                $table->fullText(['title', 'description'], 'episodes_fulltext_search');
            });
        }

        Schema::table('episodes', function (Blueprint $table): void {
            $table->dropColumn(['creator_name', 'tags']);
        });
    }
};
