<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_settings', function (Blueprint $table): void {
            $table->string('provider', 80)->primary();
            $table->text('payload_encrypted');
            $table->foreignUlid('updated_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('reason');
            $table->timestampTz('last_tested_at')->nullable();
            $table->string('last_test_state', 20)->nullable();
            $table->text('last_test_message')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_settings');
    }
};
