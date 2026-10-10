<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Additive widening of thread_type, same pattern as
// 2026_07_20_100000_add_sales_type_to_chat_threads_table.php. The new
// 'project_group'/'project_direct' values back the project-wise messenger
// feature and are deliberately distinct from the pre-existing 'project'
// value (the older, dormant single-thread-per-project ProjectChatController)
// so the two systems never collide on the same rows.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE chat_threads MODIFY COLUMN thread_type ENUM('direct', 'group', 'client', 'project', 'support', 'sales', 'project_group', 'project_direct') NOT NULL DEFAULT 'direct'");

        if (!Schema::hasColumn('chat_threads', 'visibility')) {
            Schema::table('chat_threads', function ($table) {
                $table->enum('visibility', ['internal', 'seller_facing', 'client_facing'])->nullable()->after('linked_to_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('chat_threads', 'visibility')) {
            Schema::table('chat_threads', function ($table) {
                $table->dropColumn('visibility');
            });
        }

        DB::statement("ALTER TABLE chat_threads MODIFY COLUMN thread_type ENUM('direct', 'group', 'client', 'project', 'support', 'sales') NOT NULL DEFAULT 'direct'");
    }
};
