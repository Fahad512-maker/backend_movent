<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Staff-only "hide" for the per-project CLIENT chat (Api\Admin\ProjectClientChatController
// and Api\User\ProjectClientChatController) — a reversible visual suppression,
// distinct from is_deleted. A hidden message stays exactly as-is for the
// client (Api\Client\ProjectChatController never reads or exposes this
// column) — only Admin's and Seller's/invited PM's own chat views replace it
// with a placeholder. Shared across every staff viewer of the thread, not
// per-user: once Admin or the Seller hides a message, every staff viewer sees
// it hidden until someone unhides it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->boolean('hidden_for_staff')->default(false)->after('is_deleted');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('hidden_for_staff');
        });
    }
};
