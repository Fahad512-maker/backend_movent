<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// invoices.created_by is a `users` FK, so an invoice raised by a Company
// Admin recorded NOTHING about who raised it — Admin isn't a `users` row.
// That was invisible until PaymentProjectStartService started assigning the
// auto-created project to whoever raised the invoice: with no admin id on
// the invoice there was nobody to carry over, exactly as with a sub-user's
// invoice before this.
//
// Same two-column shape projects/tasks already use for "acted on by either
// guard" (projects.created_by + projects.created_by_admin_id), so the
// Project row an invoice starts can mirror its own creator field-for-field.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('invoices', 'created_by_admin_id')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by_admin_id')->nullable()->after('created_by');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('invoices', 'created_by_admin_id')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('created_by_admin_id');
        });
    }
};
