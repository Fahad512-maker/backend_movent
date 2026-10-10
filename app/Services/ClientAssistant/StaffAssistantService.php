<?php

namespace App\Services\ClientAssistant;

use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatThread;
use App\Models\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Which client conversations the staff / Company Admin widget may show.
 *
 * This class answers one question and answers it conservatively: has a CLIENT
 * started a conversation with this person, and if so, which ones. It is the
 * whole basis of the widget's visibility — the widget does not render at all
 * when this returns nothing, which is what stops it appearing for every seller
 * in the company.
 *
 * TWO RULES, neither invented here:
 *
 *   Staff — must be a chat_participants row of the thread. Exactly the filter
 *           Api\User\ClientChatController::index() already applies, so a Seller
 *           never reaches another Seller's client conversation.
 *   Admin — the client's company must be one this Admin owns. Exactly the
 *           scope Api\Admin\ClientChatController::client() already applies.
 *
 * AND ONE MORE, on top of both: the thread must be CLIENT-INITIATED. A
 * conversation a staff member opened themselves from the Client detail page is
 * still perfectly usable there — it simply does not switch this widget on,
 * because the widget exists to surface clients who came to YOU.
 *
 * Client-initiated is read from chat_threads.created_by, which is the client's
 * own portal `users` row when the hand-off created the thread
 * (ClientSellerChatService::startFromClient). Staff-started threads carry the
 * staff id there and Admin-started ones carry null, so the three origins stay
 * distinguishable without a schema change.
 */
class StaffAssistantService
{
    /**
     * The client conversations a STAFF member may see, client-initiated only.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function staffConversations(int $userId, int $companyId): Collection
    {
        if (!$companyId) {
            return collect();
        }

        $threads = ChatThread::where('thread_type', 'client')
            ->where('company_id', $companyId)
            ->where('linked_to_type', 'Client')
            ->whereNotNull('created_by')
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userId))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        return self::present($threads, $userId);
    }

    /**
     * The client conversations a COMPANY ADMIN may see, client-initiated only.
     *
     * Admin is never a chat_participants row anywhere in this app, so
     * membership cannot scope them — company ownership does, which is the
     * existing rule for the same threads on the Client detail page. Nothing
     * wider: a conversation in a company this Admin does not own is not
     * reachable, and one nobody has started is not shown.
     *
     * @param array<int, int> $companyIds
     * @return Collection<int, array<string, mixed>>
     */
    public static function adminConversations(array $companyIds): Collection
    {
        if (!$companyIds) {
            return collect();
        }

        $threads = ChatThread::where('thread_type', 'client')
            ->whereIn('company_id', $companyIds)
            ->where('linked_to_type', 'Client')
            ->whereNotNull('created_by')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        // No per-Admin last_read_at exists (no participant row), so unread is
        // not computed on this side rather than faked with a number that would
        // always be wrong.
        return self::present($threads, null);
    }

    /**
     * Shape a set of threads, keeping only the client-initiated ones.
     *
     * The client-initiated test needs the Client row (created_by must equal
     * that client's portal user), which is loaded here anyway for the name and
     * company — so the filter lives here rather than in the query above.
     *
     * @param \Illuminate\Support\Collection<int, ChatThread> $threads
     * @return Collection<int, array<string, mixed>>
     */
    private static function present($threads, ?int $viewerUserId): Collection
    {
        if ($threads->isEmpty()) {
            return collect();
        }

        $clients = Client::whereIn('id', $threads->pluck('linked_to_id')->unique())
            ->with('company:id,name')
            ->get()
            ->keyBy('id');

        return $threads
            ->map(function (ChatThread $t) use ($clients, $viewerUserId) {
                $client = $clients->get((int) $t->linked_to_id);

                if (!$client || !$client->user_id) {
                    return null;
                }

                // THE visibility rule. A thread opened by staff or by Admin is
                // not a client reaching out, so it never switches the widget on.
                if ((int) $t->created_by !== (int) $client->user_id) {
                    return null;
                }

                return [
                    'thread_id'    => (int) $t->id,
                    'client_id'    => (int) $client->id,
                    'client_name'  => $client->name,
                    'company_name' => $client->company?->name ?: $client->company_name,
                    'unread'       => $viewerUserId ? self::unreadCount($t, $viewerUserId) : 0,
                    'last_message' => self::lastMessagePreview($t),
                    'last_at'      => optional($t->last_message_at)->toIso8601String(),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Mark client conversations read for this staff member.
     *
     * Nothing on the staff side was writing `chat_participants.last_read_at`
     * for these threads, so an unread count could only ever grow — a seller who
     * had read everything still carried the badge. Opening the widget is the
     * moment they have seen what is waiting, so that is when this runs.
     *
     * $threadId narrows it to one conversation (used when a single one is
     * opened); omitted, it clears everything currently visible to them.
     *
     * The ids are re-derived from the SAME scoped query the list uses, never
     * taken from the request — so a thread id this user is not a participant of
     * simply matches nothing rather than being marked read on their behalf.
     *
     * @return int how many participant rows were touched
     */
    public static function markStaffRead(int $userId, int $companyId, ?int $threadId = null): int
    {
        if (!$companyId) {
            return 0;
        }

        $threadIds = ChatThread::where('thread_type', 'client')
            ->where('company_id', $companyId)
            ->where('linked_to_type', 'Client')
            ->whereNotNull('created_by')
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userId))
            ->when($threadId, fn ($q) => $q->where('id', $threadId))
            ->pluck('id');

        if ($threadIds->isEmpty()) {
            return 0;
        }

        return ChatParticipant::whereIn('thread_id', $threadIds)
            ->where('user_id', $userId)
            ->update(['last_read_at' => now()]);
    }

    /**
     * How many messages have arrived since this person last looked.
     *
     * Resolution note: both chat_messages.sent_at and
     * chat_participants.last_read_at are plain `timestamp` columns — one-second
     * granularity, no fractional part. The comparison is therefore strictly
     * `>`, which makes CLEARING reliable (everything already in the thread when
     * the widget was opened stops counting, including a message sent in that
     * same second) at the cost of one narrow case: a message that arrives in
     * the very same second the widget was opened is not counted, and waits for
     * the next one to raise the badge.
     *
     * That direction is deliberate. The badge failing to clear is the bug being
     * fixed here and would be visible on every open; the same-second miss needs
     * a client to write within one wall-clock second of the seller opening the
     * panel, and self-corrects on their next message. Making it exact would
     * mean tracking a last-read message id, i.e. a new column, which is not
     * worth a migration for a notification count.
     */
    private static function unreadCount(ChatThread $thread, int $userId): int
    {
        $lastRead = ChatParticipant::where('thread_id', $thread->id)
            ->where('user_id', $userId)
            ->value('last_read_at');

        return ChatMessage::where('thread_id', $thread->id)
            ->where('sender_id', '!=', $userId)
            ->where('is_deleted', false)
            ->when($lastRead, fn ($q) => $q->where('sent_at', '>', $lastRead))
            ->count();
    }

    /** A one-line preview, never showing what the thread itself would not. */
    private static function lastMessagePreview(ChatThread $thread): ?string
    {
        $message = ChatMessage::where('thread_id', $thread->id)
            ->where('is_deleted', false)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->first();

        return $message?->content ? Str::limit($message->content, 90) : null;
    }
}
