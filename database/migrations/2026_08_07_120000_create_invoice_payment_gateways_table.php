<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Which specific company_payment_gateways account(s) a given invoice accepts.
// An invoice with zero rows here means "no explicit selection was ever made"
// — every read path treats that as the legacy fallback (tenant's per-type
// defaults), so invoices created before this feature keep working unchanged.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoice_payment_gateways')) {
            return;
        }

        Schema::create('invoice_payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_gateway_id')->constrained('company_payment_gateways')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['invoice_id', 'company_gateway_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payment_gateways');
    }
};
