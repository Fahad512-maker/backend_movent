<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Public/anonymous invoice-page chatters (no `users` row, no `company_admins`
// row — sender_id and sender_admin_id both stay null for their messages)
// have no name to fall back to today. Every existing sales-chat surface
// (Api\User\SalesChatController, Api\Admin\SalesChatController,
// Api\Client\ChatController) already resolves a display name via
// `sender?->name ?? senderAdmin?->name ?? 'Someone'` — this adds the third
// leg of that chain.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('chat_messages') || Schema::hasColumn('chat_messages', 'guest_sender_name')) {
            return;
        }

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->string('guest_sender_name')->nullable()->after('sender_admin_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('chat_messages') && Schema::hasColumn('chat_messages', 'guest_sender_name')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->dropColumn('guest_sender_name');
            });
        }
    }
};
