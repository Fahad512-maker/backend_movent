<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Admin\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatThread;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserCompanyPermission;
use App\Services\AttachmentStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Admin side of General Chat (see Api\User\GeneralChatController for the
// staff-facing half). Company Admin isn't a `users` row, so — same pattern
// as Admin\ProjectChatController/Admin\SalesChatController — access is scoped
// by company ownership (companyIds()) rather than a chat_participants row,
// and outgoing messages are attributed via sender_admin_id (already exists
// on chat_messages).
class GeneralChatController extends Controller
{
    use ScopesToActiveCompany;

    private const MAX_FILE_KB = 10240;
    private const ALLOWED_MIMES = 'pdf,doc,docx,xls,xlsx,png,jpg,jpeg,zip';

    private function admin()   { return auth('admin')->user(); }
    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    private function hasGeneralChat(int $userId, int $companyId): bool
    {
        return UserCompanyPermission::where('user_id', $userId)
            ->where('company_id', $companyId)
            ->where('module_key', 'account')
            ->where('permission_key', 'canUseGeneralChat')
            ->exists();
    }

    // GET /admin/chat — General Chat threads for the active company (or every
    // owned company when "All Companies" is selected, per the Navbar's
    // CompanySelector) — same Company-Wise Dashboard Filtering pattern as
    // every other admin list page. Previously always showed every owned
    // company's threads regardless of the active-company selector.
    public function index(): JsonResponse
    {
        $threads = ChatThread::whereIn('company_id', $this->activeCompanyIds())
            ->whereIn('thread_type', ['direct', 'group'])
            ->with(['participants.user:id,name,role_type', 'company:id,name'])
            ->orderByDesc('last_message_at')
            ->get();

        // One query for every thread's latest message (not N+1) — grouped in
        // memory, since Eloquent has no portable "latest per group" query.
        // Admin has no chat_participants row/last_read_at, so unlike the
        // User-guard index() there's no unread_count here — nothing to
        // compare a "last read" timestamp against.
        $lastMessages = ChatMessage::whereIn('thread_id', $threads->pluck('id'))
            ->where('is_deleted', false)
            ->with(['sender:id,name', 'senderAdmin:id,name'])
            ->orderByDesc('sent_at')
            ->get()
            ->groupBy('thread_id')
            ->map(fn ($msgs) => $msgs->first());

        $result = $threads->map(function ($thread) use ($lastMessages) {
            $last = $lastMessages->get($thread->id);

            return [
                'id'              => $thread->id,
                'company'         => $thread->company?->name,
                'thread_type'     => $thread->thread_type,
                'title'           => $thread->thread_type === 'group' ? $thread->title : $thread->participants->pluck('user.name')->filter()->join(' & '),
                'participants'    => $thread->participants->map(fn ($p) => ['user_id' => $p->user_id, 'name' => $p->user?->name, 'role' => $p->user?->role_type])->values(),
                'last_message_at' => $thread->last_message_at,
                'last_message'    => $last ? [
                    'content'      => $last->content,
                    'message_type' => $last->message_type,
                    'sender_name'  => $last->senderAdmin?->name ?? $last->sender?->name ?? 'Someone',
                    'sent_at'      => $last->sent_at,
                ] : null,
            ];
        });

        return ApiResponse::success($result->values());
    }

    // GET /admin/chat/eligible-users?company_id=X — users of that company who
    // actually hold canUseGeneralChat, for the group-creation checklist AND
    // the "start new chat" picker. Keeps the picker honest: nothing
    // selectable there can fail createGroup()'s own "at least two users who
    // have chat access" check. `has_direct_thread` flags who Admin already
    // has a 1:1 with (see createDirect()) — the frontend uses it to hide
    // them from the direct-chat picker only, NOT the group checklist, since
    // already having a 1:1 has no bearing on whether they belong in a new
    // group.
    public function eligibleUsers(Request $request): JsonResponse
    {
        $companyIds = $this->operationalCompanyIds();
        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'in:' . implode(',', $companyIds)],
        ]);

        $eligibleIds = UserCompanyPermission::where('company_id', $validated['company_id'])
            ->where('module_key', 'account')
            ->where('permission_key', 'canUseGeneralChat')
            ->pluck('user_id');

        $existingDirectPartnerIds = $this->adminDirectPartnerIds((int) $validated['company_id']);

        $users = User::whereIn('id', $eligibleIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'role_type'])
            ->map(fn ($u) => [
                'id'                => $u->id,
                'name'              => $u->name,
                'role_type'         => $u->role_type,
                'has_direct_thread' => $existingDirectPartnerIds->contains($u->id),
            ]);

        return ApiResponse::success($users);
    }

    // Every user Admin already has a 1:1 General Chat with, in this company
    // — an "Admin direct" thread is identified by having exactly ONE
    // ChatParticipant row (see createDirect()/class docblock: Admin itself
    // is never a participant row).
    private function adminDirectPartnerIds(int $companyId)
    {
        return ChatThread::where('thread_type', 'direct')
            ->where('company_id', $companyId)
            ->with('participants')
            ->get()
            ->filter(fn ($t) => $t->participants->count() === 1)
            ->flatMap(fn ($t) => $t->participants->pluck('user_id'))
            ->unique();
    }

    // POST /admin/chat/direct { company_id, user_id } — Admin starting a 1:1
    // with any eligible user. Admin is never a chat_participants row (see
    // class docblock), so an "Admin direct" thread is identified by having
    // exactly ONE participant (that user) — distinct from a peer-to-peer
    // direct thread between two staff members, which always has two.
    public function createDirect(Request $request): JsonResponse
    {
        $companyIds = $this->operationalCompanyIds();

        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'in:' . implode(',', $companyIds)],
            'user_id'    => ['required', 'integer', Rule::exists('users', 'id')],
        ]);

        // No separate company_id check needed — hasGeneralChat() below
        // already checks for a canUseGeneralChat grant scoped to THIS
        // company specifically, a stronger guarantee than the raw
        // users.company_id column (which, for a multi-company user, may
        // point at a different company than the one that granted this).
        if (!$this->hasGeneralChat((int) $validated['user_id'], $validated['company_id'])) {
            return ApiResponse::error('Selected user does not have chat access.', 422);
        }

        $thread = ChatThread::where('thread_type', 'direct')
            ->where('company_id', $validated['company_id'])
            ->whereHas('participants', fn ($q) => $q->where('user_id', $validated['user_id']))
            ->withCount('participants')
            ->get()
            ->firstWhere('participants_count', 1);

        if (!$thread) {
            $thread = ChatThread::create(['company_id' => $validated['company_id'], 'thread_type' => 'direct']);
            ChatParticipant::create(['thread_id' => $thread->id, 'user_id' => $validated['user_id'], 'role' => 'member', 'joined_at' => now()]);
        }

        return ApiResponse::success(['thread_id' => $thread->id], 'Direct chat ready', 201);
    }

    // POST /admin/chat/group { company_id, title, participant_user_ids[] } —
    // Admin assembling a group channel; Admin themselves is never a row in
    // chat_participants (see class docblock), just an overseer of the thread.
    public function createGroup(Request $request): JsonResponse
    {
        $companyIds = $this->operationalCompanyIds();

        $validated = $request->validate([
            'company_id'             => ['required', 'integer', 'in:' . implode(',', $companyIds)],
            'title'                  => ['required', 'string', 'max:255'],
            'participant_user_ids'   => ['required', 'array', 'min:2'],
            'participant_user_ids.*' => ['integer', Rule::exists('users', 'id')],
        ]);

        $participantIds = collect($validated['participant_user_ids'])
            ->unique()
            ->filter(fn ($id) => $this->hasGeneralChat((int) $id, $validated['company_id']))
            ->values();

        if ($participantIds->count() < 2) {
            return ApiResponse::error('Select at least two users who have chat access.', 422);
        }

        $thread = ChatThread::create([
            'company_id'  => $validated['company_id'],
            'thread_type' => 'group',
            'title'       => $validated['title'],
        ]);

        foreach ($participantIds as $index => $id) {
            ChatParticipant::create(['thread_id' => $thread->id, 'user_id' => $id, 'role' => $index === 0 ? 'admin' : 'member', 'joined_at' => now()]);
        }

        return ApiResponse::success(['thread_id' => $thread->id], 'Group chat created', 201);
    }

    // DELETE /admin/chat/{threadId}/participants/{userId} — Admin can remove
    // any participant from any thread they own, unlike a staff group-admin
    // who can only manage their own group.
    public function removeParticipant(int $threadId, int $userId): JsonResponse
    {
        $thread = $this->thread($threadId);
        ChatParticipant::where('thread_id', $thread->id)->where('user_id', $userId)->delete();

        return ApiResponse::success(null, 'Participant removed');
    }

    public function messages(int $threadId): JsonResponse
    {
        $thread = $this->thread($threadId);

        $messages = ChatMessage::where('thread_id', $thread->id)
            ->where('is_deleted', false)
            ->with(['sender:id,name', 'senderAdmin:id,name'])
            ->orderBy('sent_at')
            ->get();

        return ApiResponse::success($messages);
    }

    public function send(Request $request, int $threadId): JsonResponse
    {
        $thread = $this->thread($threadId);

        $validated = $request->validate([
            'content' => ['nullable', 'string', 'max:2000'],
            'file'    => ['nullable', 'file', 'max:' . AttachmentStorageService::maxUploadKb($thread->company_id, self::MAX_FILE_KB), 'mimes:' . self::ALLOWED_MIMES],
        ]);

        if (empty($validated['content']) && !$request->hasFile('file')) {
            return ApiResponse::error('Message or attachment is required.', 422);
        }

        $attachmentName = null;
        $messageType    = 'text';
        $storageFields  = [];

        if ($request->hasFile('file')) {
            $file = $validated['file'];
            $stored = AttachmentStorageService::store($file, $thread->company_id, 'chat', 'companies/' . $thread->company_id . '/general-chat/' . $thread->id);
            if (!$stored) {
                return AttachmentStorageService::errorResponse($thread->company_id);
            }
            $attachmentName = $stored->originalName;
            $messageType    = str_starts_with($stored->mimeType ?? '', 'image/') ? 'image' : 'file';
            // ChatMessage's own columns are attachment_path/attachment_size,
            // not locationAttributes()'s file_path — mapped explicitly rather
            // than array_merge()'d, since Eloquent would otherwise silently
            // drop the mismatched key with no error.
            $storageFields = [
                'storage_type'           => $stored->storageType,
                'attachment_path'        => $stored->filePath,
                'attachment_size'        => $stored->sizeBytes,
                'google_drive_file_id'   => $stored->googleDriveFileId,
                'google_drive_folder_id' => $stored->googleDriveFolderId,
            ];
        }

        $message = ChatMessage::create(array_merge($storageFields, [
            'thread_id'        => $thread->id,
            'sender_admin_id'  => $this->admin()->id,
            'content'          => $validated['content'] ?? null,
            'message_type'     => $messageType,
            'attachment_name'  => $attachmentName,
            'sent_at'          => now(),
        ]));

        $thread->update(['last_message_at' => now()]);
        $this->notifyParticipants($thread, $message);

        return ApiResponse::success($message->load('senderAdmin:id,name'), 'Message sent', 201);
    }

    // PATCH /admin/chat/{threadId}/messages/{messageId} — only the sending
    // admin can edit their own message.
    public function updateMessage(Request $request, int $threadId, int $messageId): JsonResponse
    {
        $thread = $this->thread($threadId);
        $message = ChatMessage::where('thread_id', $thread->id)->where('is_deleted', false)->findOrFail($messageId);

        if ($message->sender_admin_id !== $this->admin()->id) {
            return ApiResponse::error('You can only edit your own messages.', 403);
        }

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:2000'],
        ]);

        $message->update(['content' => $validated['content'], 'edited_at' => now()]);

        return ApiResponse::success($message->fresh()->load('senderAdmin:id,name'), 'Message updated');
    }

    // DELETE /admin/chat/{threadId}/messages/{messageId} — Company Admin can
    // delete ANY message in a thread they oversee (moderation), not just
    // their own, same as Admin's unrestricted delete on Project Comments.
    // Soft delete via the existing is_deleted flag.
    public function deleteMessage(int $threadId, int $messageId): JsonResponse
    {
        $thread = $this->thread($threadId);
        $message = ChatMessage::where('thread_id', $thread->id)->where('is_deleted', false)->findOrFail($messageId);

        $message->update(['is_deleted' => true]);

        return ApiResponse::success(null, 'Message deleted');
    }

    public function downloadAttachment(int $threadId, int $messageId): StreamedResponse
    {
        $thread = $this->thread($threadId);
        $message = ChatMessage::where('thread_id', $thread->id)->whereNotNull('attachment_path')->findOrFail($messageId);

        return AttachmentStorageService::download(
            $message->storage_type ?? 'internal', $message->attachment_path, $message->attachment_name ?? 'attachment',
            null, $message->google_drive_file_id, $thread->company_id
        );
    }

    private function thread(int $threadId): ChatThread
    {
        return ChatThread::whereIn('company_id', $this->companyIds())
            ->whereIn('thread_type', ['direct', 'group'])
            ->findOrFail($threadId);
    }

    private function notifyParticipants(ChatThread $thread, ChatMessage $message): void
    {
        $senderName = $this->admin()->name ?? 'Company Admin';
        $preview = $message->content
            ? Str::limit($message->content, 120)
            : '📎 ' . ($message->attachment_name ?? 'sent an attachment');

        $recipients = ChatParticipant::where('thread_id', $thread->id)->whereNull('muted_at')->pluck('user_id');

        foreach ($recipients as $recipientId) {
            Notification::create([
                'user_id'    => $recipientId,
                'company_id' => $thread->company_id,
                'type'       => 'general_chat_message',
                'title'      => $thread->thread_type === 'group' ? "New message in \"{$thread->title}\"" : 'New chat message',
                'body'       => "{$senderName}: {$preview}",
                'data'       => ['thread_id' => $thread->id, 'message_id' => $message->id, 'sender_name' => $senderName, 'link' => '/chat'],
            ]);
        }
    }
}
