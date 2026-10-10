<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Enforces "one project = one CLIENT-facing chat conversation" at the database
// level, exactly mirroring 2026_08_10_100100's generated-column trick for the
// internal project_group thread.
//
// It deliberately gets its OWN generated column rather than being folded into
// project_chat_unique_key: a project legitimately has BOTH an internal
// 'project_group' thread and this 'project_client' thread, and both share the
// same (company_id, linked_to_id) pair — adding 'project_client' to the
// existing column's CASE would make those two rows collide on the existing
// unique index. Every row that isn't a project_client thread generates NULL
// here, and MariaDB/MySQL treat every NULL as distinct in a unique index, so
// nothing else on this table is affected.
return new class extends Migration
{
    private const COLUMN = 'project_client_chat_unique_key';
    private const INDEX = 'chat_threads_one_project_client_chat_unique';

    public function up(): void
    {
        if (!Schema::hasColumn('chat_threads', self::COLUMN)) {
            DB::statement(
                "ALTER TABLE chat_threads ADD COLUMN " . self::COLUMN . " BIGINT UNSIGNED " .
                "GENERATED ALWAYS AS (CASE WHEN linked_to_type = 'Project' AND thread_type = 'project_client' THEN linked_to_id ELSE NULL END) STORED"
            );
        }

        if (!$this->indexExists()) {
            Schema::table('chat_threads', function ($table) {
                $table->unique(['company_id', self::COLUMN], self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists()) {
            Schema::table('chat_threads', function ($table) {
                $table->dropUnique(self::INDEX);
            });
        }

        if (Schema::hasColumn('chat_threads', self::COLUMN)) {
            Schema::table('chat_threads', function ($table) {
                $table->dropColumn(self::COLUMN);
            });
        }
    }

    private function indexExists(): bool
    {
        $rows = DB::select(
            'SELECT COUNT(*) as cnt FROM information_schema.STATISTICS WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            ['chat_threads', self::INDEX]
        );

        return ($rows[0]->cnt ?? 0) > 0;
    }
};
