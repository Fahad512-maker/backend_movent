<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // invoices already has payment_token/token_expires_at (public payment
    // link) and created_by/sent_at (creator + sent timestamp) — those already
    // satisfy the "public_payment_token"/"email_sent_at" requirements from the
    // spec, so only the genuinely missing columns are added here.
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'lead_id')) {
                $table->foreignId('lead_id')->nullable()->after('client_id')->constrained('leads')->nullOnDelete();
            }
            if (!Schema::hasColumn('invoices', 'sent_by')) {
                $table->unsignedBigInteger('sent_by')->nullable()->after('created_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'lead_id')) {
                $table->dropForeign(['lead_id']);
                $table->dropColumn('lead_id');
            }
            if (Schema::hasColumn('invoices', 'sent_by')) {
                $table->dropColumn('sent_by');
            }
        });
    }
};
