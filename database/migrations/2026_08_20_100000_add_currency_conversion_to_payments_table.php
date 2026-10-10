<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('currency', 10)->nullable()->after('amount');
            $table->decimal('converted_amount', 12, 2)->nullable()->after('currency');
            $table->string('converted_currency', 10)->nullable()->after('converted_amount');
            $table->decimal('exchange_rate', 18, 8)->nullable()->after('converted_currency');
        });

        // Backfill original currency from each payment's own invoice, so
        // historical rows are just as query-able by currency as new ones.
        DB::statement('
            UPDATE payments
            INNER JOIN invoices ON invoices.id = payments.invoice_id
            SET payments.currency = invoices.currency
            WHERE payments.currency IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['currency', 'converted_amount', 'converted_currency', 'exchange_rate']);
        });
    }
};
