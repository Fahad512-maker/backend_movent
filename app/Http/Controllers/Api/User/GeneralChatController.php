<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatThread;
use App\Models\CompanyAdmin;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserCompanyPermission;
use App\Services\AttachmentStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

// General Chat — a standalone, cross-department messaging feature (direct or
// group threads between any staff who hold canUseGeneralChat), deliberately
// NOT tied to a project/lead/client workflow — that stays on structured
// Project/Task Comments instead (see Api\User\ProjectCommentController).
// Reuses chat_threads/chat_messages/chat_participants exactly as-is:
// thread_type 'direct'/'group' have existed in the schema since day one but
// were never used by any controller until now.
class GeneralChatController extends Controller
{
    private const MAX_FILE_KB = 10240; // 10 MB per file
    private const ALLOWED_MIMES = 'pdf,doc,docx,xls,xlsx,png,jpg,jpeg,zip';

    private function user() { return auth('sanctum')->user(); }

    private function can(int $userId, int $companyId, string $permKey, string $moduleKey = 'account'): bool
    {
        return UserCompanyPermission::where('user_id', $userId)
            ->where('company_id', $companyId)
            ->where('module_key', $moduleKey)
            ->where('permission_key', $permKey)
            ->exists();
    }

    private function requireGeneralChat(): void
    {
        $user = $this->user();
        if (!$this->can($user->id, $user->company_id, 'canUseGeneralChat')) {
            abort(403, 'Permission denied');
        }
    }

    // GET /user/chat — threads this user participates in, most recent first.
    public function index(): JsonResponse
    {
        $this->requireGeneralChat();
        $user = $this->user();

        $threadIds = ChatParticipant::where('user_id', $user->id)->pluck('thread_id');

        // Scoped to the active company — a multi-company user's
        // ChatParticipant rows span every company they've ever chatted in,
        // and without this filter their OTHER company's threads (and
        // coworkers) would leak into this company's General Chat list.
        $threads = ChatThread::whereIn('id', $threadIds)
            ->where('company_id', $user->company_id)
            ->whereIn('thread_type', ['direct', 'group'])
            ->with(['participants.user:id,name,role_type'])
            ->orderByDesc('last_message_at')
            ->get();

        // One query for every thread's latest message (not N+1) — grouped in
        // memory, since Eloquent has no portable "latest per group" query.
        $lastMessages = ChatMessage::whereIn('thread_id', $threads->pluck('id'))
            ->where('is_deleted', false)
            ->with(['sender:id,name', 'senderAdmin:id,name'])
            ->orderByDesc('sent_at')
            ->get()
            ->groupBy('thread_id')
            ->map(fn ($msgs) => $msgs->first());

        // Admin oversees every company it belongs to but is never a company's
        // sole owner by id — company_id is a Company's own primary key, not
        // an admin's, so looking up CompanyAdmin::find($user->company_id) was
        // always wrong (coincidentally matching IDs aside). The real lookup
        // is "which Company Admin(s) own this company", same relation
        // Api\Admin\GeneralChatController::companyIds() reads the other way.
        $companyAdminName = CompanyAdmin::whereHas('companies', fn ($q) => $q->where('companies.id', $user->company_id))
            ->value('name');

        $threadsResult = $threads->map(function ($thread) use ($user, $lastMessages, $companyAdminName) {
                $mine = $thread->participants->firstWhere('user_id', $user->id);
                $other = $thread->thread_type === 'direct'
                    ? $thread->participants->firstWhere('user_id', '!=', $user->id)?->user
                    : null;

                // A 'direct' thread with only ONE ChatParticipant row (no
                // "other" user found) is this user's direct chat with Company
                // Admin — Admin never gets its own ChatParticipant row since
                // CompanyAdmin isn't a `users` row. Without this, $other is
                // always null there and the title fell back to "Unknown".
                $directTitle = $other?->name ?? $companyAdminName ?? 'Company Admin';

                $last = $lastMessages->get($thread->id);

                // Unread = messages sent after I last opened this thread
                // (or every message if I've never opened it), excluding my
                // own — I obviously don't need to be told my own message is
                // "unread". last_read_at existed in the schema since day one
                // but was never actually read/written anywhere until now.
                $unreadCount = ChatMessage::where('thread_id', $thread->id)
                    ->where('is_deleted', false)
                    // "not sent by me" — Admin-authored messages have a NULL
                    // sender_id (they use sender_admin_id instead), and SQL's
                    // != NULL always evaluates falsy, so a plain
                    // where('sender_id', '!=', $id) would silently exclude
                    // every Admin message from ever counting as unread.
                    ->where(fn ($w) => $w->whereNull('sender_id')->orWhere('sender_id', '!=', $user->id))
                    ->when($mine?->last_read_at, fn ($q, $lastRead) => $q->where('sent_at', '>', $lastRead))
                    ->count();

                return [
                    'id'              => $thread->id,
                    'thread_type'     => $thread->thread_type,
                    'title'           => $thread->thread_type === 'direct' ? $directTitle : $thread->title,
                    'participants'    => $thread->participants->map(fn ($p) => ['user_id' => $p->user_id, 'name' => $p->user?->name, 'role' => $p->user?->role_type])->values(),
                    'is_muted'        => $mine?->muted_at !== null,
                    'last_message_at' => $thread->last_message_at,
                    'last_message'    => $last ? [
                        'content'      => $last->content,
                        'message_type' => $last->message_type,
                        'sender_name'  => $last->senderAdmin?->name ?? $last->sender?->name ?? 'Someone',
                        'sent_at'      => $last->sent_at,
                    ] : null,
                    'unread_count'    => $unreadCount,
                ];
            });

        return ApiResponse::success($threadsResult->values());
    }

