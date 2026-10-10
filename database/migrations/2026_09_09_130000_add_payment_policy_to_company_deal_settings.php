<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The tenant's Payment Policy: may a client settle an invoice in instalments,
// or must every invoice be paid in full in one go?
//
// Deliberately SEPARATE from project_creation_trigger, which already lives on
// this table and answers a different question ("when does a payment start the
// project?"). The two look similar but are not the same setting: the existing
// trigger defaults to 'full_payment', and under that default a client can
// today still pay partially through the public link — the project simply
// waits for the balance. Folding the two together would have silently
// stopped every existing tenant from accepting part payments at all, without
// anyone changing a setting.
//
// So this defaults to 'partial_allowed' — exactly today's behaviour — and
// only takes effect when a Company Admin explicitly turns it on.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_deal_settings', function (Blueprint $table) {
            $table->string('payment_policy', 32)
                ->default('partial_allowed')
                ->after('project_creation_trigger');
        });
    }

    public function down(): void
    {
        Schema::table('company_deal_settings', function (Blueprint $table) {
            $table->dropColumn('payment_policy');
        });
    }
};
