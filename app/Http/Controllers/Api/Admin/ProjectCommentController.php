<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectComment;
use App\Models\ProjectCommentLike;
use App\Models\SystemAuditLog;
use App\Models\Task;
use App\Models\User;
use App\Services\AttachmentStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProjectCommentController extends Controller
{
    private function admin()   { return auth('admin')->user(); }
    private function adminName(): string { return trim((string) ($this->admin()->name ?? '')) ?: 'Admin'; }
    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    private function project(int $projectId): Project
    {
        return Project::whereIn('company_id', $this->companyIds())->findOrFail($projectId);
    }

    // Mirrors Api\User\ProjectCommentController::excludeSellers() — strips
    // any Seller-role user out of a notification recipient list. An
    // internal-visibility comment's notification carries a preview of the
    // comment body, so a Seller ending up in the recipients (e.g. recorded
    // as this project's project_manager_id via a handoff, or present in the
    // team-members notify-fallback) would leak internal content through the
    // notification bell even though index() correctly hides it from their
    // comment feed.
    private function excludeSellers($userIds)
    {
        $sellerIds = User::whereIn('id', collect($userIds)->filter())->where('role_type', 'seller')->pluck('id');
        return collect($userIds)->reject(fn ($id) => $sellerIds->contains($id))->values();
    }

    // Mirrors Api\User\ProjectCommentController::findSellerReplyThreadOwner()
    // — walks a seller_reply thread up to find the one Seller it actually
    // belongs to, since the immediate parent's author might be the PM
    // continuing the conversation rather than the Seller themselves.
    private function findSellerReplyThreadOwner(?ProjectComment $node): ?int
    {
        for ($depth = 0; $node && $depth < 50; $depth++) {
            if ($node->author_user_id && User::where('id', $node->author_user_id)->where('role_type', 'seller')->exists()) {
                return $node->author_user_id;
            }
            if ($node->visibility === 'internal' && !empty($node->mentions)) {
                $taggedSellerId = User::whereIn('id', $node->mentions)->where('role_type', 'seller')->value('id');
                if ($taggedSellerId) return $taggedSellerId;
            }
            $node = $node->parent_comment_id ? ProjectComment::find($node->parent_comment_id) : null;
        }
        return null;
    }

    // Admin can @mention anyone actually connected to this project — its PM,
    // team members, task-assignees, linked Seller/creator, or the linked
    // lead's assignee/transferee/client's account manager — same scope
    // Api\User\ProjectCommentController::project() uses to decide who's a
    // project member. An unrelated company user (e.g. staff on a completely
    // different project) never appears, even for Admin — this was previously
    // "any active company user", which leaked every unrelated Developer/
    // Designer/QA into the picker for a project they have nothing to do with.
    // $taskId: a Seller has nothing to do with a task's internal/production
    // work, so when this comment is scoped to a specific task, Seller-role
    // users are stripped out even from this project-scoped list.
    private function mentionCandidates(Project $project, string $visibility, ?int $taskId = null): array
    {
        $ids = collect([$project->project_manager_id, $project->seller_id, $project->created_by])
            ->merge($project->teamMembers()->pluck('user_id'))
            ->merge(Task::where('project_id', $project->id)->pluck('assigned_to'))
            ->filter()->unique();

        if ($project->lead_id) {
            $lead = Lead::find($project->lead_id);
            if ($lead) $ids = $ids->merge(collect([$lead->assigned_to, $lead->transferred_to])->filter());
        }
        if ($project->client_id) {
            $client = Client::find($project->client_id);
            if ($client?->account_manager) $ids->push($client->account_manager);
        }

        // role_type='client' is always excluded — these are Client Portal
        // login accounts (often auto-named after the lead they converted
        // from, e.g. "lead_7"), not staff. They have nothing to do with an
        // internal/admin comment thread, and showing up here — looking like
        // an actual Lead — is what was reported as "leads showing in the
        // mention picker". Unlike Sellers, Clients are never a valid mention
        // target anywhere else in this app either (see the User-guard's
        // mentionCandidates(), which never surfaces a Client-role user).
        $query = User::whereIn('id', $ids->unique())
            ->where('is_active', true)
            ->where('role_type', '!=', 'client');
        if ($taskId) {
            $query->where('role_type', '!=', 'seller');
        }

        return $query->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['user_id' => $u->id, 'name' => $u->name])
            ->values()->all();
    }

    // GET /admin/projects/{id}/mentionable-users?visibility=internal|client&task_id=
    public function mentionableUsers(Request $request, int $projectId): JsonResponse
    {
        $project = $this->project($projectId);
        $visibility = $request->get('visibility') === 'client' ? 'client' : 'internal';
        $taskId = $request->filled('task_id') ? (int) $request->task_id : null;

        return ApiResponse::success($this->mentionCandidates($project, $visibility, $taskId));
    }

    public function index(Request $request, int $projectId): JsonResponse
    {
        $project = $this->project($projectId);

        $q = ProjectComment::where('project_id', $project->id)
            ->with(['authorAdmin:id,name', 'authorUser:id,name,role_type', 'attachments.uploadedByAdmin:id,name', 'attachments.uploadedByUser:id,name', 'likes.user:id,name', 'likes.admin:id,name']);

        if ($request->filled('task_id')) {
            $q->where('task_id', $request->task_id);
        } else {
            // No task_id means "Project Overview" context — task-scoped
            // comments belong on their own Task page only, never here.
            $q->whereNull('task_id');
        }

        $comments = $q->orderByDesc('created_at')->get();
        $this->appendLikeFields($comments, $this->admin()->id);

        return ApiResponse::success($comments);
    }

    // Mirrors Api\User\ProjectCommentController::appendLikeFields() — attaches
    // likes_count/liked_by_me/liked_by to every comment (single or collection)
    // and drops the raw pivot rows once summarized.
    private function appendLikeFields($comments, int $adminId): void
    {
        $collection = $comments instanceof ProjectComment ? collect([$comments]) : $comments;

        foreach ($collection as $c) {
            $c->likes_count = $c->likes->count();
            $c->liked_by_me = $c->likes->contains(fn ($l) => $l->admin_id === $adminId);
            $c->liked_by = $c->likes->map(fn ($l) => $l->user?->name ?? $l->admin?->name ?? 'Someone')->values();
            unset($c->likes);
        }
    }

    public function store(Request $request, int $projectId): JsonResponse
    {
        $project = $this->project($projectId);

        if ($project->isDraft()) {
            return ApiResponse::error(Project::DRAFT_BLOCKED_MESSAGE, 422);
        }

        $validated = $request->validate([
            'task_id'              => ['nullable', 'integer', 'exists:tasks,id'],
            'deliverable_id'       => ['nullable', 'integer', 'exists:deliverables,id'],
            'parent_comment_id'    => ['nullable', 'integer', 'exists:project_comments,id'],
            'body'                 => ['required', 'string'],
            'visibility'           => ['nullable', 'in:internal,client'],
            'mentioned_user_ids'   => ['nullable', 'array'],
            'mentioned_user_ids.*' => ['integer'],
        ]);

        $parentComment = !empty($validated['parent_comment_id'])
            ? ProjectComment::where('project_id', $project->id)->find($validated['parent_comment_id'])
            : null;

        // Replying INTO an existing seller_reply thread must stay in that
        // same closed tier (PM/Admin + the one Seller only) — Admin bypasses
        // every other permission check, so unlike the User-guard controller
        // there's no eligibility gate needed here, just the tier itself.
        $isSellerThreadReply = $parentComment && $parentComment->visibility === 'seller_reply';
        $visibility = $isSellerThreadReply ? 'seller_reply' : ($validated['visibility'] ?? 'internal');

        // A task's comments are internal/production work — a Seller has
        // nothing to do with a task, so tagging one there is blocked outright
        // even for Admin, who otherwise bypasses every other tag restriction.
        if (!empty($validated['task_id'])) {
            $rawMentionIds = collect($validated['mentioned_user_ids'] ?? [])->map(fn ($id) => (int) $id);
            if ($rawMentionIds->isNotEmpty() && User::whereIn('id', $rawMentionIds)->where('role_type', 'seller')->exists()) {
                return ApiResponse::error('Seller cannot be tagged in task comments.', 403);
            }
        }

        // seller_reply has no mention picker — its audience is fixed
        // regardless of tagging, matching the User-guard controller.
        $mentions = collect();
        if ($visibility !== 'seller_reply') {
            $allowedIds = collect($this->mentionCandidates($project, $visibility, $validated['task_id'] ?? null))->pluck('user_id');
            $mentions = collect($validated['mentioned_user_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->filter(fn ($id) => $allowedIds->contains($id))
                ->values();
        }

        $comment = ProjectComment::create([
            'company_id'        => $project->company_id,
            'project_id'        => $project->id,
            'task_id'           => $validated['task_id'] ?? null,
            'deliverable_id'    => $validated['deliverable_id'] ?? null,
            'parent_comment_id' => $parentComment?->id,
            'author_admin_id'   => $this->admin()->id ?? null,
            'body'              => $validated['body'],
            'visibility'        => $visibility,
            'mentions'          => $mentions->isNotEmpty() ? $mentions->all() : null,
        ]);

        if ($isSellerThreadReply) {
            $this->notifySellerReplyFromAdmin($project, $comment, $parentComment);
        } else {
            $this->notifyAndLog($project, $comment, null);
            $this->notifyMentions($project, $comment, $mentions);
        }

        // Surface it in the task's own History feed too, not just the
        // comments panel — "who commented" per the task activity log.
        if ($comment->task_id) {
            $task = Task::find($comment->task_id);
            $task?->logActivity(
                'commented',
                "{$this->adminName()} commented: " . Str::limit($comment->body, 80),
                $this->adminName()
            );
        }

        $comment->load('authorAdmin:id,name', 'likes.user:id,name', 'likes.admin:id,name');
        $this->appendLikeFields($comment, $this->admin()->id);

        return ApiResponse::success($comment, 'Comment added', 201);
    }

    // PATCH /admin/projects/{projectId}/comments/{commentId} — Admin can only
    // edit comments THEY authored (author_admin_id === this admin), not
    // moderation-edit a staff member's comment — same restraint chat's
    // updateMessage() applies (delete is moderation-capable, edit isn't).
    public function update(Request $request, int $projectId, int $commentId): JsonResponse
    {
        $project = $this->project($projectId);
        $comment = ProjectComment::where('project_id', $project->id)->findOrFail($commentId);

        if ($comment->author_admin_id !== $this->admin()->id) {
            return ApiResponse::error('You can only edit your own comment.', 403);
        }

        $validated = $request->validate([
            'body' => ['required', 'string'],
        ]);

        $comment->update(['body' => $validated['body']]);

        $fresh = $comment->fresh()->load('authorAdmin:id,name', 'likes.user:id,name', 'likes.admin:id,name');
        $this->appendLikeFields($fresh, $this->admin()->id);

        return ApiResponse::success($fresh, 'Comment updated');
    }

    // DELETE /admin/projects/{projectId}/comments/{commentId} — Company Admin
    // can delete any comment (no authorship/moderator restriction, same as
    // everywhere else Admin bypasses per-user checks). Hard delete, mirroring
    // the User-guard controller's own destroy().
    public function destroy(int $projectId, int $commentId): JsonResponse
    {
        $project = $this->project($projectId);
        $comment = ProjectComment::where('project_id', $project->id)->with('attachments')->findOrFail($commentId);

        foreach ($comment->attachments as $attachment) {
            AttachmentStorageService::delete($attachment->storage_type, $attachment->file_path, $attachment->google_drive_file_id, $attachment->company_id);
        }

        $comment->delete();

        return ApiResponse::success(null, 'Comment deleted');
    }

    // Admin continuing a seller_reply thread — notifies the Seller who
    // actually owns it (found via findSellerReplyThreadOwner() rather than
    // trusting the immediate parent's author, which could be the PM
    // continuing the same thread rather than the Seller). Admin is never
    // the recipient here since Admin is always the actor in this
    // controller. The link points at the User-guard project page since the
    // recipient is a Seller, not another Admin.
    private function notifySellerReplyFromAdmin(Project $project, ProjectComment $comment, ?ProjectComment $parentComment): void
    {
        $authorName = $comment->authorAdmin?->name ?? 'Company Admin';
        $sellerId = $this->findSellerReplyThreadOwner($parentComment);

        if ($sellerId) {
            Notification::create([
                'user_id'    => $sellerId,
                'company_id' => $project->company_id,
                'type'       => 'seller_reply',
                'title'      => "New private reply on {$project->name}",
                'body'       => Str::limit($comment->body, 120) . " — {$authorName}",
                'data'       => [
                    'project_id' => $project->id,
                    'task_id'    => $comment->task_id,
                    'comment_id' => $comment->id,
                    'link'       => "/projects/{$project->id}",
                ],
            ]);
        }

        SystemAuditLog::create([
            'company_id'  => $project->company_id,
            'user_id'     => null,
            'action'      => 'seller_reply_added',
            'module_key'  => 'project_management',
            'entity_type' => 'Project',
            'entity_id'   => $project->id,
            'new_values'  => [
                'comment_id' => $comment->id,
                'preview'    => Str::limit($comment->body, 120),
                'project'    => $project->name,
                'author'     => $authorName,
            ],
        ]);
    }

    // POST /admin/projects/{projectId}/comments/{commentId}/like — Admin sees
    // every comment already, so unlike the User-guard version this needs no
    // visibility gate, just the toggle itself.
    public function toggleLike(int $projectId, int $commentId): JsonResponse
    {
        $project = $this->project($projectId);
        $comment = ProjectComment::where('project_id', $project->id)->findOrFail($commentId);
        $adminId = $this->admin()->id;

        $existing = ProjectCommentLike::where('comment_id', $comment->id)->where('admin_id', $adminId)->first();
        if ($existing) {
            $existing->delete();
            $liked = false;
        } else {
            ProjectCommentLike::create(['company_id' => $project->company_id, 'comment_id' => $comment->id, 'admin_id' => $adminId]);
            $liked = true;
            $this->notifyCommentLikedFromAdmin($project, $comment);
        }

        return ApiResponse::success([
            'liked'       => $liked,
            'likes_count' => ProjectCommentLike::where('comment_id', $comment->id)->count(),
        ]);
    }

    // Mirrors Api\User\ProjectCommentController::notifyCommentLiked() — never
    // on unlike, never a self-notify. A User author gets a real Notification
    // row; another Admin's comment (companies can have multiple Admin
    // accounts) has no `users` row, so a SystemAuditLog entry surfaces it on
    // their bell instead.
    private function notifyCommentLikedFromAdmin(Project $project, ProjectComment $comment): void
    {
        $admin = $this->admin();
        $likerName = $admin->name ?? 'Company Admin';

        if ($comment->author_user_id) {
            Notification::create([
                'user_id'    => $comment->author_user_id,
                'company_id' => $project->company_id,
                'type'       => 'comment_liked',
                'title'      => "Your comment got a like",
                'body'       => "{$likerName} liked your comment on {$project->name}",
                'data'       => [
                    'project_id' => $project->id,
                    'task_id'    => $comment->task_id,
                    'comment_id' => $comment->id,
                    'link'       => "/projects/{$project->id}",
                ],
            ]);
        } elseif ($comment->author_admin_id && $comment->author_admin_id !== $admin->id) {
            SystemAuditLog::create([
                'company_id'  => $project->company_id,
                'user_id'     => null,
                'action'      => 'comment_liked',
                'module_key'  => 'project_management',
                'entity_type' => 'Project',
                'entity_id'   => $project->id,
                'new_values'  => [
                    'comment_id' => $comment->id,
                    'preview'    => Str::limit($comment->body, 120),
                    'project'    => $project->name,
                    'liked_by'   => $likerName,
                ],
            ]);
        }
    }

    // Admin actor is never a `users` row, so unlike the User-guard controller
    // there's no self-mention case to guard against here.
    private function notifyMentions(Project $project, ProjectComment $comment, $mentionedIds): void
    {
        $authorName = $comment->authorAdmin?->name ?? 'Company Admin';

        foreach ($mentionedIds as $uid) {
            Notification::create([
                'user_id'    => $uid,
                'company_id' => $project->company_id,
                'type'       => 'mentioned_in_comment',
                'title'      => "You were mentioned on {$project->name}",
                'body'       => "{$authorName}: " . Str::limit($comment->body, 120),
                'data'       => [
                    'project_id' => $project->id,
                    'task_id'    => $comment->task_id,
                    'comment_id' => $comment->id,
                    'link'       => "/projects/{$project->id}",
                ],
            ]);
        }
    }

    // Notifies the relevant staff (PM / assigned user / production user) and
    // writes an activity-log row that also feeds the Company Admin's own
    // notification bell (Admin\NotificationController reads all SystemAuditLog
    // rows for the company, unfiltered by action). $actorUserId is null when
    // the comment's author is the Company Admin (never a `users` row).
    private function notifyAndLog(Project $project, ProjectComment $comment, ?int $actorUserId): void
    {
        $task = $comment->task_id ? Task::with('deliverables')->find($comment->task_id) : null;
        $pmId = $project->project_manager_id;
        $assigneeId = $task?->assigned_to;
        $productionUserId = $task?->production_assigned_to;
        $authorName = $comment->authorAdmin?->name ?? $comment->authorUser?->name ?? 'Someone';

        // Client-facing comments never route through the internal
        // PM/assignee/production notify logic below — notify PM + linked
        // Seller + the client themselves instead, per the Client
        // Communication Rules.
        if ($comment->visibility === 'client') {
            $this->notifyClientFacingComment($project, $comment, $actorUserId, $authorName);
            return;
        }

        // Admin author (never a `users` row) → notify PM + assignee + production user.
        $targets = [$pmId, $assigneeId, $productionUserId];

        $recipients = collect($targets)->filter()->unique()->reject(fn ($uid) => $uid === $actorUserId);

        // No PM/assignee/production user to target (e.g. unassigned project,
        // task-less comment) → fall back to the whole project team so the
        // comment doesn't go unnoticed by everyone.
        if ($recipients->isEmpty()) {
            $recipients = $project->teamMembers()->pluck('user_id')->unique()->reject(fn ($uid) => $uid === $actorUserId);
        }

        // This is an internal-visibility comment's notification — a Seller
        // must never be a recipient here, even incidentally (e.g. recorded
        // as project_manager_id via a handoff, or present in the
        // team-members fallback above) — mirrors Api\User\
        // ProjectCommentController::excludeSellers()'s reasoning.
        $recipients = $this->excludeSellers($recipients);

        foreach ($recipients as $uid) {
            Notification::create([
                'user_id'    => $uid,
                'company_id' => $project->company_id,
                'type'       => $task ? 'task_comment' : 'project_comment',
                'title'      => "New comment on {$project->name}" . ($task ? " · {$task->title}" : ''),
                'body'       => Str::limit($comment->body, 120) . " — {$authorName}",
                'data'       => [
                    'project_id' => $project->id,
                    'task_id'    => $comment->task_id,
                    'comment_id' => $comment->id,
                    'link'       => "/projects/{$project->id}",
                ],
            ]);
        }

        $action = !$task ? 'project_comment_added'
            : ($task->production_assigned_to || $task->task_type === 'production' ? 'production_comment_added'
                : ($task->deliverables->isNotEmpty() ? 'deliverable_comment_added' : 'task_comment_added'));

        SystemAuditLog::create([
            'company_id'  => $project->company_id,
            'user_id'     => $actorUserId,
            'action'      => $action,
            'module_key'  => 'project_management',
            'entity_type' => $comment->task_id ? 'Task' : 'Project',
            'entity_id'   => $comment->task_id ?? $project->id,
            'new_values'  => [
                'comment_id' => $comment->id,
                'preview'    => Str::limit($comment->body, 120),
                'project'    => $project->name,
                'task'       => $task?->title,
                'author'     => $authorName,
            ],
        ]);
    }

    // Client Communication Rules — a client-facing comment notifies the PM
    // and any linked Seller (never the wider internal/production team), plus
    // the client themselves. $actorUserId is null for an Admin-authored
    // comment (Admin already sees everything via the SystemAuditLog bell).
    private function notifyClientFacingComment(Project $project, ProjectComment $comment, ?int $actorUserId, string $authorName): void
    {
        $ids = collect([$project->project_manager_id, $project->seller_id, $project->created_by])->filter()->unique();

        if ($project->lead_id) {
            $lead = Lead::find($project->lead_id);
            if ($lead) $ids = $ids->merge(collect([$lead->assigned_to, $lead->transferred_to])->filter());
        }

        $client = $project->client_id ? Client::find($project->client_id) : null;
        if ($client?->account_manager) $ids->push($client->account_manager);

        $recipients = $ids->unique()->reject(fn ($uid) => $uid === $actorUserId);

        foreach ($recipients as $uid) {
            Notification::create([
                'user_id'    => $uid,
                'company_id' => $project->company_id,
                'type'       => 'client_facing_comment',
                'title'      => "New client-facing comment on {$project->name}",
                'body'       => Str::limit($comment->body, 120) . " — {$authorName}",
                'data'       => [
                    'project_id' => $project->id,
                    'task_id'    => $comment->task_id,
                    'comment_id' => $comment->id,
                    'link'       => "/projects/{$project->id}",
                ],
            ]);
        }

        if ($client?->user_id) {
            Notification::create([
                'user_id'    => $client->user_id,
                'company_id' => $project->company_id,
                'type'       => 'client_facing_comment_reply',
                'title'      => "New reply on {$project->name}",
                'body'       => Str::limit($comment->body, 120) . " — {$authorName}",
                'data'       => [
                    'project_id' => $project->id,
                    'task_id'    => $comment->task_id,
                    'comment_id' => $comment->id,
                    'link'       => "/client/projects/{$project->id}",
                ],
            ]);
        }

        SystemAuditLog::create([
            'company_id'  => $project->company_id,
            'user_id'     => $actorUserId,
            'action'      => 'client_facing_comment_added',
            'module_key'  => 'project_management',
            'entity_type' => 'Project',
            'entity_id'   => $project->id,
            'new_values'  => [
                'comment_id' => $comment->id,
                'preview'    => Str::limit($comment->body, 120),
                'project'    => $project->name,
                'author'     => $authorName,
            ],
        ]);
    }
}
