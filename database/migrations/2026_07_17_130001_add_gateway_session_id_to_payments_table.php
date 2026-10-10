<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Correlates a hosted-checkout session/order (Stripe Checkout Session id,
    // PayPal Order id, Authorize.net Accept Hosted transaction ref) created at
    // payment-initiation time with the pending Payment row, so the webhook —
    // which arrives after the fact with no other shared identifier — can find
    // and finalize the right row.
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('gateway_session_id', 255)->nullable()->unique()->after('gateway_ref');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('gateway_session_id');
        });
    }
};
