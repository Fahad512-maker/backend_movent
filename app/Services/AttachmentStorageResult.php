<?php

namespace App\Services;

/**
 * What AttachmentStorageService::store() hands back — exactly the fields
 * every attachment table's `::create([...])` call needs to fill in its own
 * storage_type / google_drive_file_id / google_drive_folder_id / file_path
 * columns, whatever that table happens to name its own file_size / mime /
 * original-name columns.
 */
final class AttachmentStorageResult
{
    public function __construct(
        public readonly string $storageType,       // 'internal' | 'google_drive'
        public readonly ?string $filePath,          // set for 'internal' only
        public readonly ?string $disk,               // the disk $filePath was written to, for 'internal' only
        public readonly ?string $googleDriveFileId,   // set for 'google_drive' only
        public readonly ?string $googleDriveFolderId, // set for 'google_drive' only
        public readonly string $originalName,
        public readonly string $fileName,             // the stored/hash name (internal) or original name (drive)
        public readonly ?string $mimeType,
        public readonly int $sizeBytes,
    ) {}

    /**
     * The three storage-location columns every attachment table shares
     * verbatim (storage_type, google_drive_file_id, google_drive_folder_id)
     * plus file_path — spread this into every ::create([...]) call alongside
     * that table's own differently-named size/name/type columns.
     *
     * @return array{storage_type: string, file_path: ?string, google_drive_file_id: ?string, google_drive_folder_id: ?string}
     */
    public function locationAttributes(): array
    {
        return [
            'storage_type'           => $this->storageType,
            'file_path'              => $this->filePath,
            'google_drive_file_id'   => $this->googleDriveFileId,
            'google_drive_folder_id' => $this->googleDriveFolderId,
        ];
    }
}
