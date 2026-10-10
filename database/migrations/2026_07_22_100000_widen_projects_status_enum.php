<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Additive widening, same pattern as the earlier lead_activities.type /
// chat_threads.thread_type changes this session — existing values (planning,
// active, on_hold, completed, cancelled) are preserved. 'blocked' and
// 'closed' are new: 'blocked' covers the lifecycle's "Blocked" state,
// 'closed' is the new terminal state reached only via the Close Project
// action (after 'completed'), distinct from 'cancelled' (abandoned early).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE projects MODIFY COLUMN status ENUM('planning', 'active', 'on_hold', 'blocked', 'completed', 'cancelled', 'closed') NOT NULL DEFAULT 'planning'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE projects MODIFY COLUMN status ENUM('planning', 'active', 'on_hold', 'completed', 'cancelled') NOT NULL DEFAULT 'planning'");
    }
};
