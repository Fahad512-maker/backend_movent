<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectAttachment;
use App\Models\UserCompanyPermission;
use App\Services\AttachmentStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectAttachmentController extends Controller
{
    private const MAX_FILE_KB = 10240; // 10 MB per file
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

    // Mirrors Api\User\ProjectController::visibleProjects()'s exact scope
    // (manager/team-member/task-assignee/seller-assignee OR canViewAllCompanyProjects).
    // seller_id is included so store()'s canUploadProjectAttachments actually
    // resolves a Seller's own linked project (e.g. one an Admin assigned
    // seller_id on directly, with no project_manager_id/team/task match) —
    // without it, upload 404'd even with the permission granted. index()/
    // download() never reach this for a Seller (they branch to
    // sellerVisibleProject()'s "visible to client" tiering first), so this
    // widening only ever affects the upload path.
    private function visibleProject(int $projectId): Project
    {
        $user = $this->user();
        $base = Project::where('company_id', $user->company_id);

        if ($this->can('canViewAllCompanyProjects')) {
            return $base->findOrFail($projectId);
        }

        return $base->where(function ($q) use ($user) {
                $q->where('project_manager_id', $user->id)
                  ->orWhere('seller_id', $user->id)
                  ->orWhereHas('teamMembers', fn ($t) => $t->where('user_id', $user->id))
                  ->orWhereHas('tasks', fn ($t) => $t->where('assigned_to', $user->id));
            })
            ->findOrFail($projectId);
    }

    // A Seller's own linked projects only (seller_id/created_by/lead/client —
    // same legs Api\User\ProjectController::visibleProjects() adds beyond
    // the plain-staff scope above). Never combined with
    // canViewAllCompanyProjects — a Seller granted that permission still
    // only ever reaches their own linked projects here, same guard rail as
    // everywhere else a Seller's access is scoped.
    private function sellerVisibleProject(int $projectId): Project
    {
        $user = $this->user();

        return Project::where('company_id', $user->company_id)
            ->where(function ($q) use ($user) {
                $q->where('seller_id', $user->id)
                  ->orWhere('created_by', $user->id)
                  ->orWhereHas('lead', fn ($l) => $l->where('assigned_to', $user->id)->orWhere('transferred_to', $user->id))
                  ->orWhereHas('client', fn ($c) => $c->where('account_manager', $user->id));
            })
            ->findOrFail($projectId);
    }

    // GET /user/projects/{projectId}/attachments
    public function index(int $projectId): JsonResponse
    {
        // A Seller never gets the full, untiered attachment list on a
        // project a real, different PM actually runs (that would expose
        // internal project files) — only ever the subset an Admin/PM
        // explicitly marked "Visible to client". No canViewProjectAttachments
        // permission needed or checked for this narrower path. EXCEPTION: a
        // Seller who is ALSO this project's own PM (a self-created/
        // self-handoff project with nobody else appointed) sees everything —
        // there's no separate internal team to hide files from, they ARE the
        // whole team, and without this they couldn't even see a file they
        // just uploaded themselves.
        if ($this->user()->role_type === 'seller') {
            $project = $this->sellerVisibleProject($projectId);

            $query = ProjectAttachment::where('project_id', $project->id);
            if ((int) $project->project_manager_id !== $this->user()->id) {
                $query->where('is_visible_to_client', true);
            }

            $attachments = $query
                ->with(['uploadedByAdmin:id,name', 'uploadedByUser:id,name'])
                ->orderByDesc('created_at')
                ->get();

            return ApiResponse::success($attachments);
        }

        if (!$this->can('canViewProjectAttachments')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $project = $this->visibleProject($projectId);

        $attachments = ProjectAttachment::where('project_id', $project->id)
            ->with(['uploadedByAdmin:id,name', 'uploadedByUser:id,name'])
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success($attachments);
    }

    // POST /user/projects/{projectId}/attachments (multipart/form-data, one file per request)
    public function store(Request $request, int $projectId): JsonResponse
    {
        $project = $this->visibleProject($projectId);

        if ($project->isDraft()) {
            return ApiResponse::error(Project::DRAFT_BLOCKED_MESSAGE, 422);
        }

        if ($project->isLocked()) {
            return ApiResponse::error(Project::LOCKED_MESSAGE, 422);
        }

        // Upload (and the visibility it grants over a file) is Admin/PM
        // territory — hard-restricted to this project's actual assigned PM,
        // regardless of who else was left holding canUploadProjectAttachments
        // in their role bundle. A Seller keeps their separate, deliberate
        // upload-on-own-project allowance. A real Project Manager with
        // company-wide project access is also PM-tier for project files —
        // and so is one added via the team (e.g. a Seller's "Invite PM",
        // which writes a project_team_members row without ever touching
        // project_manager_id) — mirrors the frontend's isProjectPmTier.
        $isSeller = $this->user()->role_type === 'seller';
        $isPmTier = (int) $project->project_manager_id === (int) $this->user()->id
            || ($this->user()->role_type === 'project_manager' && (
                $this->can('canViewAllCompanyProjects')
                || $project->teamMembers()->where('user_id', $this->user()->id)->exists()
            ));
        $canUploadFiles = $this->can('canUploadProjectAttachments')
            || (!$isSeller && $isPmTier && $this->can('canEditProjects'));
        if (!$canUploadFiles) {
            return ApiResponse::error('Permission denied', 403);
        }
        if (!$isSeller && !$isPmTier) {
            return ApiResponse::error('Permission denied', 403);
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:' . AttachmentStorageService::maxUploadKb($project->company_id, self::MAX_FILE_KB), 'mimes:' . self::ALLOWED_MIMES],
        ]);

        $file = $validated['file'];
        $folder = ($project->storage_folder ?: 'companies/' . $project->company_id . '/projects/' . $project->id) . '/attachments';

        $stored = AttachmentStorageService::store($file, $project->company_id, 'projects', $folder);
        if (!$stored) {
            return AttachmentStorageService::errorResponse($project->company_id);
        }

        $attachment = ProjectAttachment::create(array_merge($stored->locationAttributes(), [
            'company_id'            => $project->company_id,
            'project_id'            => $project->id,
            'uploaded_by_admin_id'  => null,
            'uploaded_by_user_id'   => $this->user()->id,
            'original_name'         => $stored->originalName,
            'file_name'             => $stored->fileName,
            'file_type'             => $stored->mimeType,
            'file_size'             => $stored->sizeBytes,
            'is_visible_to_client'  => true,
        ]));

        return ApiResponse::success($attachment->load('uploadedByUser:id,name'), 'Attachment uploaded', 201);
    }

    // GET /user/projects/{projectId}/attachments/{id}/download
    public function download(int $projectId, int $id): StreamedResponse
    {
        // Same tiering as index() (including the "Seller is also this
        // project's own PM" exception) — a Seller can only ever download an
        // attachment they were actually shown the listing for, never any
        // other attachment id on the project by guessing.
        if ($this->user()->role_type === 'seller') {
            $project = $this->sellerVisibleProject($projectId);

            $query = ProjectAttachment::where('project_id', $project->id);
            if ((int) $project->project_manager_id !== $this->user()->id) {
                $query->where('is_visible_to_client', true);
            }

            $attachment = $query->findOrFail($id);

            return $this->respond($attachment);
        }

        if (!$this->can('canDownloadProjectAttachments')) {
            abort(403, 'Permission denied');
        }

        $project = $this->visibleProject($projectId);

        $attachment = ProjectAttachment::where('project_id', $project->id)->findOrFail($id);

        return $this->respond($attachment);
    }

    private function respond(ProjectAttachment $attachment): StreamedResponse
    {
        return AttachmentStorageService::download(
            $attachment->storage_type, $attachment->file_path, $attachment->original_name,
            $attachment->file_type, $attachment->google_drive_file_id, $attachment->company_id
        );
    }

    // DELETE /user/projects/{projectId}/attachments/{id}
    public function destroy(int $projectId, int $id): JsonResponse
    {
        // Same hard role_type block as index()/download() — a Seller never
        // gets full project attachment access, delete included, even if a
        // Company Admin mistakenly grants both canDeleteProjectAttachments
        // and canViewAllCompanyProjects (which would otherwise let
        // visibleProject() below resolve ANY company project, not just
        // their own linked ones, defeating the "Visible to client" tiering
        // index()/download() enforce).
        if ($this->user()->role_type === 'seller') {
            return ApiResponse::error('Permission denied', 403);
        }

        if (!$this->can('canDeleteProjectAttachments')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $project = $this->visibleProject($projectId);

        if ($project->isLocked()) {
            return ApiResponse::error(Project::LOCKED_MESSAGE, 422);
        }

        $attachment = ProjectAttachment::where('project_id', $project->id)->findOrFail($id);
        AttachmentStorageService::delete($attachment->storage_type, $attachment->file_path, $attachment->google_drive_file_id, $attachment->company_id);
        $attachment->delete();

        return ApiResponse::success(null, 'Attachment deleted');
    }
}
