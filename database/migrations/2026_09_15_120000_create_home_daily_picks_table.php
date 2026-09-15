<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('home_daily_picks', function (Blueprint $table): void {
            $table->foreignUlid('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignUlid('episode_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('selected_at');
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_daily_picks');
    }
};
