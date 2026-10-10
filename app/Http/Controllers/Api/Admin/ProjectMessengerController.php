<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatThread;
use App\Models\Notification;
use App\Models\Project;
use App\Models\User;
use App\Services\AttachmentStorageService;
use App\Services\ProjectChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Project-wise messenger — ONE chat thread per project (see ProjectChatService
// and the 2026_08_10_10* migrations that merged historical duplicate
// group/direct threads and added a DB-level unique constraint). Distinct
// from the older, dormant single-thread Api\Admin\ProjectChatController
// (thread_type='project', never wired into any frontend page — left
// untouched). Company Admin is never a chat_participants row (same
// convention as GeneralChatController) — Admin sees/manages every project's
// chat unconditionally.
class ProjectMessengerController extends Controller
{
    private const MAX_FILE_KB = 10240;
    private const ALLOWED_MIMES = 'pdf,doc,docx,xls,xlsx,png,jpg,jpeg,zip';

    private function admin() { return auth('admin')->user(); }

    private function project(int $projectId): Project
    {
        $companyIds = $this->admin()->companies()->pluck('id');
        return Project::whereIn('company_id', $companyIds)->findOrFail($projectId);
    }

    // Same check as Api\User\ProjectMessengerController::isProjectPmUser() —
    // literally THIS project's Project Manager, never a Seller even one
    // recorded as project_manager_id via a handoff. Needed here only to
    // recognize "Admin tagged the project's own PM" for notifyMessage()'s
    // Seller-notification gate below.
    private function isProjectPmUser(Project $project, int $userId): bool
    {
        if (User::find($userId)?->role_type === 'seller') {
            return false;
        }
        return $project->project_manager_id === $userId
            || $project->teamMembers()->where('user_id', $userId)->where('role_in_project', 'project_manager')->exists();
    }

    // Users formally tied to the project: PM, project team members, task
    // assignees, and each task's production handoff assignee. Sellers are
    // handled separately (see eligibleParticipants) since manually adding
    // any company Seller is itself a valid path, not just a linked one.
    private function projectMemberIds(Project $project)
    {
        $teamIds = $project->teamMembers()->pluck('user_id');
        $taskAssigneeIds = $project->tasks()->whereNotNull('assigned_to')->pluck('assigned_to');
        $productionAssigneeIds = $project->tasks()->whereNotNull('production_assigned_to')->pluck('production_assigned_to');

        return collect([$project->project_manager_id])
            ->merge($teamIds)->merge($taskAssigneeIds)->merge($productionAssigneeIds)
            ->filter()->unique()->values();
    }

    // Company Admin is always privileged, so a Seller sharing this thread
    // with the rest of the team never needs to be excluded here the way it
    // once did — see the User-guard controller's canUserViewMessage() for
    // the actual isolation rule (a Seller only ever sees their own messages
    // plus anything Company Admin/PM explicitly @mentions them in; everyone
    // else never sees a Seller's message). Admin's messages()/send() stay
    // unrestricted on both sides of that rule by construction: Admin always
    // sees everything, and can always @mention anyone including a Seller.

    // GET /admin/projects/{projectId}/messenger — the project's one chat.
    public function show(int $projectId): JsonResponse
    {
        $project = $this->project($projectId);
        $thread = ProjectChatService::threadFor($project);
        ProjectChatService::syncFormalTeamParticipants($project, $thread);

        // Brings the project's Client into this SAME thread the first time
        // Admin opens it, instead of the separate "Chat with Client" thread
        // (retired, but its data stays intact for history — see
        // Api\Client\ProjectChatController). A Client here only ever sees
        // visibility='client' messages (Api\Client\ProjectChatController
        // filters strictly on that) — being a participant only makes them
        // @mentionable and notification-eligible, not able to read the
        // internal thread.
        $clientUserId = \App\Services\ProjectClientChatService::clientUserId($project);
        if ($clientUserId) {
            ProjectChatService::addClient($project, $clientUserId);
        }

        $thread->load('participants.user:id,name,role_type');

        return ApiResponse::success([
            'thread' => [
                'id'           => $thread->id,
                'visibility'   => $thread->visibility,
                'participants' => $thread->participants->map(fn ($p) => [
                    'user_id' => $p->user_id, 'name' => $p->user?->name, 'role' => $p->user?->role_type,
                ])->values(),
            ],
        ]);
    }

    // GET /admin/projects/{projectId}/messenger/eligible-participants —
    // active users actually tied to this project only: PM, team members,
    // task/production assignees, plus this project's own linked Seller
    // (project.seller_id) if one is assigned. An unrelated company Seller
    // never appears — chat access follows actual project assignment, not
    // "any Seller can be manually pulled in".
    public function eligibleParticipants(int $projectId): JsonResponse
    {
        $project = $this->project($projectId);
        $memberIds = $this->projectMemberIds($project);

        $users = User::ofCompany($project->company_id)
            ->where('is_active', true)
            ->where(function ($q) use ($memberIds, $project) {
                $q->whereIn('id', $memberIds);
                if ($project->seller_id) $q->orWhere('id', $project->seller_id);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'role_type'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'role_type' => $u->role_type, 'is_seller' => $u->role_type === 'seller']);

        return ApiResponse::success($users);
    }

    // POST /admin/projects/{projectId}/messenger/participants { user_id }
    public function addParticipant(Request $request, int $projectId): JsonResponse
    {
        $project = $this->project($projectId);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
        ]);
        $targetId = (int) $validated['user_id'];

        // ofCompany() (not a raw company_id match) so a user assigned to
        // more than one company can still be added when this project's
        // company is their secondary one, not just whichever company their
        // users.company_id column happens to point at.
        if (!User::ofCompany($project->company_id)->where('id', $targetId)->exists()) {
            return ApiResponse::error('Selected user does not belong to this company.', 422);
        }

        $thread = ProjectChatService::threadFor($project);
        $this->addParticipants($project, $thread, [$targetId], null);

        return ApiResponse::success(null, 'Participant added');
    }

    // DELETE /admin/projects/{projectId}/messenger/participants/{userId}
    public function removeParticipant(int $projectId, int $userId): JsonResponse
    {
        $project = $this->project($projectId);
        $thread = ProjectChatService::existingThreadFor($project);

        if ($thread) {
            ChatParticipant::where('thread_id', $thread->id)->where('user_id', $userId)->delete();
        }

        return ApiResponse::success(null, 'Participant removed');
    }

    // PATCH /admin/projects/{projectId}/messenger/participants/{userId}/mute
    public function muteParticipant(int $projectId, int $userId): JsonResponse
    {
        $project = $this->project($projectId);
        $thread = ProjectChatService::threadFor($project);

        $participant = ChatParticipant::where('thread_id', $thread->id)->where('user_id', $userId)->firstOrFail();
        $participant->update(['muted_at' => $participant->muted_at ? null : now()]);

        return ApiResponse::success(['is_muted' => $participant->muted_at !== null]);
    }

    public function messages(int $projectId): JsonResponse
    {
        $project = $this->project($projectId);
        $thread = ProjectChatService::threadFor($project);

        $messages = ChatMessage::where('thread_id', $thread->id)
            ->where('is_deleted', false)
            ->with(['sender:id,name', 'senderAdmin:id,name'])
            ->orderBy('sent_at')
            ->get();

        return ApiResponse::success(['messages' => $messages]);
    }

    public function send(Request $request, int $projectId): JsonResponse
    {
        $project = $this->project($projectId);

        if ($project->isDraft()) {
            return ApiResponse::error(Project::DRAFT_BLOCKED_MESSAGE, 422);
        }

        $thread = ProjectChatService::threadFor($project);

        $validated = $request->validate([
            'content'     => ['nullable', 'string', 'max:2000'],
            'file'        => ['nullable', 'file', 'max:' . AttachmentStorageService::maxUploadKb($project->company_id, self::MAX_FILE_KB), 'mimes:' . self::ALLOWED_MIMES],
            'mentions'    => ['nullable', 'array'],
            'mentions.*'  => ['integer'],
        ]);

        if (empty($validated['content']) && !$request->hasFile('file')) {
            return ApiResponse::error('Message or attachment is required.', 422);
        }

        // Mention candidates are restricted to the thread's current
        // participants — this is what enforces the Seller tag-rule for free
        // (a Seller can only be a participant via a PM/Admin add, so
        // mentioning them here means they were already vetted).
        $participantIds = ChatParticipant::where('thread_id', $thread->id)->pluck('user_id');
        $mentions = collect($validated['mentions'] ?? [])->unique()->filter(fn ($id) => $participantIds->contains($id))->values();

        // Auto-computed visibility, replacing the old manual "visible to
        // client" toggle: Company Admin's plain, untagged message is
        // promoted to visibility='client' — seen by the Client and this
        // project's Seller alike (mirrors the Seller's own untagged message
        // reaching the Client — see the User-guard controller's send()).
        // Tagging someone makes it a targeted, internal exchange instead.
        $visibility = $mentions->isEmpty() ? 'client' : 'internal';

        $attachmentName = null;
        $messageType    = 'text';
        $storageFields  = [];

        if ($request->hasFile('file')) {
            $file = $validated['file'];
            $folder = ($project->storage_folder ?: 'companies/' . $project->company_id . '/projects/' . $project->id) . '/messenger';
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
            'visibility'      => $visibility,
            'attachment_name' => $attachmentName,
            'sent_at'         => now(),
        ]));

        $thread->update(['last_message_at' => now()]);
        $this->notifyMessage($project, $thread, $message, null, $mentions);

        return ApiResponse::success($message->load('senderAdmin:id,name'), 'Message sent', 201);
    }

    // DELETE — Admin can delete ANY message in a project's chat, matching
    // GeneralChatController's unrestricted admin delete.
    public function deleteMessage(int $projectId, int $messageId): JsonResponse
    {
        $project = $this->project($projectId);
        $thread = ProjectChatService::threadFor($project);

        $message = ChatMessage::where('thread_id', $thread->id)->where('is_deleted', false)->findOrFail($messageId);
        $message->update(['is_deleted' => true]);

        return ApiResponse::success(null, 'Message deleted');
    }

    // PATCH — own message only, no moderator override, same as
    // Api\Admin\GeneralChatController::updateMessage() — editing someone
    // else's words is a different concern than removing them.
    public function updateMessage(Request $request, int $projectId, int $messageId): JsonResponse
    {
        $project = $this->project($projectId);
        $thread = ProjectChatService::threadFor($project);

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

    public function downloadAttachment(int $projectId, int $messageId): StreamedResponse
    {
        $project = $this->project($projectId);
        $thread = ProjectChatService::threadFor($project);

        $message = ChatMessage::where('thread_id', $thread->id)->whereNotNull('attachment_path')->findOrFail($messageId);

        return AttachmentStorageService::download(
            $message->storage_type ?? 'internal', $message->attachment_path, $message->attachment_name ?? 'attachment',
            null, $message->google_drive_file_id, $project->company_id
        );
    }

    // Adds participants + fires the "added to project chat" notification.
    // $addedByUserId is null when Company Admin performed the add (Admin
    // isn't a `users` row — same convention as ProjectTeamMember.assigned_by).
    private function addParticipants(Project $project, ChatThread $thread, array $userIds, ?int $addedByUserId): void
    {
        $actorName = $addedByUserId ? (User::find($addedByUserId)?->name ?? 'Someone') : ($this->admin()->name ?? 'Company Admin');

        foreach ($userIds as $userId) {
            $participant = ChatParticipant::firstOrCreate(
                ['thread_id' => $thread->id, 'user_id' => $userId],
                ['role' => 'member', 'added_by' => $addedByUserId, 'joined_at' => now()]
            );

            if (!$participant->wasRecentlyCreated) continue;

            // The Client Portal is a separate app tree with its own auth
            // cookie — the staff route below would 401 there (no staff
            // session token to send) and trip the staff axios interceptor's
            // force-logout-on-401 handling.
            $link = User::find($userId)?->role_type === 'client'
                ? ProjectChatService::clientChatLink($project->id)
                : "/projects/{$project->id}/chat";

            Notification::create([
                'user_id'    => $userId,
                'company_id' => $project->company_id,
                'type'       => 'project_chat_added',
                'title'      => "Added to project chat — {$project->name}",
                'body'       => "{$actorName} added you to project chat for '{$project->name}'.",
                'data'       => [
                    'project_id' => $project->id,
                    'thread_id'  => $thread->id,
                    'link'       => $link,
                ],
            ]);
        }
    }

    private function notifyMessage(Project $project, ChatThread $thread, ChatMessage $message, ?int $actorUserId, $mentionIds = null): void
    {
        $senderName = $message->senderAdmin?->name ?? $message->sender?->name ?? 'Someone';
        $preview = $message->content
            ? Str::limit($message->content, 120)
            : '📎 ' . ($message->attachment_name ?? 'sent an attachment');

        $recipients = ChatParticipant::where('thread_id', $thread->id)
            ->whereNull('muted_at')
            ->when($actorUserId, fn ($q) => $q->where('user_id', '!=', $actorUserId))
            ->with('user:id,role_type')
            ->get();

        $mentioned = collect($mentionIds ?? []);
        // Admin tagging the project's own PM is itself Seller-relevant — same
        // "Admin/Seller/PM is one triangle" reasoning as the User-guard
        // controller's canUserViewMessage() seller branch — so it counts as
        // a reason for the Seller to be notified too, even though only the
        // PM was named.
        $mentionsProjectPm = $mentioned->contains(fn ($id) => $this->isProjectPmUser($project, $id));

        foreach ($recipients as $participant) {
            // A Seller only ever sees messages Company Admin/PM explicitly
            // tag them (or the project's PM) in, OR a visibility='client'
            // message — Admin's own plain, untagged message is auto-promoted
            // to visibility='client' (see send()) and is meant for the
            // Seller and the Client alike. Skip the general "new message"
            // notice only when none of that applies (see the User-guard
            // controller's canUserViewMessage() for the full rule; Admin's
            // own messages are always privileged, so this is the only branch
            // of that rule Admin's side ever needs).
            if ($participant->user?->role_type === 'seller'
                && $message->visibility !== 'client'
                && !$mentioned->contains($participant->user_id)
                && !$mentionsProjectPm) {
                continue;
            }

            // A Client participant (see ProjectChatService::addClient()) only
            // ever sees visibility='client' messages (enforced in
            // Api\Client\ProjectChatController) — skip the "new message"
            // notice for an internal one, or it'd ping them about team
            // chatter they can never actually open.
            if ($participant->user?->role_type === 'client' && $message->visibility !== 'client') {
                continue;
            }

            // A plain team member (Developer/QA/Designer/Production/Team
            // Member) only ever sees Company Admin's messages if explicitly
            // @mentioned in them (see the User-guard controller's
            // canUserViewMessage()) — skip the notice for Admin's general
            // broadcast, or it'd ping them about a message they then can't
            // actually open. The literal PM is the one exception: they see
            // every Admin message unconditionally.
            if ($participant->user?->role_type !== 'seller'
                && $participant->user?->role_type !== 'client'
                && !$this->isProjectPmUser($project, $participant->user_id)
                && !$mentioned->contains($participant->user_id)) {
                continue;
            }

            // The Client Portal is a separate app tree with its own auth
            // cookie — the staff route below would 401 there (no staff
            // session token to send) and trip the staff axios interceptor's
            // force-logout-on-401 handling.
            $link = $participant->user?->role_type === 'client'
                ? ProjectChatService::clientChatLink($project->id)
                : "/projects/{$project->id}/chat";

            Notification::create([
                'user_id'    => $participant->user_id,
                'company_id' => $project->company_id,
                'type'       => 'project_chat_message',
                'title'      => "New message on {$project->name}",
                'body'       => "{$senderName}: {$preview}",
                'data'       => ['project_id' => $project->id, 'thread_id' => $thread->id, 'message_id' => $message->id, 'link' => $link],
            ]);
        }

        foreach (collect($mentionIds ?? [])->reject(fn ($id) => $id === $actorUserId) as $uid) {
            $targetIsClient = User::find($uid)?->role_type === 'client';

            // Same rule as the general recipient loop above — being
            // @mentioned doesn't override visibility for the Client.
            if ($targetIsClient && $message->visibility !== 'client') {
                continue;
            }

            // The Client Portal is a separate app tree with its own auth
            // cookie — the staff route below would 401 there (no staff
            // session token to send) and trip the staff axios interceptor's
            // force-logout-on-401 handling.
            $link = $targetIsClient
                ? ProjectChatService::clientChatLink($project->id)
                : "/projects/{$project->id}/chat";

            Notification::create([
                'user_id'    => $uid,
                'company_id' => $project->company_id,
                'type'       => 'mentioned_in_project_chat',
                'title'      => "You were mentioned on {$project->name}",
                'body'       => "{$senderName}: {$preview}",
                'data'       => ['project_id' => $project->id, 'thread_id' => $thread->id, 'message_id' => $message->id, 'link' => $link],
            ]);
        }
    }
}
