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
        Schema::create('player_sessions', function (Blueprint $table): void {
            $table->foreignUlid('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignUlid('episode_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('playing')->default(false);
            $table->decimal('playback_rate', 4, 2)->default(1);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampsTz();
        });
        Schema::create('episode_notes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('episode_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->unsignedInteger('position_seconds')->nullable();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampsTz();
            $table->unique(['user_id', 'episode_id']);
        });
        Schema::create('episode_reactions', function (Blueprint $table): void {
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('episode_id')->constrained()->cascadeOnDelete();
            $table->string('reaction', 20);
            $table->timestampsTz();
            $table->primary(['user_id', 'episode_id']);
        });
        Schema::create('library_settings', function (Blueprint $table): void {
            $table->foreignUlid('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('hide_completed')->default(false);
            $table->string('sort', 30)->default('recent');
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampsTz();
        });
        Schema::create('download_settings', function (Blueprint $table): void {
            $table->foreignUlid('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('wifi_only')->default(true);
            $table->boolean('auto_delete_completed')->default(false);
            $table->unsignedInteger('max_storage_mb')->default(2048);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('download_settings');
        Schema::dropIfExists('library_settings');
        Schema::dropIfExists('episode_reactions');
        Schema::dropIfExists('episode_notes');
        Schema::dropIfExists('player_sessions');
    }
};
