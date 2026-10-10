<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeNote extends Model
{
    protected $fillable = [
        'company_id', 'employee_id', 'author_admin_id', 'body',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function authorAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'author_admin_id');
    }
}
