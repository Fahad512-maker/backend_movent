<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesTarget extends Model
{
    protected $fillable = [
        'company_id', 'user_id', 'period_start', 'period_end',
        'target_value', 'target_deals', 'created_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end'   => 'date',
        'target_value' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
