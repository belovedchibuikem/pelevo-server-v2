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
        Schema::table('support_ticket_notes', function (Blueprint $table): void {
            $table->boolean('public_reply')->default(false);
        });
        Schema::create('support_ticket_attachments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('admin_id')->constrained()->restrictOnDelete();
            $table->string('name', 180);
            $table->string('path');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->text('reason');
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_ticket_attachments');
        Schema::table('support_ticket_notes', function (Blueprint $table): void {
            $table->dropColumn('public_reply');
        });
    }
};
