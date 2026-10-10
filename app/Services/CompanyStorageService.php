<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\Deliverable;
use App\Models\Document;
use App\Models\GoogleDriveConnection;
use App\Models\Project;
use App\Models\ProjectAttachment;
use App\Models\ProjectCommentAttachment;
use App\Models\ProjectDeliverySubmission;
use App\Models\ProjectTaskAttachment;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;

/**
 * The single source of truth for "how much internal attachment storage has
 * this company used" — and the only class in this app that is allowed to
 * answer that question. Every location that uploads a file
 * (Project/Comment/Task attachments, Project Documents, Deliverables,
 * Project Delivery, Chat, Support tickets) asks THIS service before writing,
 * rather than each computing its own partial total; see
 * App\Services\AttachmentStorageService, which is the only caller of
 * canStore()/getStorageStatus() in the write path.
 *
 * Ten "sources" below map to every place a company's files can physically
 * live, one line each. Adding an eleventh (a genuinely new attachment
 * location) means adding one line here, not touching every other module's
 * math — that is the whole point of centralizing this.
 *
 * WHAT COUNTS: only rows with storage_type = 'internal'. A file already
 * moved to Google Drive is no longer occupying the company's internal
 * quota — that is the entire mechanism by which connecting Drive "frees up"
 * room, without deleting or re-uploading anything that was already there.
 *
 * `documents` is filtered to linked_to_type = 'project' — that table is also
 * used by the HR and Compliance modules (Employee documents, Compliance
 * documents), which are their own systems with their own rules and are
 * deliberately NOT part of this quota; only Project Documents are.
 */
class CompanyStorageService
{
    public static function limitBytes(): int
    {
        return (int) (config('services.company_storage.limit_mb', 10) * 1024 * 1024);
    }

    public static function limitMb(): float
    {
        return (float) config('services.company_storage.limit_mb', 10);
    }

    /** Bytes of INTERNAL storage this company has used, across every attachment location. */
    public static function usedBytes(int $companyId): int
    {
        $total = 0;

        $total += (int) ProjectAttachment::where('company_id', $companyId)
            ->where('storage_type', 'internal')->sum('file_size');

        $total += (int) ProjectCommentAttachment::where('company_id', $companyId)
            ->where('storage_type', 'internal')->sum('file_size');

        $total += (int) ProjectTaskAttachment::where('company_id', $companyId)
            ->where('storage_type', 'internal')->sum('file_size');

        $total += (int) Document::where('company_id', $companyId)
            ->where('linked_to_type', 'project')
            ->where('storage_type', 'internal')->sum('file_size_bytes');

        // deliverables carries no company_id of its own — scoped via its
        // project, same join every controller already uses to reach it.
        $total += (int) Deliverable::whereIn('project_id', Project::where('company_id', $companyId)->select('id'))
            ->where('storage_type', 'internal')->sum('file_size_bytes');

        // The "current" delivery file living directly on `projects` — a
        // single-row-per-project pointer, not a table of its own.
        $total += (int) Project::where('company_id', $companyId)
            ->where('delivery_storage_type', 'internal')->sum('delivery_file_size');

        // Delivery HISTORY — deliverToClient()/uploadAndDeliver() archive a
        // snapshot here each time a delivery goes out; each archived file is
        // its own real object on disk (never overwritten), so it counts
        // separately from the "current" pointer above.
        $total += (int) ProjectDeliverySubmission::whereIn('project_id', Project::where('company_id', $companyId)->select('id'))
            ->where('storage_type', 'internal')->sum('file_size');

        $total += (int) ChatMessage::whereIn(
                'thread_id',
                \App\Models\ChatThread::where('company_id', $companyId)->select('id')
            )
            ->where('storage_type', 'internal')
            ->whereNotNull('attachment_path')
            ->sum('attachment_size');

        $total += (int) SupportTicket::where('company_id', $companyId)
            ->where('storage_type', 'internal')
            ->whereNotNull('attachment_path')->sum('attachment_size');

        $total += (int) SupportTicketReply::whereIn('ticket_id', SupportTicket::where('company_id', $companyId)->select('id'))
            ->where('storage_type', 'internal')
            ->whereNotNull('attachment_path')->sum('attachment_size');

        return $total;
    }

    public static function remainingBytes(int $companyId): int
    {
        return max(0, self::limitBytes() - self::usedBytes($companyId));
    }

    /** May a file of $sizeBytes be added to internal storage without crossing the limit? */
    public static function canStoreInternally(int $companyId, int $sizeBytes): bool
    {
        return self::usedBytes($companyId) + $sizeBytes <= self::limitBytes();
    }

