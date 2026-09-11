<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_exports', function (Blueprint $table): void {
            $table->string('disk')->default('local')->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('admin_exports', function (Blueprint $table): void {
            $table->dropColumn('disk');
        });
    }
};
