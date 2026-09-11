<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('show_feed_states', function (Blueprint $table): void {
            $table->index(['state', 'next_poll_at', 'show_id'], 'feed_states_due_poll_index');
        });
        Schema::table('feed_sync_runs', function (Blueprint $table): void {
            $table->index(['show_id', 'started_at'], 'feed_sync_show_started_index');
        });

        Schema::create('scheduler_heartbeats', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('host', 191);
            $table->timestampTz('ran_at')->index();
            $table->timestampsTz();
        });
        Schema::create('admin_operation_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('admin_id')->constrained('admins')->restrictOnDelete();
            $table->string('action')->index();
            $table->string('target')->nullable();
            $table->text('reason');
            $table->string('state')->index();
            $table->uuid('idempotency_key')->unique();
            $table->json('result')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_operation_requests');
        Schema::dropIfExists('scheduler_heartbeats');
        Schema::table('feed_sync_runs', function (Blueprint $table): void {
            $table->dropIndex('feed_sync_show_started_index');
        });
        Schema::table('show_feed_states', function (Blueprint $table): void {
            $table->dropIndex('feed_states_due_poll_index');
        });
    }
};
