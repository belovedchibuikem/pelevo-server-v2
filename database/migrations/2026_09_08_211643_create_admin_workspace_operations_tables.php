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
        Schema::create('admin_saved_views', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('admin_id')->constrained()->cascadeOnDelete();
            $table->string('module', 40);
            $table->string('name', 80);
            $table->boolean('shared')->default(false);
            $table->json('filters');
            $table->timestampsTz();
            $table->index(['module', 'shared']);
        });
        Schema::create('admin_exports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('admin_id')->constrained()->cascadeOnDelete();
            $table->string('module', 40);
            $table->json('filters');
            $table->string('state')->default('queued');
            $table->string('path')->nullable();
            $table->unsignedBigInteger('row_count')->default(0);
            $table->text('reason');
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->index(['admin_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_exports');
        Schema::dropIfExists('admin_saved_views');
    }
};
