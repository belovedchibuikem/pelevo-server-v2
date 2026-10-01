<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('founding_creator_applications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 120);
            $table->string('show_name', 191);
            $table->string('show_url', 500);
            $table->string('email', 191);
            $table->string('social_handle', 120)->nullable();
            $table->string('publish_frequency', 40)->nullable()->index();
            $table->text('notes')->nullable();
            $table->string('state', 40)->default('new')->index();
            $table->text('admin_note')->nullable();
            $table->foreignUlid('reviewed_by_admin_id')->nullable()->references('id')->on('admins')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->unsignedInteger('submission_count')->default(1);
            $table->timestampsTz();
            $table->index(['email', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('founding_creator_applications');
    }
};
