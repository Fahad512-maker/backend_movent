<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// completed_at already exists (base migration) and is left untouched.
// completed_by/closed_by/reopened_by are each paired with a *_admin_id twin —
// mirrors the existing created_by/created_by_admin_id split on this same
// table, since either a Company Admin or a User(PM) can perform these
// actions and neither actor type can be stored in a single FK column
// (CompanyAdmin isn't a `users` row).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasColumn('projects', 'completed_by')) {
                $table->unsignedBigInteger('completed_by')->nullable()->after('completed_at');
                $table->foreign('completed_by')->references('id')->on('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('projects', 'completed_by_admin_id')) {
                $table->unsignedBigInteger('completed_by_admin_id')->nullable()->after('completed_by');
                $table->foreign('completed_by_admin_id')->references('id')->on('company_admins')->nullOnDelete();
            }
            if (!Schema::hasColumn('projects', 'closed_at')) {
                $table->timestamp('closed_at')->nullable()->after('completed_by_admin_id');
            }
            if (!Schema::hasColumn('projects', 'closed_by')) {
                $table->unsignedBigInteger('closed_by')->nullable()->after('closed_at');
                $table->foreign('closed_by')->references('id')->on('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('projects', 'closed_by_admin_id')) {
                $table->unsignedBigInteger('closed_by_admin_id')->nullable()->after('closed_by');
                $table->foreign('closed_by_admin_id')->references('id')->on('company_admins')->nullOnDelete();
            }
            if (!Schema::hasColumn('projects', 'close_reason')) {
                $table->text('close_reason')->nullable()->after('closed_by_admin_id');
            }
            if (!Schema::hasColumn('projects', 'reopened_at')) {
                $table->timestamp('reopened_at')->nullable()->after('close_reason');
            }
            if (!Schema::hasColumn('projects', 'reopened_by')) {
                $table->unsignedBigInteger('reopened_by')->nullable()->after('reopened_at');
                $table->foreign('reopened_by')->references('id')->on('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('projects', 'reopened_by_admin_id')) {
                $table->unsignedBigInteger('reopened_by_admin_id')->nullable()->after('reopened_by');
                $table->foreign('reopened_by_admin_id')->references('id')->on('company_admins')->nullOnDelete();
            }
            if (!Schema::hasColumn('projects', 'reopen_reason')) {
                $table->text('reopen_reason')->nullable()->after('reopened_by_admin_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            foreach (['completed_by', 'completed_by_admin_id', 'closed_by', 'closed_by_admin_id', 'reopened_by', 'reopened_by_admin_id'] as $fk) {
                if (Schema::hasColumn('projects', $fk)) {
                    $table->dropForeign([$fk]);
                }
            }
            $cols = ['completed_by', 'completed_by_admin_id', 'closed_at', 'closed_by', 'closed_by_admin_id', 'close_reason', 'reopened_at', 'reopened_by', 'reopened_by_admin_id', 'reopen_reason'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('projects', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