    // GET /user/chat/eligible-users — same-company coworkers who also hold
    // canUseGeneralChat, for the "start new chat" picker AND the New Group
    // checklist. Backend re-validates on create() regardless — this just
    // keeps the picker from offering choices that would be rejected anyway.
    // `has_direct_thread` flags who this user already has a 1:1 with (see
    // findDirectThread()) — the frontend uses it to hide them from the
    // direct-chat picker only, NOT the group checklist, since already
    // having a 1:1 with someone has no bearing on whether they belong in a
    // new group.
    public function eligibleUsers(): JsonResponse
    {
        $this->requireGeneralChat();
        $user = $this->user();

        $eligibleIds = UserCompanyPermission::where('company_id', $user->company_id)
            ->where('module_key', 'account')
            ->where('permission_key', 'canUseGeneralChat')
            ->pluck('user_id');

        $existingDirectPartnerIds = $this->directChatPartnerIds($user->id, $user->company_id);

        $users = User::whereIn('id', $eligibleIds)
            ->where('id', '!=', $user->id)
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

    // Every coworker this user already has a 1:1 General Chat thread with,
    // in this company — mirrors findDirectThread()'s own "exact 2-person
    // pair" resolution so the two never disagree.
    private function directChatPartnerIds(int $userId, int $companyId)
    {
        return ChatThread::where('thread_type', 'direct')
            ->where('company_id', $companyId)
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userId))
            ->with('participants')
            ->get()
            ->flatMap(fn ($t) => $t->participants->pluck('user_id'))
            ->filter(fn ($id) => $id !== $userId)
            ->unique();
    }

    // POST /user/chat/direct { recipient_user_id }
    public function createDirect(Request $request): JsonResponse
    {
        $this->requireGeneralChat();
        $user = $this->user();

        $validated = $request->validate([
            'recipient_user_id' => ['required', 'integer', Rule::exists('users', 'id')],
        ]);
        $recipientId = (int) $validated['recipient_user_id'];

        if ($recipientId === $user->id) {
            return ApiResponse::error('Cannot start a direct chat with yourself.', 422);
        }
        // No separate company_id check needed — can() below already checks
        // for a canUseGeneralChat grant scoped to THIS company specifically,
        // a stronger guarantee than the raw users.company_id column (which,
        // for a multi-company recipient, may point at a different company
        // than the one that actually granted them this permission).
        if (!$this->can($recipientId, $user->company_id, 'canUseGeneralChat')) {
            return ApiResponse::error('Selected user does not have chat access.', 422);
        }

        $thread = $this->findDirectThread($user->id, $recipientId, $user->company_id);

        return ApiResponse::success(['thread_id' => $thread->id], 'Direct chat ready', 201);
    }

    // POST /user/chat/group { title, participant_user_ids[] }
    public function createGroup(Request $request): JsonResponse
    {
        $this->requireGeneralChat();
        $user = $this->user();

        $validated = $request->validate([
            'title'                  => ['required', 'string', 'max:255'],
            'participant_user_ids'   => ['required', 'array', 'min:1'],
            'participant_user_ids.*' => ['integer', Rule::exists('users', 'id')],
        ]);

        $participantIds = collect($validated['participant_user_ids'])
            ->unique()
            ->reject(fn ($id) => (int) $id === $user->id)
            ->filter(fn ($id) => $this->can((int) $id, $user->company_id, 'canUseGeneralChat'))
            ->values();

        if ($participantIds->isEmpty()) {
            return ApiResponse::error('Select at least one other user with chat access.', 422);
        }

        $thread = ChatThread::create([
            'company_id' => $user->company_id,
            'thread_type' => 'group',
            'title'       => $validated['title'],
            'created_by'  => $user->id,
        ]);

        ChatParticipant::create(['thread_id' => $thread->id, 'user_id' => $user->id, 'role' => 'admin', 'joined_at' => now()]);
        foreach ($participantIds as $id) {
            ChatParticipant::create(['thread_id' => $thread->id, 'user_id' => $id, 'role' => 'member', 'joined_at' => now()]);
        }

        return ApiResponse::success(['thread_id' => $thread->id], 'Group chat created', 201);
    }

    // POST /user/chat/{threadId}/participants { user_id } — creator/admin only.
    public function addParticipant(Request $request, int $threadId): JsonResponse
    {
        $this->requireGeneralChat();
        $user = $this->user();
        $thread = $this->groupThreadManagedBy($threadId, $user->id);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
        ]);
        $newUserId = (int) $validated['user_id'];

        // No separate company_id check needed — can() below already checks
        // for a canUseGeneralChat grant scoped to THIS thread's company.
        if (!$this->can($newUserId, $thread->company_id, 'canUseGeneralChat')) {
            return ApiResponse::error('Selected user does not have chat access.', 422);
        }

        ChatParticipant::firstOrCreate(
            ['thread_id' => $thread->id, 'user_id' => $newUserId],
            ['role' => 'member', 'joined_at' => now()]
        );

        return ApiResponse::success(null, 'Participant added');
    }

    // DELETE /user/chat/{threadId}/participants/{userId} — creator/admin only.
    public function removeParticipant(int $threadId, int $userId): JsonResponse
    {
        $this->requireGeneralChat();
        $user = $this->user();
        $thread = $this->groupThreadManagedBy($threadId, $user->id);

        ChatParticipant::where('thread_id', $thread->id)->where('user_id', $userId)->delete();

        return ApiResponse::success(null, 'Participant removed');
    }

    // PATCH /user/chat/{threadId}/mute — toggles the CALLER's own mute state.
    public function toggleMute(int $threadId): JsonResponse
    {
        $this->requireGeneralChat();
        $user = $this->user();

        $participant = ChatParticipant::where('thread_id', $threadId)->where('user_id', $user->id)->firstOrFail();
        $participant->update(['muted_at' => $participant->muted_at ? null : now()]);

        return ApiResponse::success(['is_muted' => $participant->muted_at !== null]);
    }

    public function messages(int $threadId): JsonResponse
    {
        $this->requireGeneralChat();
        $thread = $this->participantThread($threadId);

        $messages = ChatMessage::where('thread_id', $thread->id)
            ->where('is_deleted', false)
            ->with(['sender:id,name', 'senderAdmin:id,name'])
            ->orderBy('sent_at')
            ->get();

        // Opening a thread marks it read — index()'s unread_count is based on
        // this timestamp, same idea as the sidebar clearing WhatsApp's own
        // unread badge the moment you open a chat.
        ChatParticipant::where('thread_id', $thread->id)->where('user_id', $this->user()->id)->update(['last_read_at' => now()]);

        return ApiResponse::success($messages);
    }

    public function send(Request $request, int $threadId): JsonResponse
    {
        $this->requireGeneralChat();
        $user = $this->user();
        $thread = $this->participantThread($threadId);

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
            'thread_id'       => $thread->id,
            'sender_id'       => $user->id,
            'content'         => $validated['content'] ?? null,
            'message_type'    => $messageType,
            'attachment_name' => $attachmentName,
            'sent_at'         => now(),
        ]));

        $thread->update(['last_message_at' => now()]);
        $this->notifyParticipants($thread, $message, $user->id);

        return ApiResponse::success($message->load('sender:id,name'), 'Message sent', 201);
    }

    // PATCH /user/chat/{threadId}/messages/{messageId} — only the sender can
    // edit their own message.
    public function updateMessage(Request $request, int $threadId, int $messageId): JsonResponse
    {
        $this->requireGeneralChat();
        $user = $this->user();
        $thread = $this->participantThread($threadId);

        $message = ChatMessage::where('thread_id', $thread->id)->where('is_deleted', false)->findOrFail($messageId);

        if ($message->sender_id !== $user->id) {
            return ApiResponse::error('You can only edit your own messages.', 403);
        }

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:2000'],
        ]);

        $message->update(['content' => $validated['content'], 'edited_at' => now()]);

        return ApiResponse::success($message->fresh()->load('sender:id,name'), 'Message updated');
    }

    // DELETE /user/chat/{threadId}/messages/{messageId} — only the sender can
    // delete their own message. Soft delete (is_deleted already existed and
    // is already excluded from messages() above) — the row and any attachment
    // file are kept, matching how nothing else in this thread is hard-deleted.
    public function deleteMessage(int $threadId, int $messageId): JsonResponse
    {
        $this->requireGeneralChat();
        $user = $this->user();
        $thread = $this->participantThread($threadId);

        $message = ChatMessage::where('thread_id', $thread->id)->where('is_deleted', false)->findOrFail($messageId);

        if ($message->sender_id !== $user->id) {
            return ApiResponse::error('You can only delete your own messages.', 403);
        }

        $message->update(['is_deleted' => true]);

        return ApiResponse::success(null, 'Message deleted');
    }

    public function downloadAttachment(int $threadId, int $messageId): StreamedResponse
    {
        $this->requireGeneralChat();
        $thread = $this->participantThread($threadId);
        $message = ChatMessage::where('thread_id', $thread->id)->whereNotNull('attachment_path')->findOrFail($messageId);

        return AttachmentStorageService::download(
            $message->storage_type ?? 'internal', $message->attachment_path, $message->attachment_name ?? 'attachment',
            null, $message->google_drive_file_id, $thread->company_id
        );
    }

    // Finds (or creates) the one 'direct' thread whose exact participant set
    // is {$userA, $userB} — chat_threads has no natural unique key for a
    // 2-person pair, so this is resolved via participant membership + count
    // rather than trying to encode the pair into linked_to_id.
    private function findDirectThread(int $userA, int $userB, int $companyId): ChatThread
    {
        $existing = ChatThread::where('thread_type', 'direct')
            ->where('company_id', $companyId)
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userA))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userB))
            ->withCount('participants')
            ->get()
            ->firstWhere('participants_count', 2);

        if ($existing) return $existing;

        $thread = ChatThread::create([
            'company_id'  => $companyId,
            'thread_type' => 'direct',
            'created_by'  => $userA,
        ]);
        ChatParticipant::create(['thread_id' => $thread->id, 'user_id' => $userA, 'role' => 'member', 'joined_at' => now()]);
        ChatParticipant::create(['thread_id' => $thread->id, 'user_id' => $userB, 'role' => 'member', 'joined_at' => now()]);

        return $thread;
    }

    // A thread the caller can view/message in — must be an actual participant.
    private function participantThread(int $threadId): ChatThread
    {
        $user = $this->user();
        $thread = ChatThread::where('id', $threadId)
            ->where('company_id', $user->company_id)
            ->whereIn('thread_type', ['direct', 'group'])
            ->firstOrFail();

        ChatParticipant::where('thread_id', $thread->id)->where('user_id', $user->id)->firstOrFail();

        return $thread;
    }

    // A group thread the caller may manage (creator or an 'admin'-role participant).
    private function groupThreadManagedBy(int $threadId, int $userId): ChatThread
    {
        $thread = ChatThread::where('id', $threadId)->where('thread_type', 'group')->firstOrFail();

        $isManager = $thread->created_by === $userId
            || ChatParticipant::where('thread_id', $thread->id)->where('user_id', $userId)->where('role', 'admin')->exists();

        if (!$isManager) {
            abort(403, 'Only the group creator or an admin can manage participants.');
        }

        return $thread;
    }

    private function notifyParticipants(ChatThread $thread, ChatMessage $message, int $actorId): void
    {
        $senderName = $message->sender?->name ?? 'Someone';
        $preview = $message->content
            ? Str::limit($message->content, 120)
            : '📎 ' . ($message->attachment_name ?? 'sent an attachment');

        $recipients = ChatParticipant::where('thread_id', $thread->id)
            ->where('user_id', '!=', $actorId)
            ->whereNull('muted_at')
            ->pluck('user_id');

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
