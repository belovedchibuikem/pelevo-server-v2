<?php

use App\Support\AdminAccess;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        AdminAccess::ensureRbac();
    }

    public function down(): void
    {
        // Moderator access stays attached so operators can still act during coverage gaps.
    }
};
