<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fully additive, default false — every existing invoice keeps today's
// behavior untouched (a lead/project-linked invoice, or even a lead-less one
// created before this column existed, still lets
// App\Services\PaymentProjectStartService auto-create a Project on payment,
// exactly as it does now). Only a NEW invoice explicitly created via the
// "Without Project" mode sets this true, which is the one thing that service
// checks to skip project creation entirely.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('invoices', 'is_standalone')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->boolean('is_standalone')->default(false)->after('project_reference');
            });
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'is_standalone')) {
                $table->dropColumn('is_standalone');
            }
        });
    }
};
