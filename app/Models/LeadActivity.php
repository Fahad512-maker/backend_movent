<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadActivity extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'lead_id', 'company_id', 'causer_name',
        'type', 'description', 'meta',
    ];

    protected $casts = ['meta' => 'array'];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
