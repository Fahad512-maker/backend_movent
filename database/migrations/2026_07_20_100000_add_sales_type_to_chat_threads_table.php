<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Additive widening of thread_type, same pattern as the earlier
// lead_activities.type enum change — existing values (direct, group,
// client, project, support) are preserved. 'sales' is the new Lead/Client
// -linked chat surface for Sellers (linked_to_type='Lead'|'Client',
// linked_to_id = that entity's id) — linked_to_type was defined on this
// table from day one but never actually populated until now.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE chat_threads MODIFY COLUMN thread_type ENUM('direct', 'group', 'client', 'project', 'support', 'sales') NOT NULL DEFAULT 'direct'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE chat_threads MODIFY COLUMN thread_type ENUM('direct', 'group', 'client', 'project', 'support') NOT NULL DEFAULT 'direct'");
    }
};
