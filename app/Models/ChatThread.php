<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatThread extends Model
{
    protected $fillable = [
        'company_id', 'thread_type', 'title', 'linked_to_type', 'linked_to_id',
        'visibility', 'created_by', 'last_message_at', 'merged_into_thread_id',
    ];

    protected $casts = ['last_message_at' => 'datetime'];

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_thread_id');
    }

    /**
     * Where this conversation actually lives now.
     *
     * A paid invoice moves its pre-payment 'sales' conversation into the
     * project's thread (PaymentProjectStartService::migrateChatHistory()),
     * leaving the sales thread drained and pointing forward. Every reader and
     * writer must follow that pointer, or the two sides of the same
     * conversation end up on different threads — which is exactly what left
     * staff replying into a thread the client had stopped reading.
     *
     * Loop-guarded: a pointer cycle would otherwise hang the request, and this
     * runs on every chat read.
     */
    public function activeThread(): self
    {
        $thread = $this;
        $seen   = [$thread->id => true];

        while ($thread->merged_into_thread_id && !isset($seen[$thread->merged_into_thread_id])) {
            $next = static::find($thread->merged_into_thread_id);
            if (!$next) {
                break;
            }
            $seen[$next->id] = true;
            $thread = $next;
        }

        return $thread;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ChatParticipant::class, 'thread_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'thread_id');
    }
}
