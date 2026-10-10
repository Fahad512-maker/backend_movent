<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The secret that lets a Lead open their own Sales Chat from an emailed link
 * without any login — the same shape invoices.payment_token already has for
 * the public payment page (2026_06_27_200001_add_payment_link_fields_to_invoices).
 *
 * It is the ONLY thing the public URL carries: /sales-chat/{token} never
 * exposes a lead id, so there is no id to increment and no authorization to
 * bypass by guessing one. Api\PublicLeadChatController resolves token -> lead
 * -> that lead's own 'sales' thread server-side and can reach nothing else.
 *
 * Unlike payment_token there is deliberately NO expiry column: a sales
 * conversation runs for as long as the deal does, and expiring the link would
 * drop the lead out of an active conversation with no way back in. Revoking
 * is explicit instead — Lead::regenerateChatToken() issues a fresh token
 * (instantly killing the old link) and revokeChatToken() nulls it outright.
 *
 * Existing leads are backfilled so "Resend chat invite" works on the whole
 * pipeline from day one, not just on leads created after this ships.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('leads', 'chat_token')) {
            Schema::table('leads', function (Blueprint $table) {
                // Unique: the token IS the credential, so two leads sharing
                // one would hand each the other's conversation. The index is
                // also what makes the public lookup a single keyed read.
                $table->string('chat_token', 64)->nullable()->unique()->after('email');
                // Null until the first invite actually goes out, so the Lead
                // screen can distinguish "never invited" from "invited on X".
                $table->timestamp('chat_invited_at')->nullable()->after('chat_token');
            });
        }

        $this->backfillTokens();
    }

    /**
     * Str::random() is not collision-proof by construction, and the unique
     * index would abort the whole migration on the one-in-astronomical
     * chance of a repeat mid-backfill. Generating into a set and checking
     * makes that impossible rather than improbable.
     */
    private function backfillTokens(): void
    {
        $taken = DB::table('leads')->whereNotNull('chat_token')->pluck('chat_token')->flip();

        DB::table('leads')->whereNull('chat_token')->orderBy('id')->chunkById(200, function ($leads) use (&$taken) {
            foreach ($leads as $lead) {
                do {
                    $token = Str::random(48);
                } while ($taken->has($token));

                $taken[$token] = true;

                DB::table('leads')->where('id', $lead->id)->update(['chat_token' => $token]);
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('leads', 'chat_token')) {
            return;
        }

        Schema::table('leads', function (Blueprint $table) {
            $table->dropUnique(['chat_token']);
            $table->dropColumn(['chat_token', 'chat_invited_at']);
        });
    }
};
