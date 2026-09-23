<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_sessions', function (Blueprint $table): void {
            $table->string('mux_live_stream_id', 191)->nullable()->after('viewer_count');
            $table->string('stream_key', 191)->nullable()->after('mux_live_stream_id');
            $table->string('ingest_url', 500)->nullable()->after('stream_key');
            $table->string('playback_url', 500)->nullable()->after('ingest_url');
            $table->boolean('comments_enabled')->default(true)->after('playback_url');
            $table->boolean('notify_followers')->default(true)->after('comments_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('live_sessions', function (Blueprint $table): void {
            $table->dropColumn([
                'mux_live_stream_id',
                'stream_key',
                'ingest_url',
                'playback_url',
                'comments_enabled',
                'notify_followers',
            ]);
        });
    }
};
