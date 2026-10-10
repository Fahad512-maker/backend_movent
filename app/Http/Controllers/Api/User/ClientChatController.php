<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatThread;
use App\Models\Client;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserCompanyPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// User-side view of a client's restricted Direct Chat (thread_type='client',
// see Api\Client\ChatController) — only shows threads THIS staff member is
// actually a participant of (a Seller never sees the client's separate
// conversation with Finance, or vice versa). Eligibility to have been added
// as a participant in the first place was already enforced when the client
// started the chat (Api\Client\ChatController::startChat()).
class ClientChatController extends Controller
{
    private function user() { return auth('sanctum')->user(); }

    private function client(int $clientId): Client
    {
        return Client::where('company_id', $this->user()->company_id)->findOrFail($clientId);
    }

    // Mirrors Api\Client\ChatController::eligibleContactsFor()'s allow-list,
    // checked from the opposite direction: is THIS staff member allowed to
    // reach THIS client at all — the client's account manager, whoever
    // created/sent one of their invoices, or a canRecordPayments (Finance)
    // holder when the client has at least one invoice to discuss. An
    // unrelated staff member (e.g. a Developer with no sales/finance tie to
    // this client) can never start a chat here, only ever reply to a thread
    // the client themselves already invited them into.
    private function isEligibleContact(Client $client, int $userId): bool
    {
        if ($client->account_manager === $userId) return true;
        if ($client->invoices()->where(fn ($q) => $q->where('created_by', $userId)->orWhere('sent_by', $userId))->exists()) return true;

        if ($client->invoices()->exists()) {
            return UserCompanyPermission::where('user_id', $userId)
                ->where('company_id', $client->company_id)
                ->where('module_key', 'finance')
                ->where('permission_key', 'canRecordPayments')
                ->exists();
        }

        return false;
    }

