<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentDownloadLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['document_id', 'user_id', 'ip_address', 'downloaded_at'];

    protected $casts = ['downloaded_at' => 'datetime'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
