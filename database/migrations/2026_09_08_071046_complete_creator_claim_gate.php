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
        Schema::table('show_claims', function (Blueprint $table): void {
            $table->json('live_evidence')->nullable();
        });
        Schema::create('claim_challenges', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('show_claim_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->text('destination_encrypted')->nullable();
            $table->string('destination_masked')->nullable();
            $table->char('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
        });
        Schema::create('claim_disputes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('show_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('show_claim_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('existing_claim_id')->constrained('show_claims')->cascadeOnDelete();
            $table->string('state')->default('open')->index();
            $table->text('reason');
            $table->timestampsTz();
            $table->unique('show_claim_id');
        });
        Schema::create('studios', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('creator_profile_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestampsTz();
        });
        Schema::create('studio_members', function (Blueprint $table): void {
            $table->foreignUlid('studio_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->timestampsTz();
            $table->primary(['studio_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('studio_members');
        Schema::dropIfExists('studios');
        Schema::dropIfExists('claim_disputes');
        Schema::dropIfExists('claim_challenges');
        Schema::table('show_claims', function (Blueprint $table): void {
            $table->dropColumn('live_evidence');
        });
    }
};
