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
        foreach (['support_tickets', 'feedback'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->uuid('client_request_id')->nullable();
                $table->char('request_hash', 64)->nullable();
                $table->unique(['user_id', 'client_request_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Rollback discards retry identifiers; existing submissions remain.
        foreach (['support_tickets', 'feedback'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropUnique(['user_id', 'client_request_id']);
                $table->dropColumn(['client_request_id', 'request_hash']);
            });
        }
    }
};