    // POST /user/clients/{clientId}/direct-chat/start — the staff-initiated
    // counterpart to Api\Client\ChatController::startChat()'s "user" branch;
    // that one only ever let the CLIENT start the conversation. Finds the
    // existing thread if the client already started one with this same
    // staff member (same dedupe: exactly these 2 participants).
    public function startChat(int $clientId): JsonResponse
    {
        $client = $this->client($clientId);
        $user = $this->user();

        if (!$this->isEligibleContact($client, $user->id)) {
            return ApiResponse::error('You are not linked to this client (not their account manager, and have not sent them an invoice).', 403);
        }

        if (!$client->user_id) {
            return ApiResponse::error('This client has no portal account yet — direct chat is unavailable until portal access is enabled.', 422);
        }

        $thread = ChatThread::where('thread_type', 'client')
            ->where('linked_to_type', 'Client')->where('linked_to_id', $client->id)
            ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $client->user_id))
            ->withCount('participants')
            ->get()
            ->firstWhere('participants_count', 2);

        if (!$thread) {
            // The staff member's own name — not the client's — since this
            // exact row shape is what Api\Client\ChatController::threads()
            // reads back as this client's own "chat with {staff}" thread
            // title, matching the convention that controller's startChat()
            // already uses when the client initiates instead.
            $thread = ChatThread::create([
                'company_id' => $client->company_id, 'thread_type' => 'client',
                'linked_to_type' => 'Client', 'linked_to_id' => $client->id,
                'title' => $user->name, 'created_by' => $user->id,
            ]);
            ChatParticipant::create(['thread_id' => $thread->id, 'user_id' => $user->id, 'role' => 'member', 'joined_at' => now()]);
            ChatParticipant::create(['thread_id' => $thread->id, 'user_id' => $client->user_id, 'role' => 'member', 'joined_at' => now()]);
        }

        return ApiResponse::success(['thread_id' => $thread->id], 'Chat ready', 201);
    }

    // GET /user/clients/{clientId}/chat
    public function index(int $clientId): JsonResponse
    {
        $client = $this->client($clientId);
        $userId = $this->user()->id;

        $threads = ChatThread::where('thread_type', 'client')
            ->where('linked_to_type', 'Client')->where('linked_to_id', $client->id)
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userId))
            ->with('participants.user:id,name,role_type')
            ->orderByDesc('last_message_at')
            ->get(['id', 'title', 'last_message_at'])
            ->map(fn ($t) => [
                'id' => $t->id, 'title' => $t->title, 'last_message_at' => $t->last_message_at,
                'participants' => $t->participants->map(fn ($p) => [
                    'user_id' => $p->user_id, 'name' => $p->user?->name, 'role_type' => $p->user?->role_type,
                ])->values(),
            ]);

        return ApiResponse::success($threads);
    }

    private function participantThread(Client $client, int $threadId, int $userId): ChatThread
    {
        return ChatThread::where('thread_type', 'client')
            ->where('linked_to_type', 'Client')->where('linked_to_id', $client->id)
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userId))
            ->findOrFail($threadId);
    }

    // POST /user/clients/{clientId}/direct-chat/{threadId}/participants { user_id }
    // Loops a Project Manager into an existing Seller<->client conversation
    // instead of the PM needing their own separate thread — restricted to
    // role_type='project_manager' specifically (not any staff member) since
    // that's the one role this "bring them in" flow is for; anyone already
    // eligible to reach this client on their own still uses startChat().
    public function addParticipant(Request $request, int $clientId, int $threadId): JsonResponse
    {
        $client = $this->client($clientId);
        $user   = $this->user();
        $thread = $this->participantThread($client, $threadId, $user->id);

        $validated = $request->validate(['user_id' => 'required|integer']);
        $targetId = (int) $validated['user_id'];

        $pm = User::where('id', $targetId)
            ->where('company_id', $user->company_id)
            ->where('role_type', 'project_manager')
            ->where('is_active', true)
            ->first();

        if (!$pm) {
            return ApiResponse::error('Only an active Project Manager can be added to this chat.', 422);
        }

        $participant = ChatParticipant::firstOrCreate(
            ['thread_id' => $thread->id, 'user_id' => $targetId],
            ['role' => 'member', 'added_by' => $user->id, 'joined_at' => now()]
        );

        if ($participant->wasRecentlyCreated) {
            Notification::create([
                'user_id'    => $targetId,
                'company_id' => $client->company_id,
                'type'       => 'client_chat_added',
                'title'      => 'Added to a client chat',
                'body'       => "{$user->name} added you to their conversation with \"{$client->name}\".",
                'data'       => ['client_id' => $client->id, 'thread_id' => $thread->id, 'link' => "/clients/{$client->id}"],
            ]);
        }

        return ApiResponse::success(null, 'Project Manager added');
    }

    // DELETE /user/clients/{clientId}/direct-chat/{threadId}/participants/{userId}
    // Only ever removes a PM added via addParticipant() above — the client
    // and the seller who owns this thread are never removable through this
    // endpoint (there'd be no conversation left to have).
    public function removeParticipant(int $clientId, int $threadId, int $userId): JsonResponse
    {
        $client = $this->client($clientId);
        $thread = $this->participantThread($client, $threadId, $this->user()->id);

        if ($userId === $client->user_id) {
            return ApiResponse::error('The client cannot be removed from their own conversation.', 422);
        }

        ChatParticipant::where('thread_id', $thread->id)
            ->where('user_id', $userId)
            ->whereHas('user', fn ($q) => $q->where('role_type', 'project_manager'))
            ->delete();

        return ApiResponse::success(null, 'Removed from chat');
    }

    public function messages(int $clientId, int $threadId): JsonResponse
    {
        $client = $this->client($clientId);
        $userId = $this->user()->id;
        $thread = $this->participantThread($client, $threadId, $userId);

        $messages = ChatMessage::where('thread_id', $thread->id)
            ->where('is_deleted', false)
            ->with(['sender:id,name', 'senderAdmin:id,name'])
            ->orderBy('sent_at')
            ->get()
            // A message hidden from THIS viewer (see reply()'s
            // hidden_from_user_ids) never reaches them at all — not shown
            // as a redacted placeholder, simply absent, same as if it were
            // a different thread entirely.
            ->reject(fn ($m) => in_array($userId, $m->hidden_from_user_ids ?? [], true))
            ->values();

        return ApiResponse::success($messages);
    }

    public function reply(Request $request, int $clientId, int $threadId): JsonResponse
    {
        $client = $this->client($clientId);
        $user   = $this->user();
        $thread = $this->participantThread($client, $threadId, $user->id);

        $participantIds = ChatParticipant::where('thread_id', $thread->id)->pluck('user_id');

        $validated = $request->validate([
            'content'              => 'required|string|max:5000',
            'hidden_from_user_ids' => 'nullable|array',
            'hidden_from_user_ids.*' => 'integer',
        ]);

        // Can only ever hide from an OTHER staff participant already in this
        // thread (the added PM) — never from yourself, and never from the
        // client, no matter what the request sends; that would defeat the
        // entire point of a client conversation.
        $hiddenFrom = collect($validated['hidden_from_user_ids'] ?? [])
            ->filter(fn ($id) => $participantIds->contains($id) && $id !== $user->id && $id !== $client->user_id)
            ->unique()
            ->values();

        $message = ChatMessage::create([
            'thread_id'             => $thread->id,
            'sender_id'             => $user->id,
            'content'               => $validated['content'],
            'hidden_from_user_ids'  => $hiddenFrom->isNotEmpty() ? $hiddenFrom->all() : null,
            'message_type'          => 'text',
            'sent_at'               => now(),
        ]);

        $thread->update(['last_message_at' => now()]);

        if ($client->user_id) {
            Notification::create([
                'user_id'    => $client->user_id,
                'company_id' => $client->company_id,
                'type'       => 'client_chat_message',
                'title'      => "New message from {$user->name}",
                'body'       => Str::limit($validated['content'], 120),
                // The client CAN read this conversation again as of the AI
                // Assistance hand-off (Api\Client\SellerChatController). The
                // link opens the portal's assistant widget straight onto this
                // thread — it stays inside /client/..., which is the only shape
                // the portal's notification bell will follow.
                'data'       => [
                    'thread_id'  => $thread->id,
                    'message_id' => $message->id,
                    'link'       => "/client/dashboard?assistant_chat={$thread->id}",
                ],
            ]);
        }

        // Any OTHER staff participant (e.g. an added PM) — never the client,
        // who was already notified explicitly above (they are a
        // chat_participants row of their own conversation too, so this loop
        // would otherwise double-notify them for the very same message) —
        // and skipped entirely when this exact message was hidden from them,
        // so they never get a preview of something they're not allowed to see.
        foreach ($participantIds as $pid) {
            if ($pid === $user->id || $pid === $client->user_id || $hiddenFrom->contains($pid)) continue;
            Notification::create([
                'user_id'    => $pid,
                'company_id' => $client->company_id,
                'type'       => 'client_chat_message',
                'title'      => "New message from {$user->name}",
                'body'       => Str::limit($validated['content'], 120),
                'data'       => ['client_id' => $client->id, 'thread_id' => $thread->id, 'message_id' => $message->id, 'link' => "/clients/{$client->id}"],
            ]);
        }

        return ApiResponse::success($message->load('sender:id,name'), 'Message sent', 201);
    }
}
