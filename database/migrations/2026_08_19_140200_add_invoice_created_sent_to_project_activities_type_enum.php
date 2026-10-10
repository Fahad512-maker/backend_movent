<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Api\Admin\ProjectController::createInvoice() logs 'invoice_created' and
// 'invoice_sent' via Project::logActivity() — same project_activities table
// the existing 'invoice_linked'/'invoice_unlinked' entries already use —
// but those two type values were never added to the enum, so the very first
// call failed with "Data truncated for column 'type'".
return new class extends Migration
{
    private array $types = [
        'activated', 'created', 'updated', 'status_changed',
        'manager_assigned', 'manager_switched', 'manager_unassigned',
        'team_assigned', 'team_member_role_changed', 'team_member_removed',
        'seller_assigned', 'seller_switched',
        'invoice_linked', 'invoice_unlinked', 'invoice_created', 'invoice_sent',
        'completed', 'project_delivery_submitted', 'project_delivery_approved',
        'project_delivered_to_client',
        'approved_locked', 'reopen_requested',
        'close_blocked', 'closed', 'reopened',
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

        $types = array_values(array_diff($this->types, ['invoice_created', 'invoice_sent']));
        $quoted = collect($types)->map(fn ($type) => "'{$type}'")->implode(',');
        DB::statement("ALTER TABLE project_activities MODIFY COLUMN type ENUM({$quoted}) NOT NULL");
    }
};
