<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Replaces 2026_08_12_120000's timestamp cutoff with a message-id watermark.
//
// The timestamp version leaked: both chat_participants.history_visible_from
// and chat_messages.sent_at are second-precision, so a message sent in the
// SAME second as a "chat from now" invite satisfied `sent_at >= cutoff` and
// reached the invited Project Manager even though it was said before them.
// Tightening the comparison to `>` would have swapped the leak for the
// opposite defect — a genuinely post-invite message in that same second would
// have been hidden from them forever.
//
// chat_messages.id is monotonic, so `id > history_from_message_id` is exact
// in both directions with no precision or timezone edge at all. NULL still
// means "this participant reads the whole thread"; the invite time itself is
// already recorded in chat_participants.joined_at, so nothing is lost by
// dropping the timestamp.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('chat_participants', 'history_from_message_id')) {
            Schema::table('chat_participants', function (Blueprint $table) {
                $table->unsignedBigInteger('history_from_message_id')->nullable()->after('joined_at');
            });
        }

        if (Schema::hasColumn('chat_participants', 'history_visible_from')) {
            Schema::table('chat_participants', function (Blueprint $table) {
                $table->dropColumn('history_visible_from');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('chat_participants', 'history_visible_from')) {
            Schema::table('chat_participants', function (Blueprint $table) {
                $table->timestamp('history_visible_from')->nullable()->after('joined_at');
            });
        }

        if (Schema::hasColumn('chat_participants', 'history_from_message_id')) {
            Schema::table('chat_participants', function (Blueprint $table) {
                $table->dropColumn('history_from_message_id');
            });
        }
    }
};
