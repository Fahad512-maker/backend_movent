<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatThread;
use App\Models\Notification;
use App\Models\Project;
use App\Models\SystemAuditLog;
use App\Models\User;
use App\Services\AttachmentStorageService;
use App\Services\NotificationService;
use App\Services\ProjectClientChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Staff half of the per-project CLIENT chat — the Seller's side of the "Chat"
// tab the client sees on their portal project page (see
// Api\Client\ProjectChatController and ProjectClientChatService).
//
// Access is identity-based, not permission-based, and deliberately narrow.
// Two roles reach it from this guard:
//   - the project's own linked Seller (projects.seller_id), always. That
//     mirrors the per-client Sales Chat convention (Seller <-> Client <->
//     Company Admin, see Api\User\SalesChatController) which is likewise
//     ungated by a module permission — a Seller must always be able to answer
//     their own client.
//   - the project's Project Manager, but ONLY once the Seller has invited
//     them (invitePm() below) — i.e. only while they hold a real
//     chat_participants row. Depending on which option the Seller picked,
//     the PM either reads the whole thread or only what was said after the
//     invite (chat_participants.history_from_message_id).
// Everyone else on the internal side (developers, QA, production) has the
// internal team messenger instead (Api\User\ProjectMessengerController,
// thread_type='project_group'), which the client can never see; this client
// conversation is not theirs to read.
//
// A handoff that reassigns projects.seller_id moves this access with it —
// ProjectClientChatService::syncParticipants() prunes the previous Seller on
// the next access, so an ex-Seller stops seeing the ongoing conversation
// while the messages they already wrote stay attributed to them. The same
// prune drops a previously-invited PM once the project's PM changes.
class ProjectClientChatController extends Controller
{
    private const MAX_FILE_KB = 10240;
    private const ALLOWED_MIMES = 'pdf,doc,docx,xls,xlsx,png,jpg,jpeg,zip';

    private function user() { return auth('sanctum')->user(); }

    // The Seller's own project — the only role that may invite the PM or
    // write into a thread that doesn't exist yet.
    private function sellerProject(int $projectId): Project
    {
        $user = $this->user();

        return Project::where('company_id', $user->company_id)
            ->where('seller_id', $user->id)
            ->findOrFail($projectId);
    }

    // Any project whose client chat this caller may READ/reply to: their own
    // as the Seller, or one they were invited into as the Project Manager.
    // Returns [$project, 'seller'|'pm'].
    private function project(int $projectId): array
    {
        $user = $this->user();
        $project = Project::where('company_id', $user->company_id)->findOrFail($projectId);

        if ($project->seller_id === $user->id) {
            return [$project, 'seller'];
        }

        $thread = ProjectClientChatService::existingThreadFor($project);

        $invited = $thread
            && ProjectClientChatService::invitablePmIds($project)->contains($user->id)
            && ChatParticipant::where('thread_id', $thread->id)->where('user_id', $user->id)->exists();

        if ($invited) {
            return [$project, 'pm'];
        }

        abort(404);
    }

    // The caller's own participant row, which carries the history cutoff a
    // limited PM invite set (NULL for the Seller and for a full-history
    // invite — see the 2026_08_12_120000 migration).
    private function participant(ChatThread $thread): ?ChatParticipant
    {
        return ChatParticipant::where('thread_id', $thread->id)
            ->where('user_id', $this->user()->id)
            ->first();
    }

