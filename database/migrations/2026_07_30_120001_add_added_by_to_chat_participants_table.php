<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Audit trail for the project-messenger Seller-add flow ("[Actor] added you
// to project chat for '[Project]'") — null when a Company Admin performed
// the add, since Admin isn't a `users` row (same convention as
// ProjectTeamMember.assigned_by).
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('chat_participants', 'added_by')) {
            Schema::table('chat_participants', function (Blueprint $table) {
                $table->foreignId('added_by')->nullable()->after('role')->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('chat_participants', 'added_by')) {
            Schema::table('chat_participants', function (Blueprint $table) {
                $table->dropConstrainedForeignId('added_by');
            });
        }
    }
};
