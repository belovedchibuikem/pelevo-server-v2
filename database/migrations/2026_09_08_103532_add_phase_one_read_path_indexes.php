<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('episodes', function (Blueprint $table): void {
            $table->index(['availability', 'published_at', 'id'], 'episodes_discovery_cursor_index');
        });
        Schema::table('follows', function (Blueprint $table): void {
            $table->index(['show_id', 'created_at', 'user_id'], 'follows_show_ranking_index');
            $table->index(['user_id', 'created_at', 'show_id'], 'follows_user_recent_index');
        });
        Schema::table('show_ratings', function (Blueprint $table): void {
            $table->index(['show_id', 'rating'], 'show_ratings_chart_index');
        });
        Schema::table('downloads', function (Blueprint $table): void {
            $table->index(['user_id', 'updated_at', 'id'], 'downloads_user_recent_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('downloads', fn (Blueprint $table) => $table->dropIndex('downloads_user_recent_index'));
        Schema::table('show_ratings', fn (Blueprint $table) => $table->dropIndex('show_ratings_chart_index'));
        Schema::table('follows', function (Blueprint $table): void {
            $table->dropIndex('follows_show_ranking_index');
            $table->dropIndex('follows_user_recent_index');
        });
        Schema::table('episodes', fn (Blueprint $table) => $table->dropIndex('episodes_discovery_cursor_index'));
    }
};
