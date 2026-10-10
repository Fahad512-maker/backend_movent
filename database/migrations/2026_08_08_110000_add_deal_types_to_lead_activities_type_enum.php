<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Widens lead_activities.type to include the Deal-workflow activity types
// introduced by the Lead-Won -> eligibility -> Project gate
// (Api\User\LeadController::updateStatus() logging 'deal_created', and
// Api\User\ProjectController::store() logging 'admin_override_used') —
// same additive, check-first pattern as every other enum-widening migration
// in this codebase.
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
        if ($column && str_contains($column->type, "'deal_created'")) {
            return;
        }

        DB::statement("ALTER TABLE lead_activities MODIFY COLUMN type ENUM(
            'created','updated','status_changed','assigned','transferred','note_added',
            'followup_added','followup_completed','converted','won','lost','reopened',
            'deal_created','admin_override_used'
        ) NOT NULL");
    }

    public function down(): void
    {
        if (!Schema::hasTable('lead_activities') || !Schema::hasColumn('lead_activities', 'type')) {
            return;
        }

        DB::statement("ALTER TABLE lead_activities MODIFY COLUMN type ENUM(
            'created','updated','status_changed','assigned','transferred','note_added',
            'followup_added','followup_completed','converted','won','lost','reopened'
        ) NOT NULL");
    }
};
