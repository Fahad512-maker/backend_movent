<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A Google Drive-backed attachment row has no local file_path — the file
 * lives in Drive, identified by google_drive_file_id instead (see
 * 2026_09_22_100100_add_storage_type_to_attachment_tables). Four of the six
 * migrated tables had file_path declared NOT NULL from when every row was
 * necessarily an internal one; this drops that constraint so a Drive row can
 * actually be saved. The other two (documents, deliverables) — and
 * projects.delivery_file_path — were already nullable and need no change.
 *
 * Raw DDL (not Blueprint::nullable()) because MySQL's `MODIFY COLUMN` needs
 * the column's exact existing type restated, and this project's convention
 * (see e.g. 2026_09_06_100000_add_subscription_lifecycle_statuses_to_
 * company_admins) is to do that with DB::statement rather than
 * Doctrine/DBAL, which this app does not have installed.
 */
return new class extends Migration
{
    /** @var array<int, array{0: string, 1: string}> [table, column type] */
    private const TARGETS = [
        ['project_attachments', 'VARCHAR(255)'],
        ['project_comment_attachments', 'VARCHAR(255)'],
        ['project_task_attachments', 'VARCHAR(255)'],
        ['project_delivery_submissions', 'VARCHAR(255)'],
    ];

    public function up(): void
    {
        foreach (self::TARGETS as [$table, $type]) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'file_path')) {
                DB::statement("ALTER TABLE `{$table}` MODIFY `file_path` {$type} NULL");
            }
        }
    }

    public function down(): void
    {
        // Not reversed: rolling back to NOT NULL would fail outright on any
        // database that by then holds a real Drive-backed (null file_path)
        // row, and silently corrupting/deleting that data to make the
        // rollback succeed is worse than leaving the column nullable.
    }
};
