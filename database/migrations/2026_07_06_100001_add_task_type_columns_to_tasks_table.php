<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->enum('task_type', ['general', 'production', 'client_request', 'internal'])->default('general')->after('priority');
            $table->boolean('is_production_task')->default(false)->after('task_type');
            $table->unsignedBigInteger('assigned_by')->nullable()->after('assigned_to');
            $table->timestamp('submitted_at')->nullable()->after('completed_at');
            $table->timestamp('approved_at')->nullable()->after('submitted_at');
            $table->timestamp('delivered_at')->nullable()->after('approved_at');

            $table->foreign('assigned_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['assigned_by']);
            $table->dropColumn(['task_type', 'is_production_task', 'assigned_by', 'submitted_at', 'approved_at', 'delivered_at']);
        });
    }
};
