<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Widens project_activities.type to include 'activated' — the Draft -> Active
// transition (Api\User\ProjectController::activate() and its Admin twin) was
// added after the original enum, which only covered the completion/close/reopen
// lifecycle, so every activation was failing with "Data truncated for column
// 'type'". Same additive, check-first pattern as the other enum-widening
// migrations in this codebase.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('project_activities') || !Schema::hasColumn('project_activities', 'type')) {
            return;
        }

        $column = DB::selectOne(
            "SELECT COLUMN_TYPE AS type FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'project_activities' AND COLUMN_NAME = 'type'"
        );
        if ($column && str_contains($column->type, "'activated'")) {
            return;
        }

        DB::statement("ALTER TABLE project_activities MODIFY COLUMN type ENUM(
            'activated','completed','close_blocked','closed','reopened'
        ) NOT NULL");
    }

    public function down(): void
    {
        if (!Schema::hasTable('project_activities') || !Schema::hasColumn('project_activities', 'type')) {
            return;
        }

        DB::statement("ALTER TABLE project_activities MODIFY COLUMN type ENUM(
            'completed','close_blocked','closed','reopened'
        ) NOT NULL");
    }
};
