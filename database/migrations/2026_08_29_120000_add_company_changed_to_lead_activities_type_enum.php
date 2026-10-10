<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Widens lead_activities.type for Api\Admin\LeadController::updateCompany()
// (moving a lead created under the wrong Company) — same additive,
// check-first pattern as every other enum-widening migration in this
// codebase (see 2026_08_08_110000_add_deal_types_to_lead_activities_type_enum.php).
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('lead_activities') || !Schema::hasColumn('lead_activities', 'type')) {
            return;
        }

        $column = DB::selectOne(
            "SELECT COLUMN_TYPE AS type FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_activities' AND COLUMN_NAME = 'type'"
        );
        if ($column && str_contains($column->type, "'company_changed'")) {
            return;
        }

        DB::statement("ALTER TABLE lead_activities MODIFY COLUMN type ENUM(
            'created','updated','status_changed','assigned','transferred','note_added',
            'followup_added','followup_completed','converted','won','lost','reopened',
            'deal_created','admin_override_used','company_changed'
        ) NOT NULL");
    }

    public function down(): void
    {
        if (!Schema::hasTable('lead_activities') || !Schema::hasColumn('lead_activities', 'type')) {
            return;
        }

        DB::statement("ALTER TABLE lead_activities MODIFY COLUMN type ENUM(
            'created','updated','status_changed','assigned','transferred','note_added',
            'followup_added','followup_completed','converted','won','lost','reopened',
            'deal_created','admin_override_used'
        ) NOT NULL");
    }
};
