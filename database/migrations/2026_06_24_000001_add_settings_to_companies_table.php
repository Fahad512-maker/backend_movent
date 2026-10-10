<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('invoice_prefix', 20)->default('INV')->after('currency');
            $table->decimal('invoice_tax_rate', 5, 2)->default(0)->after('invoice_prefix');
            $table->unsignedTinyInteger('invoice_payment_terms')->default(30)->after('invoice_tax_rate');
            $table->text('invoice_notes')->nullable()->after('invoice_payment_terms');
            $table->string('bank_name', 100)->nullable()->after('invoice_notes');
            $table->string('bank_account_name', 150)->nullable()->after('bank_name');
            $table->string('bank_account_number', 50)->nullable()->after('bank_account_name');
            $table->string('bank_iban', 50)->nullable()->after('bank_account_number');
            $table->string('bank_swift', 20)->nullable()->after('bank_iban');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'invoice_prefix', 'invoice_tax_rate', 'invoice_payment_terms', 'invoice_notes',
                'bank_name', 'bank_account_name', 'bank_account_number', 'bank_iban', 'bank_swift',
            ]);
        });
    }
};
