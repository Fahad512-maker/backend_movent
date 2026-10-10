<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds Google Drive storage fields to every existing attachment-bearing
 * table, rather than creating a second, parallel attachment system.
 *
 * Every table below already tracks a file (file_path + a size column); this
 * migration only adds:
 *
 *   storage_type            'internal' (default — every existing row lands
 *                            here, which is simply true: nothing was ever
 *                            stored anywhere else before this feature) or
 *                            'google_drive'.
 *   google_drive_file_id    Drive's own id for the file, null for internal.
 *   google_drive_folder_id  which per-module Drive folder it lives in.
 *
 * `file_path` keeps its exact existing meaning for an internal row and is
 * simply unused (null) for a Drive-backed one — no existing internal
 * attachment's path, URL or download route changes.
 *
 * Three tables (chat_messages, support_tickets, support_ticket_replies)
 * additionally gain `attachment_size`, because their single-attachment
 * columns were never tracked for size at all — without it those uploads
 * could never count toward the company's storage usage, silently
 * undercounting it forever.
 */
return new class extends Migration
{
    /** @var array<int, array{table: string, sizeColumn: ?string, after: string}> */
    private const TARGETS = [
        ['table' => 'project_attachments', 'sizeColumn' => null, 'after' => 'file_size'],
        ['table' => 'project_comment_attachments', 'sizeColumn' => null, 'after' => 'file_size'],
        ['table' => 'project_task_attachments', 'sizeColumn' => null, 'after' => 'file_size'],
        ['table' => 'documents', 'sizeColumn' => null, 'after' => 'file_size_bytes'],
        ['table' => 'deliverables', 'sizeColumn' => null, 'after' => 'file_size_bytes'],
        ['table' => 'project_delivery_submissions', 'sizeColumn' => null, 'after' => 'file_size'],
        // These three never had a size column at all for their single
        // attachment_path/attachment_name pair.
        ['table' => 'chat_messages', 'sizeColumn' => 'attachment_size', 'after' => 'attachment_name'],
        ['table' => 'support_tickets', 'sizeColumn' => 'attachment_size', 'after' => 'attachment_name'],
        ['table' => 'support_ticket_replies', 'sizeColumn' => 'attachment_size', 'after' => 'attachment_name'],
    ];

    public function up(): void
    {
        foreach (self::TARGETS as $t) {
            if (!Schema::hasTable($t['table'])) continue;

            Schema::table($t['table'], function (Blueprint $table) use ($t) {
                $after = $t['after'];

                if ($t['sizeColumn'] && !Schema::hasColumn($t['table'], $t['sizeColumn'])) {
                    $table->unsignedBigInteger($t['sizeColumn'])->nullable()->after($after);
                    $after = $t['sizeColumn'];
                }

                if (!Schema::hasColumn($t['table'], 'storage_type')) {
                    $table->enum('storage_type', ['internal', 'google_drive'])->default('internal')->after($after);
                }
                if (!Schema::hasColumn($t['table'], 'google_drive_file_id')) {
                    $table->string('google_drive_file_id')->nullable()->after('storage_type');
                }
                if (!Schema::hasColumn($t['table'], 'google_drive_folder_id')) {
                    $table->string('google_drive_folder_id')->nullable()->after('google_drive_file_id');
                }
            });
        }

        // The single "current delivery" file living directly on `projects`
        // (Api\User\ProjectController::submitDelivery(),
        // Api\Admin\ProjectController::uploadAndDeliver()) needs the same
        // three fields, prefixed to match its existing delivery_file_*
        // columns.
        if (Schema::hasTable('projects') && !Schema::hasColumn('projects', 'delivery_storage_type')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->enum('delivery_storage_type', ['internal', 'google_drive'])
                    ->default('internal')->after('delivery_file_size');
                $table->string('delivery_google_drive_file_id')->nullable()->after('delivery_storage_type');
                $table->string('delivery_google_drive_folder_id')->nullable()->after('delivery_google_drive_file_id');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TARGETS as $t) {
            if (!Schema::hasTable($t['table'])) continue;

            Schema::table($t['table'], function (Blueprint $table) use ($t) {
                $drop = ['storage_type', 'google_drive_file_id', 'google_drive_folder_id'];
                if ($t['sizeColumn']) $drop[] = $t['sizeColumn'];
                foreach ($drop as $col) {
                    if (Schema::hasColumn($t['table'], $col)) $table->dropColumn($col);
                }
            });
        }

        if (Schema::hasTable('projects') && Schema::hasColumn('projects', 'delivery_storage_type')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropColumn(['delivery_storage_type', 'delivery_google_drive_file_id', 'delivery_google_drive_folder_id']);
            });
        }
    }
};
