<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lets a message in a 'client' thread (Api\User\ClientChatController /
// Api\Admin\ClientChatController) be hidden from one or more of that
// thread's OTHER staff participants — specifically: a Seller loops a PM
// into their chat with a client (see addParticipant()), but some messages
// should stay between the Seller and the client only, invisible to the PM,
// without splitting into a separate thread. Never used to hide a message
// from the client themselves (validated server-side, not enforced by the
// column) or from its own sender. Same array-of-ids shape as `mentions`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            if (!Schema::hasColumn('chat_messages', 'hidden_from_user_ids')) {
                $table->json('hidden_from_user_ids')->nullable()->after('mentions');
            }
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            if (Schema::hasColumn('chat_messages', 'hidden_from_user_ids')) {
                $table->dropColumn('hidden_from_user_ids');
            }
        });
    }
};
