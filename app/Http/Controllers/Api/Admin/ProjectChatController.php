<?php

namespace App\Http\Controllers\Api\Admin;

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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectChatController extends Controller
{
    // Mirrors Api\Admin\ProjectAttachmentController's limits — same allowed
    // file types/size as every other upload surface in Project Management.
    private const MAX_FILE_KB = 10240; // 10 MB per file
    private const ALLOWED_MIMES = 'pdf,doc,docx,xls,xlsx,png,jpg,jpeg,zip';

    private function admin()   { return auth('admin')->user(); }
    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    private function project(int $projectId): Project
    {
        return Project::whereIn('company_id', $this->companyIds())->findOrFail($projectId);
    }

    private function threadFor(Project $project): ChatThread
    {
        return ChatThread::firstOrCreate(
            ['thread_type' => 'project', 'linked_to_id' => $project->id],
            ['company_id' => $project->company_id, 'title' => $project->name]
        );
    }

    public function index(int $projectId): JsonResponse
    {
        $project = $this->project($projectId);

        $thread = ChatThread::where('thread_type', 'project')->where('linked_to_id', $project->id)->first();

        if (!$thread) {
            return ApiResponse::success(['thread_id' => null, 'messages' => []]);
        }

        $messages = ChatMessage::where('thread_id', $thread->id)
            ->where('is_deleted', false)
            ->with(['sender:id,name', 'senderAdmin:id,name'])
            ->orderBy('sent_at')
            ->get();

        return ApiResponse::success(['thread_id' => $thread->id, 'messages' => $messages]);
    }

    public function store(Request $request, int $projectId): JsonResponse
    {
        $project = $this->project($projectId);

        if ($project->isDraft()) {
            return ApiResponse::error(Project::DRAFT_BLOCKED_MESSAGE, 422);
        }

        $thread = $this->threadFor($project);

        $validated = $request->validate([
            'content'    => ['nullable', 'string', 'max:2000'],
            'file'       => ['nullable', 'file', 'max:' . AttachmentStorageService::maxUploadKb($project->company_id, self::MAX_FILE_KB), 'mimes:' . self::ALLOWED_MIMES],
            'visibility' => ['nullable', 'in:internal,client'],
        ]);

        if (empty($validated['content']) && !$request->hasFile('file')) {
            return ApiResponse::error('Message or attachment is required.', 422);
        }

        $attachmentName = null;
        $messageType    = 'text';
        $storageFields  = [];

        if ($request->hasFile('file')) {
            $file = $validated['file'];
            $folder = ($project->storage_folder ?: 'companies/' . $project->company_id . '/projects/' . $project->id) . '/chat';
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
            'thread_id'        => $thread->id,
            'sender_admin_id'  => $this->admin()->id,
            'content'          => $validated['content'] ?? null,
            'message_type'     => $messageType,
            // Company Admin (rule 4) always sees every message regardless, so
            // index() here needs no visibility filter — this field only
            // controls whether a Seller in 'linked' mode can also see it
            // (Api\User\ProjectChatController filters on it).
            'visibility'       => $validated['visibility'] ?? 'internal',
            'attachment_name'  => $attachmentName,
            'sent_at'          => now(),
        ]));

        $thread->update(['last_message_at' => now()]);

        $this->notifyAndLog($project, $message, null);

        return ApiResponse::success($message->load('senderAdmin:id,name'), 'Message sent', 201);
    }

    // GET /admin/projects/{projectId}/chat/{messageId}/attachment
    public function downloadAttachment(int $projectId, int $messageId): StreamedResponse
    {
        $project = $this->project($projectId);
        $thread  = ChatThread::where('thread_type', 'project')->where('linked_to_id', $project->id)->firstOrFail();

        $message = ChatMessage::where('thread_id', $thread->id)->whereNotNull('attachment_path')->findOrFail($messageId);

        return AttachmentStorageService::download(
            $message->storage_type ?? 'internal', $message->attachment_path, $message->attachment_name ?? 'attachment',
            null, $message->google_drive_file_id, $project->company_id
        );
    }

    // POST /admin/projects/{projectId}/chat/participants — Admin mirror of
    // Api\User\ProjectChatController::addClientParticipant(). Unrestricted
    // (Admin guard), still structurally blocked from adding an internal
    // Developer/Designer/QA/Production user into the client-facing chat.
    public function addClientParticipant(Request $request, int $projectId): JsonResponse
    {
        $project = $this->project($projectId);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->whereIn('company_id', $this->companyIds())],
        ]);

        $target = User::find($validated['user_id']);
        if (in_array($target->role_type, ['developer', 'designer', 'qa', 'production'], true)) {
            return ApiResponse::error('Cannot add an internal production team member to the client-facing chat.', 422);
        }

        $thread = $this->threadFor($project);
        ChatParticipant::firstOrCreate(
            ['thread_id' => $thread->id, 'user_id' => $target->id],
            ['role' => 'member', 'joined_at' => now()]
        );

        return ApiResponse::success(null, 'Participant added to client-facing project chat');
    }

    // Mirrors ProjectCommentController::notifyAndLog() — notifies everyone
    // actually in the thread (ChatParticipant) plus the PM/project team
    // (excluding the actor), since chat access isn't limited to formal team
    // members (e.g. a task-assignee reached this thread via canUseProjectChat).
    // Also writes an activity-log row that feeds the Company Admin's bell.
    private function notifyAndLog(Project $project, ChatMessage $message, ?int $actorUserId): void
    {
        $recipients = ChatParticipant::where('thread_id', $message->thread_id)->pluck('user_id')
            ->merge($project->teamMembers()->pluck('user_id'))
            ->push($project->project_manager_id)
            ->filter()->unique()->reject(fn ($uid) => $uid === $actorUserId);

        $senderName = $message->senderAdmin?->name ?? $message->sender?->name ?? 'Someone';
        $preview    = $message->content
            ? Str::limit($message->content, 120)
            : '📎 ' . ($message->attachment_name ?? 'sent an attachment');

        foreach ($recipients as $uid) {
            Notification::create([
                'user_id'    => $uid,
                'company_id' => $project->company_id,
                'type'       => 'project_chat_message',
                'title'      => "New chat message on {$project->name}",
                'body'       => "{$senderName}: {$preview}",
                'data'       => [
                    'project_id'  => $project->id,
                    'thread_id'   => $message->thread_id,
                    'message_id'  => $message->id,
                    'sender_name' => $senderName,
                    'link'        => "/projects/{$project->id}",
                ],
            ]);
        }

        SystemAuditLog::create([
            'company_id'  => $project->company_id,
            'user_id'     => $actorUserId,
            'action'      => 'project_chat_message_sent',
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
