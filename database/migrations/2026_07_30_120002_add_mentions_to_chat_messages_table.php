<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Backs @mention support in the project-wise messenger. Mention candidates
// are restricted to the sending thread's current participants (see
// ProjectMessengerController::send()), so this list is always a subset of
// chat_participants.user_id for the same thread — no separate Seller
// tag-rule check is needed beyond that membership constraint.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('chat_messages', 'mentions')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->json('mentions')->nullable()->after('content');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('chat_messages', 'mentions')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->dropColumn('mentions');
            });
        }
    }
};
