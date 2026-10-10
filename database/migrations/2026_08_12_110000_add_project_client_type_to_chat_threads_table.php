<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Additive widening of thread_type, same pattern as
// 2026_07_30_120000_add_project_messenger_types_to_chat_threads_table.php.
// The new 'project_client' value backs the per-project CLIENT-FACING chat
// (Client <-> the project's Seller <-> Company Admin), reached from the
// Client Portal's project page — deliberately distinct from 'project_group'
// (the internal team messenger, which a client must never see) and from
// 'sales' (one conversation per Client, not per project).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE chat_threads MODIFY COLUMN thread_type ENUM('direct', 'group', 'client', 'project', 'support', 'sales', 'project_group', 'project_direct', 'project_client') NOT NULL DEFAULT 'direct'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE chat_threads MODIFY COLUMN thread_type ENUM('direct', 'group', 'client', 'project', 'support', 'sales', 'project_group', 'project_direct') NOT NULL DEFAULT 'direct'");
    }
};
