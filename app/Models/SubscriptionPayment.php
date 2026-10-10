<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionPayment extends Model
{
    protected $fillable = [
        'admin_id', 'package_id', 'amount', 'currency', 'gateway',
        'gateway_ref', 'status', 'period_start', 'period_end', 'meta',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'period_start' => 'date',
        'period_end'   => 'date',
        'meta'         => 'array',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'admin_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
