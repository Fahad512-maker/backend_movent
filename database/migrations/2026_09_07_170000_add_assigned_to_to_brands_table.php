<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Who owns a brand's invoicing. Only staff holding Invoice create/manage
// permissions in the same company are offered — see
// Api\Admin\BrandController::ASSIGNABLE_INVOICE_PERMISSIONS.
//
// nullOnDelete: a brand outlives the person it was assigned to. Removing that
// user leaves the brand unassigned rather than taking it (or the invoices
// raised under it) with them.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            if (!Schema::hasColumn('brands', 'assigned_to')) {
                $table->foreignId('assigned_to')->nullable()->after('is_active')
                    ->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            if (Schema::hasColumn('brands', 'assigned_to')) {
                $table->dropConstrainedForeignId('assigned_to');
            }
        });
    }
};
