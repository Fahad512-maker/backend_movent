<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Deliverable extends Model
{
    protected $fillable = [
        'project_id', 'task_id', 'uploaded_by', 'title', 'file_path',
        'file_name', 'file_type', 'file_size_bytes', 'status', 'version',
        'delivered_at', 'submitted_at', 'approved_at',
        // Google Drive company storage — see App\Services\AttachmentStorageService.
        'storage_type', 'google_drive_file_id', 'google_drive_folder_id',
    ];

    protected $casts = [
        'delivered_at' => 'datetime',
        'submitted_at' => 'datetime',
        'approved_at'  => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(Revision::class);
    }
}