    // GET /user/projects/{projectId}/client-chat
    public function index(int $projectId): JsonResponse
    {
        [$project, $role] = $this->project($projectId);
        $thread  = ProjectClientChatService::threadFor($project);
        $me      = $this->participant($thread);

        $messages = ChatMessage::where('thread_id', $thread->id)
            // An invited PM limited to "chat from now" never receives the
            // earlier messages at all — they aren't returned redacted, they
            // simply aren't in the response. Compared on id, not sent_at, so
            // a message written in the same second as the invite lands on the
            // correct side of the line (see the 2026_08_12_130000 migration).
            ->when(
                $me && $me->history_from_message_id !== null,
                fn ($q) => $q->where('id', '>', $me->history_from_message_id)
            )
            ->with(['sender:id,name,role_type', 'senderAdmin:id,name'])
            ->orderBy('sent_at')
            ->get()
            ->each(fn ($m) => $this->applyDeletedTombstone($m));

        ChatParticipant::where('thread_id', $thread->id)
            ->where('user_id', $this->user()->id)
            ->update(['last_read_at' => now()]);

        $thread->load('participants.user:id,name,role_type');

        // Who the Seller may invite, and whether they already did — drives the
        // "Invite Project Manager" control. An EMPTY list means this project
        // has nobody to invite, which is not the same as "no manager shown on
        // the project": project_manager_id is often the Seller themselves (see
        // ProjectClientChatService::invitablePmIds), and that isn't someone to
        // invite into their own conversation.
        $pmIds  = ProjectClientChatService::invitablePmIds($project);
        $pmById = User::whereIn('id', $pmIds)->pluck('name', 'id');

        $pms = $pmIds->map(function ($pmId) use ($thread, $pmById) {
            $participant = $thread->participants->firstWhere('user_id', $pmId);

            return [
                'user_id'    => $pmId,
                'name'       => $pmById[$pmId] ?? null,
                'invited'    => (bool) $participant,
                // 'all' = full history, 'from_now' = only post-invite messages.
                'history'    => $participant
                    ? ($participant->history_from_message_id !== null ? 'from_now' : 'all')
                    : null,
                'invited_at' => $participant?->joined_at,
            ];
        })->values();

        return ApiResponse::success([
            // status drives the composer's draft lock on the page — a draft
            // rejects sends server-side (store()'s isDraft() guard).
            'project'  => ['id' => $project->id, 'name' => $project->name, 'status' => $project->status],
            // 'seller' owns the conversation and its invite controls; 'pm' is
            // a guest who can read (within their history window) and reply.
            'role'     => $role,
            'pms'      => $pms,
            // Who this caller may @mention — everyone else in the
            // conversation, plus Company Admin. For a Seller that is the
            // Client and Admin (and the PM once invited); for an invited PM
            // it is the Client, the Seller and Admin.
            'mentionables' => ProjectClientChatService::mentionablesFor($thread, $this->user()->id, true),
            'thread'   => [
                'id' => $thread->id,
                'participants' => $thread->participants
                    ->where('user_id', '!=', $this->user()->id)
                    ->map(fn ($p) => ['user_id' => $p->user_id, 'name' => $p->user?->name, 'role_type' => $p->user?->role_type])
                    ->values()
                    ->prepend(['user_id' => null, 'name' => 'Company Admin', 'role_type' => 'admin']),
            ],
            'messages' => $messages,
        ]);
    }

    // POST /user/projects/{projectId}/client-chat/invite-pm { history: all|from_now }
    // Seller-only: pulls THIS project's Project Manager into the client
    // conversation. Idempotent — re-inviting an already-present PM just
    // updates their history window, which is how a Seller widens a
    // "chat from now" invite to "view all chat" later without any separate
    // endpoint. Narrowing back the other way is allowed too, but it only
    // hides the earlier messages going forward; anything the PM already read
    // was already read.
    public function invitePm(Request $request, int $projectId): JsonResponse
    {
        $project = $this->sellerProject($projectId);

        $validated = $request->validate([
            'history' => ['required', 'in:all,from_now'],
            // Optional — only needed when the project has more than one
            // eligible Project Manager (project_manager_id plus any team
            // member carrying role_in_project='project_manager').
            'user_id' => ['nullable', 'integer'],
        ]);

        $pmIds = ProjectClientChatService::invitablePmIds($project);
        if ($pmIds->isEmpty()) {
            return ApiResponse::error(
                'No Project Manager is on this project yet. Assign one to the project, or add them to its team, and you can invite them here.',
                422
            );
        }

        $pmId = $validated['user_id'] ?? ($pmIds->count() === 1 ? $pmIds->first() : null);
        if (!$pmId) {
            return ApiResponse::error('This project has more than one Project Manager — pick which one to invite.', 422);
        }
        if (!$pmIds->contains($pmId)) {
            return ApiResponse::error('That user is not a Project Manager on this project.', 422);
        }

        $thread = ProjectClientChatService::threadFor($project);

        $participant = ChatParticipant::firstOrNew([
            'thread_id' => $thread->id,
            'user_id'   => $pmId,
        ]);
        $wasAlreadyIn = $participant->exists;

        $participant->fill([
            'role'      => 'member',
            'added_by'  => $this->user()->id,
            'joined_at' => $participant->joined_at ?? now(),
            // The last message that already exists becomes the watermark, so
            // "chat from now" means literally "everything written after this
            // click". 0 on an empty thread — still not NULL, which is what
            // distinguishes a limited invite from a full-history one.
            'history_from_message_id' => $validated['history'] === 'all'
                ? null
                : (int) (ChatMessage::where('thread_id', $thread->id)->max('id') ?? 0),
        ])->save();

        if (!$wasAlreadyIn) {
            Notification::create([
                'user_id'    => $pmId,
                'company_id' => $project->company_id,
                'type'       => 'project_client_chat_invited',
                'title'      => "Added to client chat — {$project->name}",
                'body'       => "{$this->user()->name} invited you into the client conversation for '{$project->name}'.",
                'data'       => [
                    'project_id' => $project->id,
                    'thread_id'  => $thread->id,
                    'link'       => "/projects/{$project->id}/client-chat",
                ],
            ]);
        }

        return ApiResponse::success(
            ['history' => $validated['history']],
            $wasAlreadyIn ? 'Project Manager access updated' : 'Project Manager invited to this chat'
        );
    }

