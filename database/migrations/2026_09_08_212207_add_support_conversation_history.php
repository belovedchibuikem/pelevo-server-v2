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
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1);
        });
        Schema::create('support_ticket_notes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('admin_id')->constrained()->restrictOnDelete();
            $table->text('body');
            $table->timestampTz('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_ticket_notes');
        Schema::table('support_tickets', fn (Blueprint $table) => $table->dropColumn('version'));
    }
};
