<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportAccess extends Model
{
    const CREATED_AT = null;

    protected $table = 'report_access';

    protected $fillable = ['user_id', 'report_key', 'can_view', 'can_export', 'set_by'];

    protected $casts = [
        'can_view'   => 'boolean',
        'can_export' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
