<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Enforces "one project = one chat conversation" at the database level, not
// just in application code — must run AFTER the merge migration
// (2026_08_10_100000) so no project still has duplicate rows when this is
// added.
//
// A plain unique index on (company_id, linked_to_type, linked_to_id) is too
// broad: a single Client (or Lead) can legitimately have MULTIPLE
// chat_threads rows sharing that same triple but with different
// thread_type values (e.g. a Client's 'sales' thread and its 'client'
// Messages thread both point at the same linked_to_id) — confirmed live in
// this DB. So instead we add a STORED generated column that is non-null
// ONLY for the exact rows this feature owns (linked_to_type='Project' AND
// thread_type IN project_group/project_direct) and put the unique index on
// that — every other row's generated value is NULL, and MariaDB/MySQL both
// treat every NULL as distinct in a unique index, so nothing else on this
// table is affected.
return new class extends Migration
{
    private const COLUMN = 'project_chat_unique_key';
    private const INDEX = 'chat_threads_one_project_chat_unique';

    public function up(): void
    {
        if (!Schema::hasColumn('chat_threads', self::COLUMN)) {
            DB::statement(
                "ALTER TABLE chat_threads ADD COLUMN " . self::COLUMN . " BIGINT UNSIGNED " .
                "GENERATED ALWAYS AS (CASE WHEN linked_to_type = 'Project' AND thread_type IN ('project_group','project_direct') THEN linked_to_id ELSE NULL END) STORED"
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
