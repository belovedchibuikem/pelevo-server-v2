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
        Schema::create('creator_followers', function (Blueprint $table): void {
            $table->foreignUlid('creator_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();
            $table->primary(['creator_profile_id', 'user_id']);
            $table->index(['creator_profile_id', 'created_at']);
        });
        Schema::create('tax_profiles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('creator_profile_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('country_code', 2);
            $table->string('state')->default('missing')->index();
            $table->text('details_encrypted')->nullable();
            $table->timestampsTz();
        });
        Schema::create('catalog_merges', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('survivor_show_id')->constrained('shows')->restrictOnDelete();
            $table->foreignUlid('duplicate_show_id')->constrained('shows')->restrictOnDelete();
            $table->foreignUlid('admin_id')->constrained()->restrictOnDelete();
            $table->string('state')->default('queued')->index();
            $table->string('idempotency_key')->unique();
            $table->json('preview');
            $table->text('reason');
            $table->text('error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->unique(['survivor_show_id', 'duplicate_show_id']);
        });
        Schema::create('show_redirects', function (Blueprint $table): void {
            $table->foreignUlid('source_show_id')->primary()->constrained('shows')->restrictOnDelete();
            $table->foreignUlid('destination_show_id')->constrained('shows')->restrictOnDelete();
            $table->foreignUlid('catalog_merge_id')->constrained()->restrictOnDelete();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('show_redirects');
        Schema::dropIfExists('catalog_merges');
        Schema::dropIfExists('tax_profiles');
        Schema::dropIfExists('creator_followers');
    }
};
