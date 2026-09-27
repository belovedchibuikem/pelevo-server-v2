<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shows', function (Blueprint $table): void {
            $table->foreignId('earn_category_id')->nullable()->after('earn_enabled')->constrained('categories')->nullOnDelete();
            $table->unsignedInteger('earn_position')->default(0)->after('earn_category_id');
        });
    }

    public function down(): void
    {
        Schema::table('shows', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('earn_category_id');
            $table->dropColumn('earn_position');
        });
    }
};
