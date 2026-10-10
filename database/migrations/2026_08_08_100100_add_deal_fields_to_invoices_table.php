<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lets an invoice clearly state what it's for (spec: client must know which
// service/proposed project an invoice belongs to before a Project exists)
// and whether it counts toward the Deal's kickoff-payment requirement.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'invoice_purpose')) {
                $table->string('invoice_purpose', 255)->nullable()->after('notes');
            }
            if (!Schema::hasColumn('invoices', 'payment_type')) {
                $table->string('payment_type', 30)->nullable()->after('invoice_purpose');
            }
            if (!Schema::hasColumn('invoices', 'required_payment_amount')) {
                $table->decimal('required_payment_amount', 12, 2)->nullable()->after('payment_type');
            }
            if (!Schema::hasColumn('invoices', 'counts_toward_project_activation')) {
                $table->boolean('counts_toward_project_activation')->default(true)->after('required_payment_amount');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            foreach (['invoice_purpose', 'payment_type', 'required_payment_amount', 'counts_toward_project_activation'] as $col) {
                if (Schema::hasColumn('invoices', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
