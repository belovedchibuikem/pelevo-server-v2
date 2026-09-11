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
        Schema::create('user_profiles', function (Blueprint $table): void {
            $table->foreignUlid('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->text('bio')->nullable();
            $table->text('avatar_url')->nullable();
            $table->string('locale', 15)->default('en');
            $table->string('timezone', 64)->default('UTC');
            $table->timestampsTz();
        });
        Schema::create('user_preferences', function (Blueprint $table): void {
            $table->foreignUlid('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('explicit_content')->default(false);
            $table->boolean('autoplay')->default(true);
            $table->boolean('download_wifi_only')->default(true);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampsTz();
        });
        Schema::create('user_interests', function (Blueprint $table): void {
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('interest');
            $table->timestampsTz();
            $table->primary(['user_id', 'interest']);
        });
        Schema::create('connected_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_subject');
            $table->string('email')->nullable();
            $table->timestampsTz();
            $table->unique(['provider', 'provider_subject']);
            $table->unique(['user_id', 'provider']);
        });
        Schema::create('one_time_codes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('destination');
            $table->string('purpose');
            $table->char('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
            $table->index(['destination', 'purpose', 'created_at']);
        });
        Schema::create('data_export_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('state')->default('queued')->index();
            $table->string('disk')->nullable();
            $table->text('path')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });
        Schema::create('account_deletion_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('state')->default('queued')->index();
            $table->text('reason')->nullable();
            $table->timestampTz('scheduled_for');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_deletion_requests');
        Schema::dropIfExists('data_export_requests');
        Schema::dropIfExists('one_time_codes');
        Schema::dropIfExists('connected_accounts');
        Schema::dropIfExists('user_interests');
        Schema::dropIfExists('user_preferences');
        Schema::dropIfExists('user_profiles');
    }
};
