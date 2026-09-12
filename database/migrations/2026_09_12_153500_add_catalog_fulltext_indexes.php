<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shows', function (Blueprint $table): void {
            $table->fullText(['title', 'description', 'author'], 'shows_fulltext_search');
        });

        Schema::table('episodes', function (Blueprint $table): void {
            $table->fullText(['title', 'description'], 'episodes_fulltext_search');
        });

        Schema::table('editorial_playlists', function (Blueprint $table): void {
            $table->fullText(['title', 'description'], 'editorial_playlists_fulltext_search');
        });
    }

    public function down(): void
    {
        Schema::table('editorial_playlists', function (Blueprint $table): void {
            $table->dropFullText('editorial_playlists_fulltext_search');
        });

        Schema::table('episodes', function (Blueprint $table): void {
            $table->dropFullText('episodes_fulltext_search');
        });

        Schema::table('shows', function (Blueprint $table): void {
            $table->dropFullText('shows_fulltext_search');
        });
    }
};
