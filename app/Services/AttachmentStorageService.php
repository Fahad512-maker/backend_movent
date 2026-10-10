<?php

namespace App\Services;

use App\Models\GoogleDriveConnection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The one place every attachment upload in this CRM decides WHERE a file
 * actually goes, and the one place that later retrieves or deletes it again
 * — Section 5's "centralize attachment storage logic", Section 19's
 * AttachmentStorageService. No controller calls `$file->store()` or
 * `Storage::download()/delete()` directly anymore for a company attachment;
 * every one of them calls store()/download()/delete() here instead.
 *
 * store()'s decision, exactly as Section 5 specifies:
 *
 *   1. Would this file fit in the company's remaining internal quota
 *      (CompanyStorageService)? → store internally, EXACTLY as before this
 *      feature existed — same disk, same $file->store($folder) call, same
 *      file_path shape. Nothing about the internal path changes.
 *   2. It would not fit, but this company has a working Google Drive
 *      connection? → store there instead, transparently.
 *   3. It would not fit and there is no working connection? → return null.
 *      The caller turns that into the standard "storage limit reached,
 *      connect Google Drive" response (see errorResponse() below) — never a
 *      silent failure.
 *
 * WHAT NEVER MOVES: an attachment already on disk stays exactly where it is
 * forever. Connecting Drive only changes where the NEXT file that would not
 * fit goes — Section 6's "do not automatically move existing internal
 * files".
 */
class AttachmentStorageService
{
    // The per-file Laravel `max:` validation ceiling every upload endpoint
    // now uses — a "would this be silly to even attempt" sanity bound
    // against an absurd single request, NOT the real per-company decision.
    // PHP's own upload_max_filesize/post_max_size remain the true, final
    // ceiling regardless of this value; production's php.ini governs there
    // exactly as it always did.
    public const DRIVE_MAX_FILE_KB = 512000; // 500 MB

    /**
     * The per-file `max:` validation limit an upload endpoint should enforce.
     *
     * Every upload endpoint used to hard-code its own hard per-file cap
     * (10MB nearly everywhere, 20MB for Documents, 50MB for Deliverables/
     * Delivery) directly in its own `$request->validate()` call — a Laravel
     * validation rule that runs, and rejects, BEFORE the controller body (and
     * therefore before store() below) ever executes. That is what made a
     * company with Google Drive connected still get "file too large" for
     * anything over that cap: the request never survived long enough to reach
     * the code that would have routed it to Drive.
     *
     * The real, per-company answer to "does this file fit" already lives one
     * layer down, in store()'s CompanyStorageService::canStoreInternally()
     * check followed by the Google Drive fallback — correct today, and
     * unarguably the single source of truth for it (it is also what decides
     * the ANSWER, not just whether to allow the attempt). So this stays
     * generous UNCONDITIONALLY, Drive connected or not: a company with no
     * Drive and no room still gets exactly AttachmentStorageService::
     * errorResponse()'s "connect Google Drive" response — never a generic
     * Laravel validation message — because the request now actually reaches
     * that decision instead of being turned away earlier by a redundant,
     * differently-sized (10 vs 20 vs 50MB) per-endpoint rule that happened to
     * exist for historical reasons, not a real company-storage policy.
     *
     * $companyId/$localMaxKb are kept as parameters (currently unused) so
     * every call site already passing them needs no further change if a
     * future need for a per-company/per-endpoint distinction reappears.
     */
    public static function maxUploadKb(int $companyId, int $localMaxKb): int
    {
        return self::DRIVE_MAX_FILE_KB;
    }

    /** Does this company have a Google Drive connection that can actually be uploaded to right now? */
    public static function driveUsable(int $companyId): bool
    {
        return (bool) GoogleDriveConnection::where('company_id', $companyId)->first()?->isUsable();
    }

