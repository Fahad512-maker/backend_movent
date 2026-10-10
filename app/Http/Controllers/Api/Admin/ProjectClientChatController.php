<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\Notification;
use App\Models\Project;
use App\Services\AttachmentStorageService;
use App\Services\ProjectClientChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Company Admin half of the per-project CLIENT chat (see
// Api\Client\ProjectChatController and ProjectClientChatService) — the third
// party in every project's Client <-> Seller <-> Company Admin conversation.
//
// Admin reaches EVERY project's client chat in its companies unconditionally
// and is never a chat_participants row (it has no `users` id), exactly the
// convention Api\Admin\ProjectMessengerController and GeneralChatController
// already use. Its messages carry sender_admin_id instead of sender_id, which
// is what both the portal and the Seller's page render as "(Admin)".
class ProjectClientChatController extends Controller
{
    private const MAX_FILE_KB = 10240;
    private const ALLOWED_MIMES = 'pdf,doc,docx,xls,xlsx,png,jpg,jpeg,zip';

    private function admin() { return auth('admin')->user(); }

    private function project(int $projectId): Project
    {
        $companyIds = $this->admin()->companies()->pluck('id');

        return Project::whereIn('company_id', $companyIds)->findOrFail($projectId);
    }

    // GET /admin/projects/{projectId}/client-chat
    public function index(int $projectId): JsonResponse
    {
        $project = $this->project($projectId);
        $thread  = ProjectClientChatService::threadFor($project);

        $messages = ChatMessage::where('thread_id', $thread->id)
            ->with(['sender:id,name,role_type', 'senderAdmin:id,name'])
            ->orderBy('sent_at')
            ->get()
            ->each(fn ($m) => $this->applyDeletedTombstone($m));

        $thread->load('participants.user:id,name,role_type');

        return ApiResponse::success([
            // status drives the composer's draft lock on the page — a draft
            // rejects sends server-side (store()'s isDraft() guard).
            'project'  => ['id' => $project->id, 'name' => $project->name, 'status' => $project->status],
            // Admin may tag everyone in the conversation — but not the
            // "Company Admin" sentinel, which is Admin itself.
            'mentionables' => ProjectClientChatService::mentionablesFor($thread, null, false),
            'thread'   => [
                'id' => $thread->id,
                'participants' => $thread->participants->map(fn ($p) => [
                    'user_id' => $p->user_id, 'name' => $p->user?->name, 'role_type' => $p->user?->role_type,
                ])->values(),
            ],
            'messages' => $messages,
        ]);
    }

    // POST /admin/projects/{projectId}/client-chat
    public function store(Request $request, int $projectId): JsonResponse
    {
        $project = $this->project($projectId);

        if ($project->isDraft()) {
            return ApiResponse::error(Project::DRAFT_BLOCKED_MESSAGE, 422);
        }

        $thread  = ProjectClientChatService::threadFor($project);

        $validated = $request->validate([
            'content'    => ['nullable', 'string', 'max:2000'],
            'file'       => ['nullable', 'file', 'max:' . AttachmentStorageService::maxUploadKb($project->company_id, self::MAX_FILE_KB), 'mimes:' . self::ALLOWED_MIMES],
            'mentions'   => ['nullable', 'array'],
            'mentions.*' => ['integer'],
        ]);

        if (empty($validated['content']) && !$request->hasFile('file')) {
            return ApiResponse::error('Message or attachment is required.', 422);
        }

        // No actor user id — Admin isn't a `users` row — and no Admin
        // sentinel, since that would be tagging itself.
        $mentions = ProjectClientChatService::filterMentions(
            $thread,
            $validated['mentions'] ?? null,
            null,
            false
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
            'sender_admin_id' => $this->admin()->id,
            'content'         => $validated['content'] ?? null,
            'mentions'        => $mentions->isNotEmpty() ? $mentions->all() : null,
            'message_type'    => $messageType,
            'visibility'      => 'client',
            'attachment_name' => $attachmentName,
            'is_deleted'      => false,
            'sent_at'         => now(),
        ]));

        $thread->update(['last_message_at' => now()]);
        $this->notifyOthers($project, $message);
        ProjectClientChatService::notifyMentions(
            $project,
            $message,
            $mentions,
            ($this->admin()->name ?? 'Company Admin') . ' (Admin)',
            null,
            $this->admin()->id
        );

        return ApiResponse::success($message->load('senderAdmin:id,name'), 'Message sent', 201);
    }

    // A deleted message stays in the list (so everyone keeps seeing it in
    // place, WhatsApp-style) but its content/attachment are wiped — only the
    // `is_deleted` flag survives for the frontend to render the "This message
    // was deleted" placeholder from. Matches Api\Admin\ProjectMessengerController.
    private function applyDeletedTombstone(ChatMessage $m): void
    {
        if (!$m->is_deleted) return;
        $m->content = null;
        $m->attachment_path = null;
        $m->attachment_name = null;
        $m->mentions = null;
    }

    // DELETE /admin/projects/{projectId}/client-chat/{messageId} — Admin can
    // delete ANY message in the client conversation (its own, staff's, or the
    // client's), matching Api\Admin\ProjectMessengerController's unrestricted
    // authority. Every other side of this conversation may only delete their
    // own message — see the User and Client controllers.
    public function deleteMessage(int $projectId, int $messageId): JsonResponse
    {
        $project = $this->project($projectId);
        $thread  = ProjectClientChatService::threadFor($project);

        $message = ChatMessage::where('thread_id', $thread->id)->where('is_deleted', false)->findOrFail($messageId);
        $message->update(['is_deleted' => true]);

        return ApiResponse::success(null, 'Message deleted');
    }

    // POST /admin/projects/{projectId}/client-chat/{messageId}/toggle-hide —
    // Admin may hide/unhide ANY message (own, Seller's/PM's, or the client's).
    // Purely a staff-side view toggle: the client's own chat view
    // (Api\Client\ProjectChatController) never reads this column and always
    // shows the message untouched — hiding is invisible to them. Shared
    // across every staff viewer of the thread, not personal: this flips it
    // for the Seller/PM's view too, not just Admin's own.
    public function toggleHide(int $projectId, int $messageId): JsonResponse
    {
        $project = $this->project($projectId);
        $thread  = ProjectClientChatService::threadFor($project);

        $message = ChatMessage::where('thread_id', $thread->id)->where('is_deleted', false)->findOrFail($messageId);
        $message->update(['hidden_for_staff' => !$message->hidden_for_staff]);

        return ApiResponse::success(
            ['hidden_for_staff' => $message->hidden_for_staff],
            $message->hidden_for_staff ? 'Message hidden' : 'Message unhidden'
        );
    }

    // GET /admin/projects/{projectId}/client-chat/{messageId}/attachment
    public function downloadAttachment(int $projectId, int $messageId): StreamedResponse
    {
        $project = $this->project($projectId);
        $thread  = ProjectClientChatService::existingThreadFor($project);

        if (!$thread) abort(404, 'Attachment not found');

        $message = ChatMessage::where('thread_id', $thread->id)
            ->where('is_deleted', false)
            ->whereNotNull('attachment_path')
            ->findOrFail($messageId);

        return AttachmentStorageService::download(
            $message->storage_type ?? 'internal', $message->attachment_path, $message->attachment_name ?? 'attachment',
            null, $message->google_drive_file_id, $project->company_id
        );
    }

    // Both `users`-backed sides of the conversation — the client (portal
    // bell, deep-linked to the project's own Chat tab) and the project's
    // Seller (staff bell). No SystemAuditLog row here: Admin is the actor, so
    // the entry would only ever notify Admin of its own message.
    private function notifyOthers(Project $project, ChatMessage $message): void
    {
        $senderName = ($this->admin()->name ?? 'Company Admin');
        $preview    = $message->content
            ? Str::limit($message->content, 120)
            : '📎 ' . ($message->attachment_name ?? 'sent an attachment');

        $clientUserId = ProjectClientChatService::clientUserId($project);

        foreach (ChatParticipant::where('thread_id', $message->thread_id)->pluck('user_id') as $uid) {
            Notification::create([
                'user_id'    => $uid,
                'company_id' => $project->company_id,
                'type'       => 'project_client_chat_message',
                'title'      => "New message on {$project->name}",
                'body'       => "{$senderName} (Admin): {$preview}",
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
    }
}
