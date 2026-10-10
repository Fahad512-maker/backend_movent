<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectAttachment extends Model
{
    protected $fillable = [
        'company_id', 'project_id', 'uploaded_by_admin_id', 'uploaded_by_user_id',
        'original_name', 'file_name', 'file_path', 'file_type', 'file_size',
        'is_visible_to_client',
        // Google Drive company storage — see App\Services\AttachmentStorageService.
        'storage_type', 'google_drive_file_id', 'google_drive_folder_id',
    ];

    protected $casts = [
        'is_visible_to_client' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function uploadedByAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'uploaded_by_admin_id');
    }

    public function uploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
