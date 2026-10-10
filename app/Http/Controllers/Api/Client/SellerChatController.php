<?php

namespace App\Http\Controllers\Api\Client;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatThread;
use App\Models\Client;
use App\Models\User;
use App\Services\ClientSellerChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The CLIENT end of the per-client Direct Chat (`thread_type='client'`).
 *
 * The staff and Admin ends of this conversation already exist and are untouched
 * (Api\User\ClientChatController, Api\Admin\ClientChatController). Only the
 * client's own end was missing — Api\Client\ChatController was removed on
 * 2026-09-11 — which is why a seller could reply to a client who had nowhere to
 * read it. This restores that end, narrowly: read your own conversations, and
 * send into them. Nothing else.
 *
 * AUTHORIZATION, in one place and on every request:
 *
 *   thread_type = 'client'                 — never a project, sales or internal thread
 *   linked_to_id ∈ this login's own Clients — never another client's conversation
 *   company_id  = that Client's company     — never across companies
 *   participant = this login's user id      — never a room they were not put in
 *
 * All four are re-derived from $request->user() on every call. A thread id in
 * the URL is only ever a filter, never a grant: a crafted id fails to resolve
 * rather than resolving to someone else's conversation.
 *
 * Deliberately NOT module-gated. This is the client's conversation with the
 * person who sold to them, not one of the portal sections Company Admin
 * switches on and off — same reasoning as the portal's notification endpoints.
 */
class SellerChatController extends Controller
{
    /**
     * Every Client record this portal login owns.
     *
     * @return \Illuminate\Support\Collection<int, Client>
     */
    private function ownClients(Request $request)
    {
        return Client::where('user_id', $request->user()->id)
            ->where('portal_access', true)
            ->with('company:id,name')
            ->get();
    }

    /**
     * A thread this login is genuinely in, plus the Client row it belongs to.
     *
     * @return array{0: ChatThread, 1: Client}
     */
    private function ownThread(Request $request, int $threadId): array
    {
        $clients = $this->ownClients($request);

        if ($clients->isEmpty()) {
            abort(404, 'Conversation not found');
        }

        $thread = ChatThread::where('id', $threadId)
            ->where('thread_type', 'client')
            ->where('linked_to_type', 'Client')
            ->whereIn('linked_to_id', $clients->pluck('id'))
            ->whereIn('company_id', $clients->pluck('company_id'))
            // Membership, not merely ownership: a thread anchored to this
            // client that they were somehow never added to is still not theirs
            // to read.
            ->whereHas('participants', fn ($q) => $q->where('user_id', $request->user()->id))
            ->first();

        if (!$thread) {
            abort(404, 'Conversation not found');
        }

        $client = $clients->firstWhere('id', (int) $thread->linked_to_id);

        if (!$client || (int) $client->company_id !== (int) $thread->company_id) {
            abort(404, 'Conversation not found');
        }

        return [$thread, $client];
    }

    /** The staff people on the other side of a thread, named and role-labelled. */
    private function counterparts(ChatThread $thread, int $clientUserId)
    {
        $ids = ChatParticipant::where('thread_id', $thread->id)
            ->where('user_id', '!=', $clientUserId)
            ->pluck('user_id');

        return User::whereIn('id', $ids)
            ->withTrashed()
            ->get(['id', 'name', 'role_type', 'custom_role_label'])
            ->map(fn (User $u) => [
                'id'   => (int) $u->id,
                'name' => $u->name,
                // "Seller", not "Account Manager" — see the same mapping and
                // reasoning in ClientSellerChatService::roleLabel().
                'role' => $u->custom_role_label ?: match ($u->role_type) {
                    'seller'          => 'Seller',
                    'project_manager' => 'Project Manager',
                    'lead_manager'    => 'Lead Manager',
                    default           => 'Support',
                },
            ])
            ->values();
    }

    // ── GET /client/seller-chat ──────────────────────────────────────────────

    /** Every conversation this login has, newest activity first. */
    public function index(Request $request): JsonResponse
    {
        $clients = $this->ownClients($request);

        if ($clients->isEmpty()) {
            return ApiResponse::success(['conversations' => []]);
        }

        $threads = ChatThread::where('thread_type', 'client')
            ->where('linked_to_type', 'Client')
            ->whereIn('linked_to_id', $clients->pluck('id'))
            ->whereIn('company_id', $clients->pluck('company_id'))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $request->user()->id))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();

        $userId = (int) $request->user()->id;

        return ApiResponse::success([
            'conversations' => $threads->map(function (ChatThread $t) use ($clients, $userId) {
                $client = $clients->firstWhere('id', (int) $t->linked_to_id);
                $with   = $this->counterparts($t, $userId);

                return [
                    'thread_id'       => (int) $t->id,
                    'company_name'    => $client?->company?->name ?: $client?->company_name,
                    'with'            => $with,
                    'title'           => $with->first()['name'] ?? ($t->title ?: 'Your seller'),
                    'role'            => $with->first()['role'] ?? 'Seller',
                    'last_message_at' => optional($t->last_message_at)->toIso8601String(),
                    'unread'          => $this->unreadCount($t, $userId),
                ];
            })->values(),
        ]);
    }

    /** Messages since this login last read the thread. */
    private function unreadCount(ChatThread $thread, int $userId): int
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

    // ── GET /client/seller-chat/{threadId} ───────────────────────────────────

    /**
     * One conversation. Opening it marks it read — the same `last_read_at`
     * column every other chat surface in this app already keeps.
     */
    public function show(Request $request, int $threadId): JsonResponse
    {
        [$thread, $client] = $this->ownThread($request, $threadId);
        $userId = (int) $request->user()->id;

        $messages = ChatMessage::where('thread_id', $thread->id)
            ->with(['sender:id,name,role_type,custom_role_label', 'senderAdmin:id,name'])
            ->orderBy('sent_at')
            ->orderBy('id')
            ->get()
            // A message the staff side hid from a specific participant is
            // absent, not redacted — same convention as
            // Api\User\ClientChatController::messages(). The client can never
            // be a hide target (that controller filters them out), so this is
            // defence in depth rather than an expected case.
            ->reject(fn (ChatMessage $m) => in_array($userId, $m->hidden_from_user_ids ?? [], true))
            ->map(fn (ChatMessage $m) => ClientSellerChatService::presentMessage($m, $userId))
            ->values();

        ChatParticipant::where('thread_id', $thread->id)
            ->where('user_id', $userId)
            ->update(['last_read_at' => now()]);

        $with = $this->counterparts($thread, $userId);

        return ApiResponse::success([
            'thread_id'    => (int) $thread->id,
            'title'        => $with->first()['name'] ?? ($thread->title ?: 'Your seller'),
            'role'         => $with->first()['role'] ?? 'Seller',
            'company_name' => $client->company?->name ?: $client->company_name,
            'with'         => $with,
            'messages'     => $messages,
        ]);
    }

    // ── POST /client/seller-chat/{threadId}/messages ─────────────────────────

    public function store(Request $request, int $threadId): JsonResponse
    {
        $validated = $request->validate(['content' => ['required', 'string', 'max:5000']]);

        [$thread, $client] = $this->ownThread($request, $threadId);

        $message = ClientSellerChatService::postClientMessage(
            $client,
            $thread,
            trim($validated['content'])
        );

        ChatParticipant::where('thread_id', $thread->id)
            ->where('user_id', $request->user()->id)
            ->update(['last_read_at' => now()]);

        return ApiResponse::success(
            ['message' => ClientSellerChatService::presentMessage($message, (int) $request->user()->id)],
            'Message sent',
            201
        );
    }
}
