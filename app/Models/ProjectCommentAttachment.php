<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectCommentAttachment extends Model
{
    protected $fillable = [
        'company_id', 'comment_id', 'uploaded_by_admin_id', 'uploaded_by_user_id',
        'original_name', 'file_name', 'file_path', 'file_type', 'file_size',
        // Google Drive company storage — see App\Services\AttachmentStorageService.
        'storage_type', 'google_drive_file_id', 'google_drive_folder_id',
    ];

    public function comment(): BelongsTo
    {
        return $this->belongsTo(ProjectComment::class, 'comment_id');
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
