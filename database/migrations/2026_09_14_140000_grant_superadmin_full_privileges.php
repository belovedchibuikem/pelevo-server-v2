<?php

use App\Support\AdminAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        AdminAccess::ensureRbac();

        foreach (['johnchibuikem20@gmail.com', 'info@pelevo.com'] as $email) {
            $adminId = DB::table('admins')->where('email', $email)->value('id');
            if (is_string($adminId) && $adminId !== '') {
                AdminAccess::attachRole($adminId);
            }
        }
    }

    public function down(): void
    {
        // Privileges stay attached; operators keep superadmin until explicitly changed.
    }
};
