<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectFolder extends Model
{
    protected $fillable = [
        'project_id', 'parent_folder_id', 'name', 'folder_path',
        'is_system', 'is_visible_to_client', 'created_by', 'sort_order',
    ];

    protected $casts = [
        'is_system'            => 'boolean',
        'is_visible_to_client' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ProjectFolder::class, 'parent_folder_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ProjectFolder::class, 'parent_folder_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ProjectFolderFile::class, 'folder_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
