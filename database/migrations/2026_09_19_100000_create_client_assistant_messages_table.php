<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The Client Portal assistant widget's visible transcript.
//
// The existing full-page Payment Assistant never persisted its messages — it
// rendered them from the state each response carried, which was fine for a
// screen you sit on. The widget is mounted in the portal LAYOUT and has to
// survive a route change and a hard refresh, so its thread has to live
// somewhere. One row per bubble, keyed by the same conversation_id the
// payment_assistant_sessions row already carries, so a conversation's messages
// and its permanent audit trail (system_audit_logs, action payment_assistant.*)
// line up under one id.
//
// Deliberately NOT another chat system: no threads, no participants, no
// attachments. Nobody but the signed-in client ever reads these rows, and
// "Clear History" rotates the conversation id exactly as it already does — the
// old rows stay, they simply stop being the live conversation.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_assistant_messages')) {
            return;
        }

        Schema::create('client_assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('conversation_id');
            // 'client' = what the person typed/clicked, 'assistant' = the reply.
            $table->enum('role', ['client', 'assistant']);
            $table->text('content');
            // The suggestions/actions that were rendered under an assistant
            // bubble, so a reloaded thread comes back with its buttons intact
            // instead of a wall of text the client can no longer act on.
            $table->json('payload')->nullable();
            $table->timestamps();

            // The only read this table ever serves: "this login's messages in
            // this conversation, oldest first".
            $table->index(['user_id', 'conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_assistant_messages');
    }
};
