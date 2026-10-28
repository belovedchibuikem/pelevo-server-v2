<?php

use App\Models\GiftType;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        GiftType::seed();
    }

    public function down(): void
    {
        // Prior gift prices are not restored; sent gifts keep their ledgered coin amounts.
    }
};
