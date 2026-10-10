<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasColumn('projects', 'delivery_status')) {
                $table->string('delivery_status', 40)->nullable()->after('reopen_reason');
            }
            if (!Schema::hasColumn('projects', 'delivery_file_path')) {
                $table->string('delivery_file_path', 600)->nullable()->after('delivery_status');
            }
            if (!Schema::hasColumn('projects', 'delivery_file_name')) {
                $table->string('delivery_file_name', 255)->nullable()->after('delivery_file_path');
            }
            if (!Schema::hasColumn('projects', 'delivery_file_type')) {
                $table->string('delivery_file_type', 100)->nullable()->after('delivery_file_name');
            }
            if (!Schema::hasColumn('projects', 'delivery_file_size')) {
                $table->unsignedBigInteger('delivery_file_size')->nullable()->after('delivery_file_type');
            }
            if (!Schema::hasColumn('projects', 'delivery_submitted_at')) {
                $table->timestamp('delivery_submitted_at')->nullable()->after('delivery_file_size');
            }
            if (!Schema::hasColumn('projects', 'delivery_submitted_by')) {
                $table->unsignedBigInteger('delivery_submitted_by')->nullable()->after('delivery_submitted_at');
                $table->foreign('delivery_submitted_by')->references('id')->on('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('projects', 'delivery_approved_at')) {
                $table->timestamp('delivery_approved_at')->nullable()->after('delivery_submitted_by');
            }
            if (!Schema::hasColumn('projects', 'delivery_approved_by_admin_id')) {
                $table->unsignedBigInteger('delivery_approved_by_admin_id')->nullable()->after('delivery_approved_at');
                $table->foreign('delivery_approved_by_admin_id')->references('id')->on('company_admins')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            foreach (['delivery_submitted_by', 'delivery_approved_by_admin_id'] as $fk) {
                if (Schema::hasColumn('projects', $fk)) {
                    $table->dropForeign([$fk]);
                }
            }

            foreach ([
                'delivery_status',
                'delivery_file_path',
                'delivery_file_name',
                'delivery_file_type',
                'delivery_file_size',
                'delivery_submitted_at',
                'delivery_submitted_by',
                'delivery_approved_at',
                'delivery_approved_by_admin_id',
            ] as $column) {
                if (Schema::hasColumn('projects', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
