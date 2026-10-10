<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Additive widening of task_activities.type, same pattern as
// 2026_07_20_100000_add_sales_type_to_chat_threads_table.php. 'commented'
// backs comment-posted History entries; 'revision' backs the
// deliverable/revision workflow ("who fixed it") — both previously had no
// representation in a task's History feed at all.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE task_activities MODIFY COLUMN type ENUM('created', 'updated', 'status_changed', 'assigned', 'completed', 'commented', 'revision') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE task_activities MODIFY COLUMN type ENUM('created', 'updated', 'status_changed', 'assigned', 'completed') NOT NULL");
    }
};