    // POST /user/projects/{projectId}/client-chat/notify-admin
    // Alerts every Company Admin of this company that the conversation needs
    // them. Admin can already read and post in every project's client chat
    // unconditionally (Api\Admin\ProjectClientChatController) — this is purely
    // the "come and look at this one" ping, so it writes no message into the
    // thread and the client never sees that it happened.
    public function notifyAdmin(Request $request, int $projectId): JsonResponse
    {
        [$project] = $this->project($projectId);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $note = trim($validated['note'] ?? '');

        NotificationService::notifyCompanyAdmins($project->company_id, null, [
            'type'        => 'project_client_chat_admin_requested',
            'module'      => 'project_management',
            'title'       => "Client chat needs you — {$project->name}",
            'message'     => $note !== ''
                ? "{$this->user()->name}: {$note}"
                : "{$this->user()->name} asked you to join the client conversation on '{$project->name}'.",
            'entity_type' => 'Project',
            'entity_id'   => $project->id,
            'url'         => "/admin/projects/{$project->id}/client-chat",
        ]);

        return ApiResponse::success(null, 'Company Admin notified');
    }

    // POST /user/projects/{projectId}/client-chat
    public function store(Request $request, int $projectId): JsonResponse
    {
        [$project] = $this->project($projectId);

        if ($project->isDraft()) {
            return ApiResponse::error(Project::DRAFT_BLOCKED_MESSAGE, 422);
        }

        $thread  = ProjectClientChatService::threadFor($project);
        $user    = $this->user();

        $validated = $request->validate([
            'content'    => ['nullable', 'string', 'max:2000'],
            'file'       => ['nullable', 'file', 'max:' . AttachmentStorageService::maxUploadKb($project->company_id, self::MAX_FILE_KB), 'mimes:' . self::ALLOWED_MIMES],
            'mentions'   => ['nullable', 'array'],
            'mentions.*' => ['integer'],
        ]);

        if (empty($validated['content']) && !$request->hasFile('file')) {
            return ApiResponse::error('Message or attachment is required.', 422);
        }

        $mentions = ProjectClientChatService::filterMentions(
            $thread,
            $validated['mentions'] ?? null,
            $user->id,
            true
        );

        $attachmentName = null;
        $messageType    = 'text';
        $storageFields  = [];

        if ($request->hasFile('file')) {
            $file   = $validated['file'];
            $folder = ($project->storage_folder ?: 'companies/' . $project->company_id . '/projects/' . $project->id) . '/client-chat';
            $stored = AttachmentStorageService::store($file, $project->company_id, 'chat', $folder);
            if (!$stored) {
                return AttachmentStorageService::errorResponse($project->company_id);
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
            'mentions'        => $mentions->isNotEmpty() ? $mentions->all() : null,
            'message_type'    => $messageType,
            'visibility'      => 'client',
            'attachment_name' => $attachmentName,
            'is_deleted'      => false,
            'sent_at'         => now(),
        ]));

        $thread->update(['last_message_at' => now()]);
        $this->notifyOthers($project, $message, $user->id, $user->name);
        ProjectClientChatService::notifyMentions(
            $project,
            $message,
            $mentions,
            $user->name ?? 'Someone',
            $user->id
        );

