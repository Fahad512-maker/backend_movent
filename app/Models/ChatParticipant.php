<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatParticipant extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'thread_id', 'user_id', 'role', 'added_by', 'last_read_at', 'joined_at', 'muted_at',
        'history_from_message_id', 'can_message_client',
    ];

    protected $casts = [
        'last_read_at' => 'datetime',
        'joined_at'    => 'datetime',
        'muted_at'     => 'datetime',
        // Only ever meaningful on a Seller's row in a PROJECT chat thread:
        // "this Seller was brought in by the project's own Seller (the
        // Upseller) and explicitly allowed to address the Client", which
        // promotes their plain, untagged message to visibility='client' the
        // same way the project's own Seller's already is. See the
        // 2026_09_12_100000 migration and
        // Api\User\ProjectMessengerController::sellerMayAddressClient().
        'can_message_client' => 'boolean',
        // NULL = this participant reads the whole thread. A message id limits
        // them to `id > history_from_message_id` — set when a Seller invites
        // the Project Manager into a project's client chat with "Chat from
        // now" instead of "View all chat". An id rather than a timestamp
        // because both sides of a timestamp comparison are second-precision
        // here; see the 2026_08_12_130000 migration.
        'history_from_message_id' => 'integer',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ChatThread::class, 'thread_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
