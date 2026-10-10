<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentAccess extends Model
{
    const UPDATED_AT = null;

    protected $table = 'document_access';

    protected $fillable = ['document_id', 'user_id', 'permission_level', 'granted_by', 'expires_at'];

    protected $casts = ['expires_at' => 'datetime'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
