<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// seller_id records WHO initiated a sales handoff, distinct from created_by
// (always set) and project_manager_id (may be a different person entirely)
// — a project can have created_by=seller AND project_manager_id=someone else,
// and seller_id is what keeps it discoverable as "this seller's handoff" in
// Api\User\ProjectController::visibleProjects() even then. source is a plain
// free-text marker ('sales_handoff' today) rather than an enum, so future
// handoff origins don't require another migration.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasColumn('projects', 'seller_id')) {
                $table->unsignedBigInteger('seller_id')->nullable()->after('created_by');
                $table->foreign('seller_id')->references('id')->on('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('projects', 'source')) {
                $table->string('source', 50)->nullable()->after('seller_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (Schema::hasColumn('projects', 'seller_id')) {
                $table->dropForeign(['seller_id']);
                $table->dropColumn('seller_id');
            }
            if (Schema::hasColumn('projects', 'source')) {
                $table->dropColumn('source');
            }
        });
    }
};
