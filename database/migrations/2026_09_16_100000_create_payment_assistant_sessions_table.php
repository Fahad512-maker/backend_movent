<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// State for the Client Portal's Payment Assistant — the rule-based invoice
// payment chat.
//
// It exists because the frontend must not be the source of truth for anything
// that decides a charge. Which company the client picked, which invoice they
// are on, and how far through the flow they are all live here, server-side, and
// every request re-derives the invoice and amount from these columns rather
// than from whatever the browser sends.
//
// One row per portal user: opening the assistant resumes the row, so closing
// the browser mid-flow (or coming back from the gateway's hosted page) lands
// back on the same invoice instead of starting over.
//
// Deliberately carries NO money and NO payment method. The amount is always
// recomputed from the invoice, and payment runs through the existing hosted
// checkout — this app never sees or stores a card.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_assistant_sessions')) {
            return;
        }

        Schema::create('payment_assistant_sessions', function (Blueprint $table) {
            $table->id();

            // The portal login. Unique: one live assistant session per client.
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            // Set once the company is chosen. client_id is the Client record
            // for THAT company — the pair is what scopes every later lookup, so
            // an invoice from another company can never be reached.
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();

            // The invoice currently in view. Re-validated against user +
            // company on every request; never trusted on its own.
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->string('state', 40)->default('company_selection');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_assistant_sessions');
    }
};
