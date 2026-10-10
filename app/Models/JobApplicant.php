<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobApplicant extends Model
{
    protected $fillable = [
        'recruitment_id', 'name', 'email', 'phone', 'resume_path', 'status', 'notes',
    ];

    public function recruitment(): BelongsTo
    {
        return $this->belongsTo(Recruitment::class);
    }
}
