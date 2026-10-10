<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The index of generated payment receipts — one row per CONFIRMED payment.
//
// It is deliberately NOT a second documents table. The receipt file itself is
// stored and served by the EXISTING document architecture: a `documents` row on
// the default (private) disk, linked to the client, flagged
// is_visible_to_client, which is what makes it appear in Client Portal →
// Documents with no change to that module's query at all.
//
// This table exists because `documents` can hold exactly ONE link
// (linked_to_type + linked_to_id), and a receipt genuinely belongs to four
// things at once — a company, a client, an invoice and a payment. Those four
// ids are what every authorization check in this feature is made of, so they
// have to be columns, not something inferred from a filename.
//
// Nothing about the money is copied here. Amounts, currency, status, method and
// gateway reference are read live off the invoice and payment rows at render
// time, so a receipt listing can never drift from the payments table the way a
// denormalized snapshot would.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_receipts')) {
            return;
        }

        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id();

            // Company isolation is enforced on this column directly rather
            // than only through the invoice, so a receipt query never has to
            // join to be safe.
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // Whose receipt it is. Nullable for the same reason invoices.client_id
            // is: an invoice can be raised against one-off customer details with
            // no Client record — such a payment simply gets no portal receipt.
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();

            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();

            // One receipt per payment. The unique key is the idempotency
            // guarantee: applyToInvoice() is explicitly safe to reach more than
            // once for the same payment, so the generator must be too.
            $table->foreignId('payment_id')->unique()->constrained('payments')->cascadeOnDelete();

            // The documents row that actually holds the file. Deleting the
            // document (the client-facing artifact) retires the receipt with it.
            $table->foreignId('document_id')->nullable()->constrained('documents')->cascadeOnDelete();

            // Mirrors documents.file_path — kept so the file can still be
            // located and cleaned up if the document row is ever detached.
            $table->string('file_path', 600)->nullable();
            $table->string('file_name', 255)->nullable();

            // The receipt number as issued on the payment at generation time.
            // The one value that is copied, because it identifies THIS artifact.
            $table->string('receipt_number', 60)->nullable();

            $table->timestamps();

            $table->index(['company_id', 'client_id']);
            $table->index(['company_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_receipts');
    }
};
