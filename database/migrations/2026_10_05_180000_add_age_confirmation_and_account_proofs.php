<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('age_majority_confirmed_at')->nullable();
        });

        Schema::table('payout_methods', function (Blueprint $table): void {
            $table->string('proof_path')->nullable();
            $table->string('proof_disk', 32)->nullable();
            $table->string('proof_mime', 127)->nullable();
            $table->string('proof_status', 20)->default('none')->index();
            $table->timestampTz('proof_reviewed_at')->nullable();
            $table->foreignUlid('proof_reviewed_by')->nullable()->constrained('admins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payout_methods', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('proof_reviewed_by');
            $table->dropColumn(['proof_path', 'proof_disk', 'proof_mime', 'proof_status', 'proof_reviewed_at']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('age_majority_confirmed_at');
        });
    }
};
