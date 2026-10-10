<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatThread;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\Project;
use App\Models\SystemAuditLog;
use App\Models\User;
use App\Models\UserCompanyPermission;
use App\Services\AttachmentStorageService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectChatController extends Controller
{
    // Mirrors Api\User\ProjectAttachmentController's limits — same allowed
    // file types/size as every other upload surface in Project Management.
    private const MAX_FILE_KB = 10240; // 10 MB per file
    private const ALLOWED_MIMES = 'pdf,doc,docx,xls,xlsx,png,jpg,jpeg,zip';

    private function user() { return auth('sanctum')->user(); }

    private function can(string $permKey, string $moduleKey = 'project_management'): bool
    {
        $user = $this->user();
        return UserCompanyPermission::where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->where('module_key', $moduleKey)
            ->where('permission_key', $permKey)
            ->exists();
    }

    // Resolves which of the three chat tiers this user occupies on this
    // project, per the Seller/PM/Developer access-control rules. Reuses the
    // pre-existing canUseProjectChat/canViewAllCompanyProjects permissions
    // rather than inventing separate ones — canParticipateClientFacingProjectChat
    // is the ONE new toggle this tiering needed.
    //   'bridge'   — Project Manager (or canViewAllCompanyProjects) who ALSO
    //                holds canParticipateClientFacingProjectChat — sees + posts
    //                BOTH internal and client-visibility messages. The only
    //                tier that bridges Seller <-> internal production team.
    //   'internal' — a PM without the client-facing permission yet, OR any
    //                other internal member (Developer/Designer/QA/Production/
    //                Team Member via team membership or task assignment) —
    //                sees + posts internal-visibility messages ONLY. Can
    //                never reach the client-facing slice.
    //   'linked'   — a Seller linked to the project's originating lead/client
    //                (or holding the override) — sees + posts client-visibility
    //                messages ONLY. Can never reach internal messages; see
    //                store()'s explicit block + message for what happens if
    //                they try.
    // Throws a 403 in ApiResponse's own shape so every caller (JsonResponse
    // or StreamedResponse) gets identical error handling for free.
    private function projectForChat(int $projectId): array
    {
        $user = $this->user();
        $project = Project::where('company_id', $user->company_id)->findOrFail($projectId);

        $isPM = $project->project_manager_id === $user->id
            || $project->teamMembers()->where('user_id', $user->id)->where('role_in_project', 'project_manager')->exists()
            || $this->can('canViewAllCompanyProjects');

        if ($isPM) {
            if (!$this->can('canUseProjectChat')) {
                throw new HttpResponseException(ApiResponse::error('Permission denied', 403));
            }
            $mode = $this->can('canParticipateClientFacingProjectChat') ? 'bridge' : 'internal';
            return [$project, $mode, 'pm'];
        }

        // A Seller's chat identity is the client-facing 'linked' slice, and team
        // membership must not silently replace it with the internal one.
        //
        // This matters because a Seller can now BE a project_team_members row
        // (Api\Admin\ProjectController::assignTeam() accepts them). Without this
        // guard the check below would match first and resolve them as an
        // internal 'worker' — a mode that, by design, "can never reach the
        // client-facing slice" — so adding a Seller to the team would quietly
        // take away their ability to message the client, breaking the Upseller
        // flow on that project. Mirrors the Seller exclusion that
        // ProjectChatService::syncFormalTeamParticipants() already applies for
        // the same reason.
        //
        // Gated on hasLinkedAccess() rather than the role alone so the change
        // only ever PRESERVES access: a Seller with no client-facing claim on
        // this project still falls through to 'internal' exactly as before,
        // instead of being left with no chat at all.
        $keepsClientFacingSlice = $user->role_type === 'seller' && $this->hasLinkedAccess($project);

        $isInternalMember = !$keepsClientFacingSlice
            && ($project->teamMembers()->where('user_id', $user->id)->exists()
                || $project->tasks()->where('assigned_to', $user->id)->exists());

        if ($isInternalMember) {
            if (!$this->can('canUseProjectChat')) {
                throw new HttpResponseException(ApiResponse::error('Permission denied', 403));
            }
            // Never 'bridge' — a Developer/Designer/QA/Production/Team Member
            // is internal-only regardless of any other permission they hold;
            // the client-facing slice is Seller/PM/Client/Admin territory.
            return [$project, 'internal', 'worker'];
        }

        if ($this->hasLinkedAccess($project)) {
            return [$project, 'linked', 'seller'];
        }

        throw new HttpResponseException(ApiResponse::error('Permission denied', 403));
    }

    // A Seller who is NOT a team member still gets the client-visible slice
    // of a project's chat if ANY of: the project is linked to their own lead
    // or client, they created/handed off the project, they were explicitly
    // added as a ChatParticipant (see addClientParticipant()), or they hold
    // the canViewLinkedProjectChat override (sees every project's
    // client-facing chat, no link required). canParticipateClientFacingProjectChat
    // is the hard, universal gate for touching visibility='client' messages
    // at all — a PM needs it too (see projectForChat()) — without it a
    // Seller has no access to this project's chat regardless of any link.
    private function hasLinkedAccess(Project $project): bool
    {
        if (!$this->can('canParticipateClientFacingProjectChat')) return false;
        if ($this->can('canViewLinkedProjectChat')) return true;
        if (!$this->can('canUseSalesChat', 'sales')) return false;

        $user = $this->user();

        if ($project->created_by === $user->id) return true;

        if ($project->lead_id && Lead::where('id', $project->lead_id)
                ->where(fn($q) => $q->where('assigned_to', $user->id)->orWhere('transferred_to', $user->id))
                ->exists()) {
            return true;
        }

        if ($project->client_id && Client::where('id', $project->client_id)->where('account_manager', $user->id)->exists()) {
            return true;
        }

        $thread = ChatThread::where('thread_type', 'project')->where('linked_to_id', $project->id)->first();
        return $thread && ChatParticipant::where('thread_id', $thread->id)->where('user_id', $user->id)->exists();
    }

    private function threadFor(Project $project): ChatThread
    {
        $thread = ChatThread::firstOrCreate(
            ['thread_type' => 'project', 'linked_to_id' => $project->id],
            ['company_id' => $project->company_id, 'title' => $project->name, 'created_by' => $this->user()->id]
        );

        ChatParticipant::firstOrCreate(
            ['thread_id' => $thread->id, 'user_id' => $this->user()->id],
            ['role' => 'member', 'joined_at' => now()]
        );

        return $thread;
    }

    public function index(int $projectId): JsonResponse
    {
        [$project, $mode, ] = $this->projectForChat($projectId);
        $thread = $this->threadFor($project);

        $q = ChatMessage::where('thread_id', $thread->id)->where('is_deleted', false);
        // 'linked' (Seller) only ever sees the client-facing slice — internal
        // production/dev discussion stays hidden. 'internal' (Developer/
        // Designer/QA/Production/Team Member, or a PM without the
        // client-facing permission) only ever sees internal messages — the
        // client-facing slice (Seller/Client conversation) stays hidden from
        // them too. Only 'bridge' (PM with the permission, or a company-wide
        // override) sees both, unfiltered.
        if ($mode === 'linked') {
            $q->where('visibility', 'client');
        } elseif ($mode === 'internal') {
            $q->where('visibility', 'internal');
        }

        $messages = $q->with(['sender:id,name', 'senderAdmin:id,name'])->orderBy('sent_at')->get();

        return ApiResponse::success(['thread_id' => $thread->id, 'messages' => $messages, 'access_mode' => $mode]);
    }

    public function store(Request $request, int $projectId): JsonResponse
    {
        [$project, $mode, $tier] = $this->projectForChat($projectId);

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

        // Seller -> Developer/Production direct chat is explicitly blocked
        // here, not just silently coerced — PM must bridge the two sides.
        if ($mode === 'linked' && ($validated['visibility'] ?? 'client') === 'internal') {
            return ApiResponse::error('Seller cannot chat directly with internal production team. Please contact the Project Manager.', 403);
        }
        // Symmetric block: a Developer/Designer/QA/Production/Team Member (or
        // a PM without the client-facing permission) cannot post into the
        // client-facing slice — that stays PM/Admin/Seller/Client territory.
        if ($mode === 'internal' && ($validated['visibility'] ?? 'internal') === 'client') {
            return ApiResponse::error('Only the Project Manager or Company Admin can post client-facing messages on this project.', 403);
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

        // 'linked' (Seller) always posts client-visible; 'internal' (worker
        // tier, or a client-permission-less PM) always posts internal; only
        // 'bridge' can choose per-message via the `visibility` field.
        $visibility = match ($mode) {
            'linked'   => 'client',
            'internal' => 'internal',
            default    => $validated['visibility'] ?? 'internal',
        };

        $message = ChatMessage::create(array_merge($storageFields, [
            'thread_id'        => $thread->id,
            'sender_id'        => $this->user()->id,
            'content'          => $validated['content'] ?? null,
            'message_type'     => $messageType,
            'visibility'       => $visibility,
            'attachment_name'  => $attachmentName,
            'sent_at'          => now(),
        ]));

        $thread->update(['last_message_at' => now()]);

        $this->notifyAndLog($project, $message, $this->user()->id);

        return ApiResponse::success($message->load('sender:id,name'), 'Message sent', 201);
    }

    // GET /user/projects/{projectId}/chat/{messageId}/attachment
    public function downloadAttachment(int $projectId, int $messageId): StreamedResponse
    {
        [$project, $mode] = $this->projectForChat($projectId);
        $thread  = ChatThread::where('thread_type', 'project')->where('linked_to_id', $project->id)->firstOrFail();

        $q = ChatMessage::where('thread_id', $thread->id)->whereNotNull('attachment_path');
        if ($mode === 'linked') {
            $q->where('visibility', 'client');
        } elseif ($mode === 'internal') {
            $q->where('visibility', 'internal');
        }
        $message = $q->findOrFail($messageId);

        return AttachmentStorageService::download(
            $message->storage_type ?? 'internal', $message->attachment_path, $message->attachment_name ?? 'attachment',
            null, $message->google_drive_file_id, $project->company_id
        );
    }

    // POST /user/projects/{projectId}/chat/participants — lets THIS project's
    // own PM explicitly grant a Seller access to its client-facing chat slice
    // even when they aren't otherwise linked via lead/client ownership. Backed
    // by the existing ChatParticipant fallback check in hasLinkedAccess() —
    // this is simply its write-side entry point. Structurally cannot be used
    // to add an internal Developer/Designer/QA/Production user into the
    // client-facing chat, and cannot be invoked by a Seller themselves.
    public function addClientParticipant(Request $request, int $projectId): JsonResponse
    {
        // Being the project's own PM is sufficient — no separate permission
        // toggle needed on top of already being the bridge tier for THIS project.
        [$project, $mode, $tier] = $this->projectForChat($projectId);
        if ($tier !== 'pm') {
            return ApiResponse::error('Permission denied', 403);
        }

        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
        ]);

        // ofCompany() (not a raw company_id match) so a Seller assigned to
        // more than one company can still be added when this project's
        // company is their secondary one.
        if (!User::ofCompany($project->company_id)->where('id', $validated['user_id'])->exists()) {
            return ApiResponse::error('Selected user does not belong to this company.', 422);
        }

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

    // Mirrors Api\Admin\ProjectChatController::notifyAndLog() — notifies
    // everyone actually in the thread (ChatParticipant) plus the PM/project
    // team (excluding the actor), since chat access isn't limited to formal
    // team members (e.g. a task-assignee reached this thread via canUseProjectChat).
    // "Notify Company Admin" needs no Notification row (Admin isn't a `users`
    // row) — the SystemAuditLog write below already surfaces in the Admin's bell.
    private function notifyAndLog(Project $project, ChatMessage $message, int $actorUserId): void
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
