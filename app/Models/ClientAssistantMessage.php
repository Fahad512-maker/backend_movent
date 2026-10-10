<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bubble in the Client Portal assistant widget's thread.
 *
 * Written by Api\Client\AssistantController on every turn, read back when the
 * widget mounts so the conversation survives a route change or a refresh. It is
 * NOT a chat system — there is no thread, no participant and no second reader.
 * These rows belong to one portal login and are only ever served back to that
 * same login.
 */
class ClientAssistantMessage extends Model
{
    protected $fillable = ['user_id', 'conversation_id', 'role', 'content', 'payload'];

    protected $casts = ['payload' => 'array'];

    /** How many bubbles a single conversation may keep. */
    public const MAX_PER_CONVERSATION = 120;

    /** How many previous turns are replayed to the model for context. */
    public const AI_CONTEXT_TURNS = 8;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The live thread, oldest first.
     *
     * @return \Illuminate\Support\Collection<int, ClientAssistantMessage>
     */
    public static function thread(int $userId, ?string $conversationId)
    {
        if (!$conversationId) {
            return collect();
        }

        return static::where('user_id', $userId)
            ->where('conversation_id', $conversationId)
            ->orderBy('id')
            ->limit(self::MAX_PER_CONVERSATION)
            ->get();
    }

    /**
     * Append a bubble.
     *
     * Never throws: a transcript row failing to write must not cost the client
     * their answer, let alone their payment. The reply is already composed by
     * the time this runs.
     */
    public static function record(int $userId, ?string $conversationId, string $role, string $content, ?array $payload = null): void
    {
        if (!$conversationId || trim($content) === '') {
            return;
        }

        try {
            static::create([
                'user_id'         => $userId,
                'conversation_id' => $conversationId,
                'role'            => $role,
                'content'         => $content,
                'payload'         => $payload ?: null,
            ]);
        } catch (\Throwable) {
            // Transcript only — nothing downstream depends on it.
        }
    }
}
