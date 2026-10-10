<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectComment;
use App\Models\ProjectCommentAttachment;
use App\Models\User;
use App\Models\UserCompanyPermission;
use App\Services\AttachmentStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Mirrors Api\User\TaskAttachmentController exactly, scoped by comment_id
// instead of task_id. Only the comment's own author can attach a file to it
// (post-hoc, right after posting) — viewing/downloading follows the same
// internal/client-facing visibility the comment itself already has.
class ProjectCommentAttachmentController extends Controller
{
    private const MAX_FILE_KB = 10240;
    private const ALLOWED_MIMES = 'pdf,doc,docx,xls,xlsx,png,jpg,jpeg,zip';

    private function user() { return auth('sanctum')->user(); }

    private function can(string $permKey): bool
    {
        $user = $this->user();
        return UserCompanyPermission::where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->where('module_key', 'project_management')
            ->where('permission_key', $permKey)
            ->exists();
    }

    private function isInternalStaff(): bool
    {
        return $this->can('canViewTasks') || $this->can('canViewAllCompanyProjects');
    }

    // Mirrors Api\User\ProjectCommentController::findSellerReplyThreadOwner()
    // — walks a seller_reply thread up to find the one Seller it actually
    // belongs to, since the comment being checked might be the PM's or
    // Admin's reply rather than the Seller's own message.
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

    // Mirrors Api\User\ProjectCommentController::isProjectPmTier() — this
    // project's literal assigned PM, OR a genuine Project Manager
    // (role_type='project_manager') with company-wide oversight
    // (canViewAllCompanyProjects) who isn't individually assigned to this
    // one project. Keyed off role_type, not just the permission alone, so a
    // Developer/Designer/QA/Production/Team Member holding that same
    // permission still doesn't qualify.
    private function isProjectPmTier(Project $project): bool
    {
        $user = $this->user();
        return $project->project_manager_id === $user->id
            || ($user->role_type === 'project_manager' && $this->can('canViewAllCompanyProjects'));
    }

    private function visibleProject(int $projectId): Project
    {
        $user = $this->user();
        $base = Project::where('company_id', $user->company_id);

        if ($this->can('canViewAllCompanyProjects')) {
            return $base->findOrFail($projectId);
        }

        return $base->where(function ($q) use ($user) {
                $q->where('project_manager_id', $user->id)
                  ->orWhere('created_by', $user->id)
                  ->orWhere('seller_id', $user->id)
                  ->orWhereHas('teamMembers', fn ($t) => $t->where('user_id', $user->id))
                  ->orWhereHas('tasks', fn ($t) => $t->where('assigned_to', $user->id))
                  ->orWhereHas('lead', fn ($l) => $l->where('assigned_to', $user->id)->orWhere('transferred_to', $user->id))
                  ->orWhereHas('client', fn ($c) => $c->where('account_manager', $user->id));
            })
            ->findOrFail($projectId);
    }

    // A comment this user can actually see — internal-visibility comments
    // stay hidden from a non-internal actor, same rule ProjectCommentController
    // already enforces on index(), including its one exception: a Seller
    // validly tagged into this specific internal comment (via mentions) can
    // still reach its attachments, same as they can reach the comment itself.
    // seller_reply attachments are narrower still — only the PM/moderator or
    // the Seller who wrote the reply, never the rest of the internal team.
    private function visibleComment(int $projectId, int $commentId): ProjectComment
    {
        $project = $this->visibleProject($projectId);
        $comment = ProjectComment::where('project_id', $project->id)->findOrFail($commentId);
        $userId = $this->user()->id;

        $isTagged = in_array($userId, $comment->mentions ?? [], true);
        if ($comment->visibility === 'internal' && !$this->isInternalStaff() && !$isTagged) {
            abort(404);
        }

        // A Seller can see every message in THEIR OWN seller_reply thread,
        // not just rows they personally authored — findSellerReplyThreadOwner()
        // walks the parent chain to identify whose thread this is, since a
        // PM's/Admin's reply back has a different author_user_id (or none at
        // all for Admin) than the Seller's own messages in that same thread.
        $isProjectPm = $this->isProjectPmTier($project);
        if ($comment->visibility === 'seller_reply' && !$isProjectPm && $this->findSellerReplyThreadOwner($comment) !== $userId) {
            abort(404);
        }

        // A PM/Admin comment that tags a Seller is part of that Seller
        // conversation, even stored as visibility=internal — hidden from
        // the wider internal team the same way the reply already is,
        // mirroring index()'s post-fetch filter.
        if ($comment->visibility === 'internal' && $this->isInternalStaff() && !$isProjectPm && !empty($comment->mentions)) {
            $taggedSeller = User::whereIn('id', $comment->mentions)->where('role_type', 'seller')->exists();
            if ($taggedSeller) {
                abort(404);
            }
        }

        // Client-facing (Seller<->PM/Admin/Client) attachments are also
        // Company Admin/PM territory, same as the comment itself now is —
        // regular internal staff never had access to the comment's client
        // context, so they shouldn't reach its attachments either.
        if ($comment->visibility === 'client' && $this->isInternalStaff() && !$isProjectPm) {
            abort(404);
        }

        return $comment;
    }

    public function index(int $projectId, int $commentId): JsonResponse
    {
        $comment = $this->visibleComment($projectId, $commentId);

        $attachments = ProjectCommentAttachment::where('comment_id', $comment->id)
            ->with(['uploadedByAdmin:id,name', 'uploadedByUser:id,name'])
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success($attachments);
    }

    public function store(Request $request, int $projectId, int $commentId): JsonResponse
    {
        $comment = $this->visibleComment($projectId, $commentId);

        if ($comment->author_user_id !== $this->user()->id) {
            return ApiResponse::error('You can only attach files to your own comment.', 403);
        }

        $project = $comment->project;

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:' . AttachmentStorageService::maxUploadKb($project->company_id, self::MAX_FILE_KB), 'mimes:' . self::ALLOWED_MIMES],
        ]);

        $file = $validated['file'];
        $folder = ($project->storage_folder ?: 'companies/' . $project->company_id . '/projects/' . $project->id) . '/comments/' . $comment->id . '/attachments';

        $stored = AttachmentStorageService::store($file, $project->company_id, 'project_comments', $folder);
        if (!$stored) {
            return AttachmentStorageService::errorResponse($project->company_id);
        }

        $attachment = ProjectCommentAttachment::create(array_merge($stored->locationAttributes(), [
            'company_id'           => $project->company_id,
            'comment_id'           => $comment->id,
            'uploaded_by_admin_id' => null,
            'uploaded_by_user_id'  => $this->user()->id,
            'original_name'        => $stored->originalName,
            'file_name'            => $stored->fileName,
            'file_type'            => $stored->mimeType,
            'file_size'            => $stored->sizeBytes,
        ]));

        return ApiResponse::success($attachment->load('uploadedByUser:id,name'), 'Attachment uploaded', 201);
    }

    public function download(int $projectId, int $commentId, int $id): StreamedResponse
    {
        $comment = $this->visibleComment($projectId, $commentId);
        $attachment = ProjectCommentAttachment::where('comment_id', $comment->id)->findOrFail($id);

        return AttachmentStorageService::download(
            $attachment->storage_type, $attachment->file_path, $attachment->original_name,
            $attachment->file_type, $attachment->google_drive_file_id, $attachment->company_id
        );
    }
}
