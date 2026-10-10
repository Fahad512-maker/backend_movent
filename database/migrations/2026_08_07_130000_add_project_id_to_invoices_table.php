<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Which project this invoice is billed under — the OPPOSITE direction from
// the existing projects.invoice_id (which means "the invoice this project
// originated from" and is left untouched). This new column is what lets a
// project accumulate multiple invoices over its life (deposit/milestone/
// final/change-request): the originating invoice gets both projects.invoice_id
// AND its own project_id set back to that project; any later invoice for the
// same project just gets project_id set, no pivot table needed since one
// invoice belongs to at most one project.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('invoices') || Schema::hasColumn('invoices', 'project_id')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('lead_id')
                ->constrained('projects')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'project_id')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropConstrainedForeignId('project_id');
            });
        }
    }
};
