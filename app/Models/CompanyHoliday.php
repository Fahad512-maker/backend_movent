<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyHoliday extends Model
{
    protected $fillable = ['company_id', 'name', 'date', 'is_recurring_annual'];

    protected $casts = [
        'date'                 => 'date',
        'is_recurring_annual'  => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
