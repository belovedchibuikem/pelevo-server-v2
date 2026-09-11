<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_ledger_entry_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'Ledger entries are immutable';
            END;
            $$;

            DROP TRIGGER IF EXISTS ledger_entries_no_update ON ledger_entries;
            CREATE TRIGGER ledger_entries_no_update
                BEFORE UPDATE ON ledger_entries
                FOR EACH ROW EXECUTE FUNCTION prevent_ledger_entry_mutation();

            DROP TRIGGER IF EXISTS ledger_entries_no_delete ON ledger_entries;
            CREATE TRIGGER ledger_entries_no_delete
                BEFORE DELETE ON ledger_entries
                FOR EACH ROW EXECUTE FUNCTION prevent_ledger_entry_mutation();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS ledger_entries_no_update ON ledger_entries;
            DROP TRIGGER IF EXISTS ledger_entries_no_delete ON ledger_entries;
            DROP FUNCTION IF EXISTS prevent_ledger_entry_mutation();
            SQL);
    }
};