    /**
     * Everything Company Admin > Settings > Storage, and the storage-full
     * decision in AttachmentStorageService, need in one call.
     *
     * @return array{limit_bytes: int, limit_mb: float, used_bytes: int, remaining_bytes: int, used_percentage: float, is_full: bool, warning_level: string, drive: array{connected: bool, email: ?string, status: ?string}}
     */
    public static function status(int $companyId): array
    {
        $limit = self::limitBytes();
        $used  = self::usedBytes($companyId);
        $isFull = $used >= $limit;
        $percentage = $limit > 0 ? min(100, round($used / $limit * 100, 1)) : 100.0;

        $connection = GoogleDriveConnection::where('company_id', $companyId)->first();

        return [
            'limit_bytes'     => $limit,
            'limit_mb'        => self::limitMb(),
            'used_bytes'      => $used,
            'remaining_bytes' => max(0, $limit - $used),
            'used_percentage' => $percentage,
            'is_full'         => $isFull,
            'warning_level'   => self::warningLevel($percentage, $isFull),
            'drive'           => [
                'connected' => (bool) $connection?->isConnected(),
                'email'     => $connection?->google_account_email,
                'status'    => $connection?->status,
            ],
        ];
    }

    /**
     * Normal / High / Critical / Full — Section 11 of the Storage Tracking
     * spec. Thresholds come from config('services.company_storage'), which
     * just formalizes the single 80% color-break that already existed only
     * as a hardcoded frontend constant (StorageSettingsPanel.tsx) — reused
     * here for both the internal quota card and the real Google Drive quota
     * card, so the two never disagree on what "High" means.
     */
    public static function warningLevel(float $usedPercentage, bool $isFull): string
    {
        if ($isFull || $usedPercentage >= 100) {
            return 'full';
        }

        if ($usedPercentage >= (float) config('services.company_storage.warning_critical_percent', 95)) {
            return 'critical';
        }

        if ($usedPercentage >= (float) config('services.company_storage.warning_high_percent', 80)) {
            return 'high';
        }

        return 'normal';
    }

    /**
     * The connected Google account's REAL storage quota (Section 1/2/4) —
     * cached on the connection row for `drive_quota_cache_minutes` (default
     * 10) so an ordinary Settings page view never calls Google's API more
     * than once per that window (Section 13). `$force` bypasses the TTL for
     * the explicit "Refresh Storage" button, but still refuses to hammer
     * Google on a double-click: a sync less than 30 seconds old is served
     * as-is even when forced.
     *
     * On any fetch failure, the last persisted values are returned with
     * `stale: true` rather than an error — Section 12: "preserve the last
     * valid storage information where appropriate". A connection that has
     * NEVER synced successfully returns `null` values with `stale: true` and
     * no crash.
     *
     * @return array{total_bytes: ?int, used_bytes: ?int, synced_at: ?\Illuminate\Support\Carbon, stale: bool}
     */
    public static function refreshDriveQuota(GoogleDriveConnection $connection, bool $force = false): array
    {
        $ttlMinutes = (int) config('services.company_storage.drive_quota_cache_minutes', 10);
        $syncedAt = $connection->quota_synced_at;

        $fresh = $syncedAt && $syncedAt->gt(now()->subMinutes($ttlMinutes));
        $tooSoonToForce = $syncedAt && $syncedAt->gt(now()->subSeconds(30));

        // Forced (the "Refresh Storage" button) skips the passive TTL, but a
        // sync under 30s old is served as-is regardless — protects against a
        // double-click hammering Google, never against a genuinely stale
        // page the admin is deliberately asking to update.
        $serveCached = $force ? $tooSoonToForce : $fresh;

        if ($serveCached || !$connection->isUsable()) {
            return [
                'total_bytes' => $connection->quota_total_bytes,
                'used_bytes'  => $connection->quota_used_bytes,
                'synced_at'   => $syncedAt,
                'stale'       => !$serveCached, // i.e. not usable at all right now
            ];
        }

        $quota = app(GoogleDriveService::class)->accountStorageQuota($connection);

        if (!$quota) {
            return [
                'total_bytes' => $connection->quota_total_bytes,
                'used_bytes'  => $connection->quota_used_bytes,
                'synced_at'   => $syncedAt,
                'stale'       => true,
            ];
        }

        $connection->update([
            'quota_total_bytes' => $quota['total_bytes'],
            'quota_used_bytes'  => $quota['used_bytes'],
            'quota_synced_at'   => now(),
        ]);

        return [
            'total_bytes' => $quota['total_bytes'],
            'used_bytes'  => $quota['used_bytes'],
            'synced_at'   => $connection->quota_synced_at,
            'stale'       => false,
        ];
    }

