<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Accounts created before signup verification stay usable.
        // New registrations leave email_verified_at null until the code is confirmed.
        DB::table('users')->whereNull('email_verified_at')->update([
            'email_verified_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        // Verified timestamps cannot be split back into "legacy" versus "confirmed".
    }
};
