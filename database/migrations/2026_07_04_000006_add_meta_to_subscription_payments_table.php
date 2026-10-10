<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lets a payment row record WHAT was purchased (e.g. which module keys, for
// the new "upgrade modules" flow) without a new table — additive only, every
// existing renewal-payment row keeps meta = null.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('period_end');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