    /**
     * The shape Company Admin > Settings > Storage's "Google Drive Storage"
     * card needs — real account quota, not this CRM's internal one.
     *
     * @return array{connected: bool, unlimited: bool, total_bytes: ?int, used_bytes: int, available_bytes: ?int, used_percentage: ?float, last_synced_at: ?string, warning_level: string, stale: bool}
     */
    public static function driveQuotaStatus(GoogleDriveConnection $connection, bool $force = false): array
    {
        $quota = self::refreshDriveQuota($connection, $force);
        $total = $quota['total_bytes'];
        $used  = (int) ($quota['used_bytes'] ?? 0);
        $unlimited = $quota['synced_at'] !== null && $total === null;

        $percentage = ($total && $total > 0) ? min(100, round($used / $total * 100, 1)) : null;
        $isFull = $percentage !== null && $percentage >= 100;

        return [
            'connected'       => true,
            'unlimited'       => $unlimited,
            'total_bytes'     => $total,
            'used_bytes'      => $used,
            'available_bytes' => $total !== null ? max(0, $total - $used) : null,
            'used_percentage' => $percentage,
            'last_synced_at'  => $quota['synced_at']?->toIso8601String(),
            'warning_level'   => $percentage !== null ? self::warningLevel($percentage, $isFull) : 'normal',
            'stale'           => $quota['stale'],
        ];
    }

    /**
     * A breakdown by location, for the "Internal Files / Google Drive Files"
     * panel Section 9 asks for, plus the "File Count / Total Size" per
     * category the Storage Tracking spec's Section 3 asks for.
     * `total_bytes` = internal_bytes + drive_bytes: a file's SIZE never
     * changes when it moves to Drive (only WHERE it lives, and therefore
     * which quota it counts against — see the class note above), so summing
     * both is a correct total regardless of storage_type. Not used by any
     * quota decision — display only, so a slower, more legible query shape
     * here is fine.
     *
     * @return array<int, array{label: string, internal_bytes: int, drive_bytes: int, total_bytes: int, internal_files: int, drive_files: int}>
     */
    public static function breakdown(int $companyId): array
    {
        $rows = [];

        $add = function (string $label, $query, string $sizeColumn, string $storageTypeColumn = 'storage_type') use (&$rows) {
            $internal = (clone $query)->where($storageTypeColumn, 'internal');
            $drive    = (clone $query)->where($storageTypeColumn, 'google_drive');

            $internalBytes = (int) $internal->sum($sizeColumn);
            $driveBytes    = (int) (clone $drive)->sum($sizeColumn);

            $rows[] = [
                'label'           => $label,
                'internal_bytes'  => $internalBytes,
                'drive_bytes'     => $driveBytes,
                'total_bytes'     => $internalBytes + $driveBytes,
                'internal_files'  => (clone $internal)->count(),
                'drive_files'     => (clone $drive)->count(),
            ];
        };

        $add('Project Attachments', ProjectAttachment::where('company_id', $companyId), 'file_size');
        $add('Project Comments', ProjectCommentAttachment::where('company_id', $companyId), 'file_size');
        $add('Task Attachments', ProjectTaskAttachment::where('company_id', $companyId), 'file_size');
        $add('Project Documents', Document::where('company_id', $companyId)->where('linked_to_type', 'project'), 'file_size_bytes');
        $add('Deliverables', Deliverable::whereIn('project_id', Project::where('company_id', $companyId)->select('id')), 'file_size_bytes');
        $add('Current Delivery File', Project::where('company_id', $companyId)->whereNotNull('delivery_file_size'), 'delivery_file_size', 'delivery_storage_type');
        $add('Project Delivery (history)', ProjectDeliverySubmission::whereIn('project_id', Project::where('company_id', $companyId)->select('id')), 'file_size');
        $add('Chat Attachments', ChatMessage::whereIn('thread_id', \App\Models\ChatThread::where('company_id', $companyId)->select('id'))->whereNotNull('attachment_path'), 'attachment_size');
        $add('Support Tickets', SupportTicket::where('company_id', $companyId)->whereNotNull('attachment_path'), 'attachment_size');
        $add('Support Ticket Replies', SupportTicketReply::whereIn('ticket_id', SupportTicket::where('company_id', $companyId)->select('id'))->whereNotNull('attachment_path'), 'attachment_size');

        return $rows;
    }
}
