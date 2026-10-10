<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Some deployments' database was seeded from a raw SQL snapshot that
        // already included this table before migrations tracking began —
        // skip rather than error if it's already there.
        if (Schema::hasTable('payments')) return;

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->decimal('amount', 12, 2);
            $table->enum('method', ['bank_transfer', 'cash', 'card', 'cheque', 'gateway'])->nullable();
            $table->string('gateway', 50)->nullable();
            $table->string('gateway_ref', 255)->nullable();
            $table->enum('status', ['confirmed', 'pending', 'failed', 'refunded'])->default('confirmed');
            $table->date('payment_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
