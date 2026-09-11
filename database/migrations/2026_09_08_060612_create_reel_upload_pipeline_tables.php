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
        Schema::create('media_uploads', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 40);
            $table->string('path', 500);
            $table->string('expected_mime', 100);
            $table->unsignedBigInteger('expected_size');
            $table->unsignedBigInteger('actual_size')->nullable();
            $table->char('checksum_sha256', 64)->nullable();
            $table->string('state')->default('pending')->index();
            $table->json('probe')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('uploaded_at')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'created_at', 'id'], 'media_uploads_user_cursor_index');
        });
        Schema::create('reel_media', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('reel_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUlid('media_upload_id')->unique()->constrained()->restrictOnDelete();
            $table->string('mime', 100);
            $table->unsignedInteger('duration_ms');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('processing_state')->default('ready')->index();
            $table->string('thumbnail_path', 500)->nullable();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reel_media');
        Schema::dropIfExists('media_uploads');
    }
};
