<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "New Project" invoice-create flow: the seller names a not-yet-created
// project (title + reference) right on the invoice instead of picking an
// existing Project row — see project_id (already on this table) for the
// "Existing Project" side of that same flow.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'project_title')) {
                $table->string('project_title', 255)->nullable()->after('project_id');
            }
            if (!Schema::hasColumn('invoices', 'project_reference')) {
                $table->string('project_reference', 100)->nullable()->after('project_title');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            foreach (['project_title', 'project_reference'] as $col) {
                if (Schema::hasColumn('invoices', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
