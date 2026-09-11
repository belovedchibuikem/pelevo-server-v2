<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shows', function (Blueprint $table): void {
            $table->char('country_code', 2)->nullable()->index()->after('language');
        });

        Schema::create('home_modules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('key')->unique();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('kind');
            $table->string('source');
            $table->json('config')->nullable();
            $table->unsignedInteger('position')->default(0)->index();
            $table->boolean('active')->default(true)->index();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_modules');
        Schema::table('shows', function (Blueprint $table): void {
            $table->dropColumn('country_code');
        });
    }
};
