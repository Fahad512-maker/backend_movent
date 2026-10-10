<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Blueprint::change() needs doctrine/dbal (not installed in this project —
    // same constraint hit earlier for other enum columns), so this widens the
    // enum with a raw ALTER instead. Existing rows/values are untouched.
    public function up(): void
    {
        if (!Schema::hasTable('lead_activities')) {
            return;
        }

        // Later migrations widened this same enum further ('deal_created',
        // 'admin_override_used', 'company_changed'). On any database where
        // those already ran, replaying the 12-value list below would NARROW
        // the column and MySQL truncates every row holding one of the newer
        // values — "SQLSTATE[01000]: Warning: 1265 Data truncated for column
        // 'type'", which is exactly what a restored-without-migration-history
        // database hits. This migration's only job was adding 'transferred',
        // so once that's present there is nothing left to do.
        $current = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'lead_activities')
            ->where('COLUMN_NAME', 'type')
            ->value('COLUMN_TYPE');

        if ($current && str_contains($current, "'transferred'")) {
            return;
        }

        DB::statement("ALTER TABLE lead_activities MODIFY COLUMN type ENUM(
            'created', 'updated', 'status_changed', 'assigned', 'transferred',
            'note_added', 'followup_added', 'followup_completed',
            'converted', 'won', 'lost', 'reopened'
        ) NOT NULL");
    }

    public function down(): void
    {
        if (!Schema::hasTable('lead_activities')) {
            return;
        }

        DB::statement("ALTER TABLE lead_activities MODIFY COLUMN type ENUM(
            'created', 'updated', 'status_changed', 'assigned',
            'note_added', 'followup_added', 'followup_completed',
            'converted', 'won', 'lost', 'reopened'
        ) NOT NULL");
    }
};
