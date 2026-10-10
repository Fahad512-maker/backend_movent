<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A card a Client Portal client has saved, so a later invoice can be paid with
// one confirmation instead of another trip through the gateway's hosted page.
//
// WHAT IS NOT HERE, and must never be: the card number, the CVV, the PIN, the
// full expiry-bearing PAN — nothing that could reconstruct the card. This app
// never receives any of it. The card is entered on the gateway's own hosted
// page and all that comes back are the opaque references below, plus the brand
// and last four digits, which exist only so the client can tell one saved card
// from another.
//
// Scoped per (client, company, gateway) rather than per client. Gateway
// credentials are configured per company, so a Stripe customer created under
// one company's account is meaningless to another's — a method saved for
// Company A must never be offered when paying Company B.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_payment_methods')) {
            return;
        }

        Schema::create('client_payment_methods', function (Blueprint $table) {
            $table->id();

            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // Which provider this reference belongs to ('stripe', 'paypal', …).
            // Matches CompanyPaymentGateway::GATEWAYS keys.
            $table->string('gateway', 30);

            // The gateway's own identifiers. Opaque to us — they are only ever
            // handed back to that same gateway, under that same company's
            // credentials.
            $table->string('gateway_customer_id', 191)->nullable();
            $table->string('payment_method_token', 191);

            // Display only, so the client can recognise the card. Never enough
            // to use it anywhere.
            $table->string('card_brand', 30)->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->unsignedSmallInteger('exp_month')->nullable();
            $table->unsignedSmallInteger('exp_year')->nullable();

            $table->boolean('is_default')->default(false);
            $table->timestamps();

            // The same card saved twice is one row — the gateway hands back a
            // stable token, so this is what makes re-paying idempotent rather
            // than growing a duplicate on every checkout.
            $table->unique(['client_id', 'company_id', 'gateway', 'payment_method_token'], 'client_payment_methods_unique');
            $table->index(['client_id', 'company_id', 'gateway']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_payment_methods');
    }
};
