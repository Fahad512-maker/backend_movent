<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Distinguishes Internal Project Chat messages from Client-facing ones
// within the SAME project thread — avoids fragmenting the existing single
// per-project Team Chat into two separate threads. Defaults to 'internal'
// so nothing already in the table becomes newly visible to a Seller.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            if (!Schema::hasColumn('chat_messages', 'visibility')) {
                $table->enum('visibility', ['internal', 'client'])->default('internal')->after('message_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            if (Schema::hasColumn('chat_messages', 'visibility')) {
                $table->dropColumn('visibility');
            }
        });
    }
};
