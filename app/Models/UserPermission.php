<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPermission extends Model
{
    const CREATED_AT = null;

    protected $fillable = [
        'user_id', 'module_key', 'can_view', 'can_create', 'can_edit',
        'can_delete', 'can_export', 'can_assign', 'can_approve', 'can_send', 'set_by',
    ];

    protected $casts = [
        'can_view'    => 'boolean',
        'can_create'  => 'boolean',
        'can_edit'    => 'boolean',
        'can_delete'  => 'boolean',
        'can_export'  => 'boolean',
        'can_assign'  => 'boolean',
        'can_approve' => 'boolean',
        'can_send'    => 'boolean',
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
