<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Idempotency ledger for gateway webhooks — Stripe/PayPal/Authorize.net
    // all retry webhook delivery on anything but a clean 2xx response, so the
    // same event can arrive more than once. Recording each processed event id
    // here (unique per gateway) lets the webhook handler safely no-op a
    // duplicate delivery instead of double-applying a payment.
    public function up(): void
    {
        Schema::create('payment_gateway_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 30);
            $table->string('event_id', 255);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['gateway', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_webhook_events');
    }
};
