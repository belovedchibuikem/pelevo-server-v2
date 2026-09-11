<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS ledger_entries_no_update;
            DROP TRIGGER IF EXISTS ledger_entries_no_delete;

            CREATE TRIGGER ledger_entries_no_update
                BEFORE UPDATE ON ledger_entries
                FOR EACH ROW
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Ledger entries are immutable';

            CREATE TRIGGER ledger_entries_no_delete
                BEFORE DELETE ON ledger_entries
                FOR EACH ROW
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Ledger entries are immutable';
            SQL);
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS ledger_entries_no_update;
            DROP TRIGGER IF EXISTS ledger_entries_no_delete;
            SQL);
    }
};
