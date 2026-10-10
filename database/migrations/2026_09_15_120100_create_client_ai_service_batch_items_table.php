<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The 10 individual suggested services within one client_ai_service_batches
// row. 'requested' is set when the client clicks "Request Service" on the
// dashboard; 'taken' is set only once a real invoice linked to this item
// (invoices.ai_service_batch_item_id) is paid or partially paid — clicking
// "Request Service" alone never sets 'taken'. See
// App\Services\ClientAiServiceBatchService.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_ai_service_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('client_ai_service_batches')->cascadeOnDelete();
            $table->string('key', 100);
            $table->string('name', 150);
            $table->string('category', 100)->default('IT');
            $table->string('summary', 500);
            $table->string('timeline', 100)->default('Upon review');
            $table->enum('status', ['available', 'requested', 'taken'])->default('available');
            $table->timestamp('requested_at')->nullable();
            $table->text('requested_notes')->nullable();
            $table->timestamp('taken_at')->nullable();
            $table->unsignedBigInteger('taken_by_invoice_id')->nullable();
            $table->timestamps();

            $table->foreign('taken_by_invoice_id')->references('id')->on('invoices')->nullOnDelete();

            $table->unique(['batch_id', 'key']);
            $table->index(['batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_ai_service_batch_items');
    }
};