        return ApiResponse::success($message->load('sender:id,name,role_type'), 'Message sent', 201);
    }

    // A deleted message stays in the list (so everyone keeps seeing it in
    // place, WhatsApp-style) but its content/attachment are wiped — only the
    // `is_deleted` flag survives for the frontend to render the "This message
    // was deleted" placeholder from. Matches Api\User\ProjectMessengerController.
    private function applyDeletedTombstone(ChatMessage $m): void
    {
        if (!$m->is_deleted) return;
        $m->content = null;
        $m->attachment_path = null;
        $m->attachment_name = null;
        $m->mentions = null;
    }

    // DELETE /user/projects/{projectId}/client-chat/{messageId} — own message
    // only, for both the Seller and an invited PM. Company Admin's
    // unrestricted "delete any message" authority lives exclusively in
    // Api\Admin\ProjectClientChatController::deleteMessage().
    public function deleteMessage(int $projectId, int $messageId): JsonResponse
    {
        [$project] = $this->project($projectId);
        $thread  = ProjectClientChatService::threadFor($project);
        $user    = $this->user();

        $message = ChatMessage::where('thread_id', $thread->id)->where('is_deleted', false)->findOrFail($messageId);

        if ($message->sender_id !== $user->id) {
            return ApiResponse::error('You can only delete your own messages.', 403);
        }

        $message->update(['is_deleted' => true]);

        return ApiResponse::success(null, 'Message deleted');
    }

    // POST /user/projects/{projectId}/client-chat/{messageId}/toggle-hide —
    // the Seller/invited PM may hide/unhide their own message or the client's,
    // never another staff member's (that mirrors "own + the opposing side",
    // not "own + everyone"). Company Admin's unconditional authority over
    // every message lives in Api\Admin\ProjectClientChatController::toggleHide().
    // Purely a staff-side view toggle — the client's own chat view
    // (Api\Client\ProjectChatController) never reads this column, so hiding
    // is invisible to them. Shared across every staff viewer of the thread.
    public function toggleHide(int $projectId, int $messageId): JsonResponse
    {
        [$project] = $this->project($projectId);
        $thread  = ProjectClientChatService::threadFor($project);
        $user    = $this->user();

        $message = ChatMessage::where('thread_id', $thread->id)->where('is_deleted', false)->findOrFail($messageId);

        $isMine = $message->sender_id === $user->id;
        $isClientMessage = $message->sender_id !== null
            && $message->sender_id === ProjectClientChatService::clientUserId($project);

        if (!$isMine && !$isClientMessage) {
            return ApiResponse::error('You can only hide your own messages or the client\'s messages.', 403);
        }

        $message->update(['hidden_for_staff' => !$message->hidden_for_staff]);

        return ApiResponse::success(
            ['hidden_for_staff' => $message->hidden_for_staff],
            $message->hidden_for_staff ? 'Message hidden' : 'Message unhidden'
        );
    }

    // GET /user/projects/{projectId}/client-chat/{messageId}/attachment
    public function downloadAttachment(int $projectId, int $messageId): StreamedResponse
    {
        [$project] = $this->project($projectId);
        $thread  = ProjectClientChatService::existingThreadFor($project);

        if (!$thread) abort(404, 'Attachment not found');

        $me = $this->participant($thread);

        $message = ChatMessage::where('thread_id', $thread->id)
            ->where('is_deleted', false)
            ->whereNotNull('attachment_path')
            // Same history window as index() — a PM invited with "chat from
            // now" can't pull an older attachment by guessing its id either.
            ->when(
                $me && $me->history_from_message_id !== null,
                fn ($q) => $q->where('id', '>', $me->history_from_message_id)
            )
            ->findOrFail($messageId);

        return AttachmentStorageService::download(
            $message->storage_type ?? 'internal', $message->attachment_path, $message->attachment_name ?? 'attachment',
            null, $message->google_drive_file_id, $project->company_id
        );
    }

    // Everyone else in the thread — the client (portal bell, deep-linked to
    // the project's own Chat tab) and, once invited, the Project Manager
    // (staff bell) — plus the SystemAuditLog row that surfaces on Company
    // Admin's bell, since Admin is never a `users` row.
    private function notifyOthers(Project $project, ChatMessage $message, int $actorUserId, ?string $actorName): void
    {
        $senderName = $actorName ?? 'Someone';
        $preview    = $message->content
            ? Str::limit($message->content, 120)
            : '📎 ' . ($message->attachment_name ?? 'sent an attachment');

        $clientUserId = ProjectClientChatService::clientUserId($project);

        $recipients = ChatParticipant::where('thread_id', $message->thread_id)
            ->where('user_id', '!=', $actorUserId)
            ->pluck('user_id');

        foreach ($recipients as $uid) {
            Notification::create([
                'user_id'    => $uid,
                'company_id' => $project->company_id,
                'type'       => 'project_client_chat_message',
                'title'      => "New message on {$project->name}",
                'body'       => "{$senderName}: {$preview}",
                'data'       => [
                    'project_id' => $project->id,
                    'thread_id'  => $message->thread_id,
                    'message_id' => $message->id,
                    'link'       => $uid === $clientUserId
                        ? "/client/projects/{$project->id}?tab=chat"
                        : "/projects/{$project->id}/client-chat",
                ],
            ]);
        }

        SystemAuditLog::create([
            'company_id'  => $project->company_id,
            'user_id'     => $actorUserId,
            'action'      => 'project_client_chat_message_sent',
            'module_key'  => 'project_management',
            'entity_type' => 'Project',
            'entity_id'   => $project->id,
            'new_values'  => [
                'thread_id'  => $message->thread_id,
                'message_id' => $message->id,
                'preview'    => $preview,
                'project'    => $project->name,
                'sender'     => $senderName,
            ],
        ]);
    }
}
