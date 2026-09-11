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
        Schema::create('support_ticket_links', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('admin_id')->constrained()->restrictOnDelete();
            $table->string('kind', 40);
            $table->string('target_id', 191);
            $table->text('reason');
            $table->unique(['support_ticket_id', 'kind', 'target_id'], 'support_link_unique');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_ticket_links');
    }
};
