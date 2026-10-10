<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Per-participant mute preference for General Chat — a personal setting, not
// a permission, so it lives on the participant row rather than needing any
// new permission key.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_participants', function (Blueprint $table) {
            if (!Schema::hasColumn('chat_participants', 'muted_at')) {
                $table->timestamp('muted_at')->nullable()->after('last_read_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('chat_participants', function (Blueprint $table) {
            if (Schema::hasColumn('chat_participants', 'muted_at')) {
                $table->dropColumn('muted_at');
            }
        });
    }
};
