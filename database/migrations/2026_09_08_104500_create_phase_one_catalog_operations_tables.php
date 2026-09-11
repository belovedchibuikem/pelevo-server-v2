<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_history', function (Blueprint $table): void {
            $table->unsignedInteger('result_count')->nullable()->after('query_hash');
            $table->index(['result_count', 'searched_at'], 'search_zero_result_index');
        });
        Schema::create('search_synonyms', function (Blueprint $table): void {
            $table->id();
            $table->string('term', 100);
            $table->string('synonym', 100);
            $table->boolean('active')->default(true)->index();
            $table->timestampsTz();
            $table->unique(['term', 'synonym']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_synonyms');
        Schema::table('search_history', function (Blueprint $table): void {
            $table->dropIndex('search_zero_result_index');
            $table->dropColumn('result_count');
        });
    }
};
