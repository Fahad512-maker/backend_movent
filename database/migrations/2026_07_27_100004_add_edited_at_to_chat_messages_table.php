<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lets a General Chat message be edited after sending — nullable timestamp,
// null means never edited. chat_messages has no timestamps() at all, so this
// mirrors the existing sent_at column's plain nullable-timestamp style.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            if (!Schema::hasColumn('chat_messages', 'edited_at')) {
                $table->timestamp('edited_at')->nullable()->after('sent_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            if (Schema::hasColumn('chat_messages', 'edited_at')) {
                $table->dropColumn('edited_at');
            }
        });
    }
};
