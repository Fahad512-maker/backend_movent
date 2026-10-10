<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One row per generated batch of AI-suggested services for a Client (see
// App\Services\ClientAiServiceBatchService). Exactly one 'active' row per
// client_id is an application-level invariant (enforced in
// ClientAiServiceBatchService), not a DB constraint — MySQL has no partial
// unique index, so this is just a supporting index for that lookup.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_ai_service_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            // Audit/debug trail only — the invoice whose payment triggered
            // this batch. Nullable so the batch row survives that invoice
            // being deleted.
            $table->unsignedBigInteger('source_invoice_id')->nullable();
            $table->enum('status', ['active', 'superseded'])->default('active');
            $table->enum('generated_via', ['ai', 'fallback'])->default('fallback');
            $table->timestamps();

            $table->foreign('source_invoice_id')->references('id')->on('invoices')->nullOnDelete();

            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_ai_service_batches');
    }
};
