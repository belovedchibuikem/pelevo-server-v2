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
        // Gift rows reference these types. Leave the catalog in place on rollback.
    }
};
