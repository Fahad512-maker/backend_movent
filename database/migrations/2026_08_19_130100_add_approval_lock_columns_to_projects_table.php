<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// completion_approved_* / reopen_requested_* / reopen_request_reason support
// the new 'approved_locked' status (see the status-enum migration alongside
// this one) and its PM-requests/Admin-approves reopen flow — see
// Api\Admin\ProjectController::approveCompletion()/reopen() and
// Api\User\ProjectController::requestReopen().
//
// Unlike completed_by/completed_by_admin_id (either actor type), these are
// each single-actor: only Admin can approve completion, only a User (PM) can
// request a reopen — so neither needs the *_by/*_by_admin_id twin used
// elsewhere on this table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasColumn('projects', 'completion_approved_at')) {
                $table->timestamp('completion_approved_at')->nullable()->after('reopen_reason');
            }
            if (!Schema::hasColumn('projects', 'completion_approved_by_admin_id')) {
                $table->unsignedBigInteger('completion_approved_by_admin_id')->nullable()->after('completion_approved_at');
                $table->foreign('completion_approved_by_admin_id')->references('id')->on('company_admins')->nullOnDelete();
            }
            if (!Schema::hasColumn('projects', 'reopen_requested_at')) {
                $table->timestamp('reopen_requested_at')->nullable()->after('completion_approved_by_admin_id');
            }
            if (!Schema::hasColumn('projects', 'reopen_requested_by')) {
                $table->unsignedBigInteger('reopen_requested_by')->nullable()->after('reopen_requested_at');
                $table->foreign('reopen_requested_by')->references('id')->on('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('projects', 'reopen_request_reason')) {
                $table->text('reopen_request_reason')->nullable()->after('reopen_requested_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            foreach (['completion_approved_by_admin_id', 'reopen_requested_by'] as $fk) {
                if (Schema::hasColumn('projects', $fk)) {
                    $table->dropForeign([$fk]);
                }
            }
            $cols = ['completion_approved_at', 'completion_approved_by_admin_id', 'reopen_requested_at', 'reopen_requested_by', 'reopen_request_reason'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('projects', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
