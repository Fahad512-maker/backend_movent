<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatThread;
use App\Models\Client;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// Admin-side view of a client's restricted Direct Chat (thread_type='client',
// see Api\Client\ChatController) — the client-initiated counterpart to Sales
// Chat. Company Admin is unrestricted, scoped only to companies it owns, same
// pattern as Api\Admin\SalesChatController.
class ClientChatController extends Controller
{
    private function admin() { return auth('admin')->user(); }
    private function companyIds(): array { return $this->admin()->companies()->pluck('id')->toArray(); }

    private function client(int $clientId): Client
    {
        return Client::whereIn('company_id', $this->companyIds())->findOrFail($clientId);
    }

    // POST /admin/clients/{clientId}/direct-chat/start — the Admin-initiated
    // counterpart to Api\Client\ChatController::startChat()'s "admin" branch;
    // that one only ever let the CLIENT start the conversation. Company
    // Admin is never a chat_participants row (same convention as every
    // other chat surface in this app), so an "Admin thread" with a client is
    // identified by having exactly ONE participant — the client themselves.
    public function startChat(int $clientId): JsonResponse
    {
        $client = $this->client($clientId);

        if (!$client->user_id) {
            return ApiResponse::error('This client has no portal account yet — direct chat is unavailable until portal access is enabled.', 422);
        }

        $thread = ChatThread::where('thread_type', 'client')
            ->where('company_id', $client->company_id)
            ->where('linked_to_type', 'Client')->where('linked_to_id', $client->id)
            ->withCount('participants')
            ->get()
            ->firstWhere('participants_count', 1);

        if (!$thread) {
            // 'Company Admin' — not the client's name — since this exact row
            // shape is what Api\Client\ChatController::threads() reads back
            // as this client's own "chat with Company Admin" thread title.
            $thread = ChatThread::create([
                'company_id' => $client->company_id, 'thread_type' => 'client',
                'linked_to_type' => 'Client', 'linked_to_id' => $client->id,
                'title' => 'Company Admin',
            ]);
            ChatParticipant::create(['thread_id' => $thread->id, 'user_id' => $client->user_id, 'role' => 'member', 'joined_at' => now()]);
        }

        return ApiResponse::success(['thread_id' => $thread->id], 'Chat ready', 201);
    }

    // GET /admin/clients/{clientId}/chat — every direct-chat thread this
    // client has (with Admin, with a linked Seller, with Finance), for the
    // Admin's own "Client Messages" tab review.
    public function index(int $clientId): JsonResponse
    {
        $client = $this->client($clientId);

        $threads = ChatThread::where('thread_type', 'client')
            ->where('linked_to_type', 'Client')->where('linked_to_id', $client->id)
            ->withCount('participants')
            ->with(['participants.user:id,name,role_type'])
            ->orderByDesc('last_message_at')
            ->get()
            ->map(fn ($t) => [
                'id'              => $t->id,
                'title'           => $t->participants_count === 1 ? 'Company Admin' : ($t->title ?? $t->participants->first()?->user?->name),
                'last_message_at' => $t->last_message_at,
                'participants'    => $t->participants->map(fn ($p) => [
                    'user_id' => $p->user_id, 'name' => $p->user?->name, 'role_type' => $p->user?->role_type,
                ])->values(),
            ]);

        return ApiResponse::success($threads);
    }

    // POST /admin/clients/{clientId}/direct-chat/{threadId}/participants { user_id }
    // Same "loop a PM into an existing conversation" flow Api\User\
    // ClientChatController exposes to a Seller — Admin can do it too,
    // unrestricted (not required to already be a participant, matching
    // index()/messages() above).
    public function addParticipant(Request $request, int $clientId, int $threadId): JsonResponse
    {
        $client = $this->client($clientId);
        $thread = ChatThread::where('thread_type', 'client')
            ->where('linked_to_type', 'Client')->where('linked_to_id', $client->id)
            ->findOrFail($threadId);

        $validated = $request->validate(['user_id' => 'required|integer']);
        $targetId = (int) $validated['user_id'];

        $pm = User::where('id', $targetId)
            ->where('company_id', $client->company_id)
            ->where('role_type', 'project_manager')
            ->where('is_active', true)
            ->first();

        if (!$pm) {
            return ApiResponse::error('Only an active Project Manager can be added to this chat.', 422);
        }

        $participant = ChatParticipant::firstOrCreate(
            ['thread_id' => $thread->id, 'user_id' => $targetId],
            ['role' => 'member', 'joined_at' => now()]
        );

        if ($participant->wasRecentlyCreated) {
            Notification::create([
                'user_id'    => $targetId,
                'company_id' => $client->company_id,
                'type'       => 'client_chat_added',
                'title'      => 'Added to a client chat',
                'body'       => "Company Admin added you to a conversation with \"{$client->name}\".",
                'data'       => ['client_id' => $client->id, 'thread_id' => $thread->id, 'link' => "/clients/{$client->id}"],
            ]);
        }

        return ApiResponse::success(null, 'Project Manager added');
    }

    // DELETE /admin/clients/{clientId}/direct-chat/{threadId}/participants/{userId}
    public function removeParticipant(int $clientId, int $threadId, int $userId): JsonResponse
    {
        $client = $this->client($clientId);
        $thread = ChatThread::where('thread_type', 'client')
            ->where('linked_to_type', 'Client')->where('linked_to_id', $client->id)
            ->findOrFail($threadId);

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

        $thread = ChatThread::where('thread_type', 'client')
            ->where('linked_to_type', 'Client')->where('linked_to_id', $client->id)
            ->findOrFail($threadId);

        $messages = ChatMessage::where('thread_id', $thread->id)
            ->where('is_deleted', false)
            ->with(['sender:id,name', 'senderAdmin:id,name'])
            ->orderBy('sent_at')
            ->get();

        return ApiResponse::success($messages);
    }

    public function reply(Request $request, int $clientId, int $threadId): JsonResponse
    {
        $validated = $request->validate(['content' => 'required|string|max:5000']);

        $client = $this->client($clientId);

        $thread = ChatThread::where('thread_type', 'client')
            ->where('linked_to_type', 'Client')->where('linked_to_id', $client->id)
            ->findOrFail($threadId);

        $message = ChatMessage::create([
            'thread_id'       => $thread->id,
            'sender_admin_id' => $this->admin()->id,
            'content'         => $validated['content'],
            'message_type'    => 'text',
            'sent_at'         => now(),
        ]);

        $thread->update(['last_message_at' => now()]);

        if ($client->user_id) {
            Notification::create([
                'user_id'    => $client->user_id,
                'company_id' => $client->company_id,
                'type'       => 'client_chat_message',
                'title'      => 'New message from ' . ($this->admin()->name ?? 'Company Admin'),
                'body'       => Str::limit($validated['content'], 120),
                // Was '/client/chat' (removed 2026-09-11), then the dashboard
                // as a stopgap because nothing in the portal rendered a
                // thread_type='client' conversation. That gap is now closed —
                // Api\Client\SellerChatController serves this exact thread and
                // the portal's assistant widget opens it — so the link points
                // at the conversation itself again. Still inside /client/...,
                // which is the only shape the portal's bell will follow.
                'data'       => [
                    'thread_id'  => $thread->id,
                    'message_id' => $message->id,
                    'link'       => "/client/dashboard?assistant_chat={$thread->id}",
                ],
            ]);
        }

        return ApiResponse::success($message->load('senderAdmin:id,name'), 'Message sent', 201);
    }
}
