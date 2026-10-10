<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProjectFolderFile extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'folder_id', 'project_id', 'uploaded_by', 'file_name', 'file_path',
        'disk', 'file_type', 'file_extension', 'file_size_bytes', 'version',
        'previous_version_id', 'is_visible_to_client', 'description',
    ];

    protected $casts = [
        'is_visible_to_client' => 'boolean',
        'version'              => 'integer',
    ];

    public function folder(): BelongsTo
    {
        return $this->belongsTo(ProjectFolder::class, 'folder_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(ProjectFolderFile::class, 'previous_version_id');
    }
}
