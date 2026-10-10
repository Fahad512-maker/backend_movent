<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    const UPDATED_AT = null;

    protected $table = 'notifications';

    protected $fillable = [
        'user_id', 'recipient_admin_id', 'recipient_super_admin_id',
        'actor_user_id', 'actor_admin_id',
        'company_id', 'type', 'module', 'title', 'body', 'data',
        'entity_type', 'entity_id', 'url', 'is_read', 'read_at', 'cleared_at',
    ];

    protected $casts = [
        'data'       => 'array',
        'is_read'    => 'boolean',
        'read_at'    => 'datetime',
        'cleared_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // Present only when this row targets a Company Admin instead of a
    // staff User (see NotificationService — a row has exactly one of
    // user_id/recipient_admin_id set, never both).
    public function recipientAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'recipient_admin_id');
    }

    // Platform-level rows (see SuperAdminNotificationService) — the third
    // possible recipient, and the only one whose company_id may be null.
    public function recipientSuperAdmin(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'recipient_super_admin_id');
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function actorAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'actor_admin_id');
    }
}
