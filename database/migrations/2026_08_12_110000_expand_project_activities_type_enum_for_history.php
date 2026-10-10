<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $types = [
        'activated', 'created', 'updated', 'status_changed',
        'manager_assigned', 'manager_switched', 'manager_unassigned',
        'team_assigned', 'team_member_role_changed', 'team_member_removed',
        'seller_assigned', 'seller_switched',
        'invoice_linked', 'invoice_unlinked',
        'completed', 'close_blocked', 'closed', 'reopened',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('project_activities') || !Schema::hasColumn('project_activities', 'type')) {
            return;
        }

        $quoted = collect($this->types)->map(fn ($type) => "'{$type}'")->implode(',');

        DB::statement("ALTER TABLE project_activities MODIFY COLUMN type ENUM({$quoted}) NOT NULL");
    }

    public function down(): void
    {
        if (!Schema::hasTable('project_activities') || !Schema::hasColumn('project_activities', 'type')) {
            return;
        }

        DB::statement("ALTER TABLE project_activities MODIFY COLUMN type ENUM(
            'activated','completed','close_blocked','closed','reopened'
        ) NOT NULL");
    }
};
