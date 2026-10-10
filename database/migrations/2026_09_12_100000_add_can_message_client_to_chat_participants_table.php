<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Per-participant "may address this project's Client" grant, handed out by the
// project's own linked Seller (the "Upseller") when they bring ANOTHER company
// Seller into the project chat — see
// Api\User\ProjectMessengerController::isProjectUpseller() and send()'s
// visibility computation.
//
// Default false on purpose: being added to the chat is never itself permission
// to talk to the Client. Without this flag an added Seller's messages stay
// visibility='internal' however long they sit in the thread, which is exactly
// the old behaviour — so every pre-existing participant row is unaffected by
// this column arriving.
//
// Only ever read for a Seller. The project's OWN Seller doesn't need it (their
// seller_id match already grants them the Client, unchanged), and no other role
// is ever consulted for it.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('chat_participants', 'can_message_client')) {
            Schema::table('chat_participants', function (Blueprint $table) {
                $table->boolean('can_message_client')->default(false)->after('added_by');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('chat_participants', 'can_message_client')) {
            Schema::table('chat_participants', function (Blueprint $table) {
                $table->dropColumn('can_message_client');
            });
        }
    }
};
