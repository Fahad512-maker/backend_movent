<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Which CONVERSATION the Payment Assistant is currently on, for one portal
// login.
//
// The assistant's messages were never rows — they are rendered from the state
// each request returns and live only in the open browser tab. What IS persisted
// is (a) this session row, which is transient flow state, and (b) the permanent
// activity trail in `system_audit_logs` (action `payment_assistant.*`, written
// by PaymentAssistantService::audit()).
//
// "Clear History" therefore must not delete anything: it starts a NEW
// conversation. This column is that conversation's identifier — every audit row
// is stamped with the conversation_id that was live when it was written, so the
// old activity stays exactly where it was and stays attributable to the
// conversation it came from, while the client's visible thread starts empty.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payment_assistant_sessions')) {
            return;
        }

        Schema::table('payment_assistant_sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_assistant_sessions', 'conversation_id')) {
                // A uuid rather than an incrementing counter: it is only ever
                // an opaque grouping key for audit rows, never addressed by a
                // request, so there is nothing to guess and nothing to probe.
                $table->uuid('conversation_id')->nullable()->after('state');
            }

            if (!Schema::hasColumn('payment_assistant_sessions', 'conversation_started_at')) {
                $table->timestamp('conversation_started_at')->nullable()->after('conversation_id');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('payment_assistant_sessions')) {
            return;
        }

        Schema::table('payment_assistant_sessions', function (Blueprint $table) {
            foreach (['conversation_id', 'conversation_started_at'] as $column) {
                if (Schema::hasColumn('payment_assistant_sessions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