    /**
     * @param string $moduleKey one of GoogleDriveService::MODULE_FOLDER_LABELS' keys
     * @param string $internalFolder the exact folder store() would have used before this feature existed
     * @param string|null $disk null = default disk (private local); 'public' for a
     *        location whose existing behaviour serves files unauthenticated via
     *        PublicFile::url() (support tickets) — unchanged either way.
     */
    public static function store(
        UploadedFile $file,
        int $companyId,
        string $moduleKey,
        string $internalFolder,
        ?string $disk = null,
    ): ?AttachmentStorageResult {
        $size = $file->getSize();

        if (CompanyStorageService::canStoreInternally($companyId, $size)) {
            $path = $disk ? $file->store($internalFolder, $disk) : $file->store($internalFolder);

            return new AttachmentStorageResult(
                storageType: 'internal',
                filePath: $path,
                disk: $disk,
                googleDriveFileId: null,
                googleDriveFolderId: null,
                originalName: $file->getClientOriginalName(),
                fileName: $file->hashName(),
                mimeType: $file->getClientMimeType(),
                sizeBytes: $size,
            );
        }

        $connection = GoogleDriveConnection::where('company_id', $companyId)->first();
        if (!$connection || !$connection->isUsable()) {
            return null;
        }

        $drive = app(GoogleDriveService::class);
        $folderId = $drive->folderFor($connection, $moduleKey);
        if (!$folderId) {
            return null;
        }

        $originalName = $file->getClientOriginalName();
        $fileId = $drive->upload($connection, $file, $folderId, $originalName);
        if (!$fileId) {
            return null;
        }

        // The account's real quota just changed (Section 6: automatic
        // tracking after upload) — null out the persisted sync so the next
        // Settings > Storage view refetches instead of showing a stale
        // pre-upload figure for up to drive_quota_cache_minutes.
        $connection->update(['quota_synced_at' => null]);

        return new AttachmentStorageResult(
            storageType: 'google_drive',
            filePath: null,
            disk: null,
            googleDriveFileId: $fileId,
            googleDriveFolderId: $folderId,
            originalName: $originalName,
            fileName: $originalName,
            mimeType: $file->getClientMimeType(),
            sizeBytes: $size,
        );
    }

    /**
     * Retrieve a previously stored attachment, whichever storage it lives
     * in. The caller has already finished its own permission check before
     * reaching this — company isolation and per-record authorization are
     * that caller's job (see e.g. Api\User\ProjectAttachmentController::
     * download()), same as it always was for Storage::download().
     */
    public static function download(
        string $storageType,
        ?string $filePath,
        string $downloadName,
        ?string $mimeType,
        ?string $googleDriveFileId,
        ?int $companyId,
        ?string $disk = null,
    ): StreamedResponse {
        if ($storageType === 'google_drive') {
            if ($googleDriveFileId && $companyId) {
                $connection = GoogleDriveConnection::where('company_id', $companyId)->first();
                if ($connection) {
                    $stream = app(GoogleDriveService::class)->streamDownload($connection, $googleDriveFileId, $downloadName, $mimeType);
                    if ($stream) {
                        return $stream;
                    }
                }
            }

            abort(404, 'This file could not be reached in Google Drive.');
        }

        if (!$filePath) {
            abort(404, 'File not found.');
        }

        return $disk
            ? Storage::disk($disk)->download($filePath, $downloadName)
            : Storage::download($filePath, $downloadName);
    }

    /**
     * Remove a previously stored attachment. Only for a deliberate,
     * user-initiated delete of ONE attachment — see the class note on
     * GoogleDriveService::delete() for why this must never be called as a
     * side effect of disconnecting the company's whole Drive integration.
     */
    public static function delete(
        string $storageType,
        ?string $filePath,
        ?string $googleDriveFileId,
        ?int $companyId,
        ?string $disk = null,
    ): void {
        if ($storageType === 'google_drive') {
            if ($googleDriveFileId && $companyId) {
                $connection = GoogleDriveConnection::where('company_id', $companyId)->first();
                if ($connection) {
                    app(GoogleDriveService::class)->delete($connection, $googleDriveFileId);
                    // Same reasoning as store()'s cache invalidation above —
                    // a deleted file frees up real Drive quota immediately.
                    $connection->update(['quota_synced_at' => null]);
                }
            }

            return;
        }

        if (!$filePath) {
            return;
        }

        $disk ? Storage::disk($disk)->delete($filePath) : Storage::delete($filePath);
    }

    /**
     * The exact, consistent "storage full" response Section 2 specifies —
     * built once here so it reads identically from every one of the dozen
     * upload endpoints that can return it, rather than a dozen slightly
     * different wordings.
     *
     * `errors.error_code` follows the same convention
     * frontend/lib/axios.ts's interceptor already reads
     * ('subscription_required'), so the frontend's upload forms can detect
     * this specific case and show the "Connect Google Drive" action instead
     * of a generic failure toast — see components/storage/StorageLimitNotice.
     */
    public static function errorResponse(int $companyId): \Illuminate\Http\JsonResponse
    {
        $status = CompanyStorageService::status($companyId);

        return \App\Helpers\ApiResponse::error(
            'Your company storage limit has been reached. Connect Google Drive to continue storing attachments.',
            422,
            [
                'error_code'      => 'storage_limit_reached',
                'used_bytes'      => $status['used_bytes'],
                'limit_bytes'     => $status['limit_bytes'],
                'drive_connected' => $status['drive']['connected'],
            ]
        );
    }
}
