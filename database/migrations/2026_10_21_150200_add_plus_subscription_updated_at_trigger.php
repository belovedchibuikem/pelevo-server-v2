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
CREATE OR REPLACE FUNCTION plus_set_updated_at()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
  NEW.updated_at := now();
  RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS premium_subscriptions_set_updated_at ON premium_subscriptions;
CREATE TRIGGER premium_subscriptions_set_updated_at
  BEFORE UPDATE ON premium_subscriptions
  FOR EACH ROW
  EXECUTE FUNCTION plus_set_updated_at();

DROP TRIGGER IF EXISTS premium_entitlements_set_updated_at ON premium_entitlements;
CREATE TRIGGER premium_entitlements_set_updated_at
  BEFORE UPDATE ON premium_entitlements
  FOR EACH ROW
  EXECUTE FUNCTION plus_set_updated_at();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS premium_subscriptions_set_updated_at ON premium_subscriptions;
DROP TRIGGER IF EXISTS premium_entitlements_set_updated_at ON premium_entitlements;
DROP FUNCTION IF EXISTS plus_set_updated_at();
SQL);
    }
};
