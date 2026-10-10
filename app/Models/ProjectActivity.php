<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectActivity extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'project_id', 'company_id', 'causer_name',
        'type', 'description', 'meta',
    ];

    protected $casts = ['meta' => 'array'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
