<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskActivity extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'task_id', 'company_id', 'causer_name',
        'type', 'description', 'meta',
    ];

    protected $casts = ['meta' => 'array'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
