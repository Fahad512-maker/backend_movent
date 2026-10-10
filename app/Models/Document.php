<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'uploaded_by', 'title', 'type', 'file_path', 'file_name',
        'file_size_bytes', 'version', 'parent_doc_id', 'linked_to_type',
        'linked_to_id', 'folder_id', 'is_shared', 'is_visible_to_client',
        // Google Drive company storage — see App\Services\AttachmentStorageService.
        'storage_type', 'google_drive_file_id', 'google_drive_folder_id',
    ];

    protected $casts = [
        'is_shared'             => 'boolean',
        'is_visible_to_client'  => 'boolean',
        'version'               => 'integer',
    ];

    public function folder(): BelongsTo
    {
        return $this->belongsTo(ProjectFolder::class, 'folder_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'parent_doc_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(Document::class, 'parent_doc_id');
    }

    public function access(): HasMany
    {
        return $this->hasMany(DocumentAccess::class);
    }

    public function downloadLogs(): HasMany
    {
        return $this->hasMany(DocumentDownloadLog::class);
    }
}
