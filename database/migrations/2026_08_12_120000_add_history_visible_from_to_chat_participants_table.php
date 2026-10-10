<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Backs the "invite the Project Manager into this project's client chat"
// flow (Api\User\ProjectClientChatController::invitePm()), where the Seller
// chooses how much of the conversation the PM may read:
//   NULL      — the whole thread, including everything said before they were
//               invited ("View all chat"). Also the value every pre-existing
//               participant keeps, so nothing changes for the Client/Seller.
//   timestamp — only messages sent at or after this moment ("Chat from now"),
//               i.e. the invite time.
// A timestamp rather than a boolean because it doubles as the record of WHEN
// the limited invite happened, and the read-side filter is then a plain
// `sent_at >= history_visible_from` with no extra join.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('chat_participants', 'history_visible_from')) {
            return;
        }

        Schema::table('chat_participants', function (Blueprint $table) {
            $table->timestamp('history_visible_from')->nullable()->after('joined_at');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('chat_participants', 'history_visible_from')) {
            return;
        }

        Schema::table('chat_participants', function (Blueprint $table) {
            $table->dropColumn('history_visible_from');
        });
    }
};
