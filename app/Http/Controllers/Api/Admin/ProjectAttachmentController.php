<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectAttachment;
use App\Services\AttachmentStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectAttachmentController extends Controller
{
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

    // GET /admin/projects/{projectId}/attachments
    public function index(int $projectId): JsonResponse
    {
        $project = $this->project($projectId);

        $attachments = ProjectAttachment::where('project_id', $project->id)
            ->with(['uploadedByAdmin:id,name', 'uploadedByUser:id,name'])
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success($attachments);
    }

    // POST /admin/projects/{projectId}/attachments (multipart/form-data, one file per request)
    public function store(Request $request, int $projectId): JsonResponse
    {
        $project = $this->project($projectId);

        if ($project->isDraft()) {
            return ApiResponse::error(Project::DRAFT_BLOCKED_MESSAGE, 422);
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
            // Company Admin has no `users` row; only one of these two is ever set.
            'uploaded_by_admin_id'  => $this->admin()->id ?? null,
            'uploaded_by_user_id'   => null,
            'original_name'         => $stored->originalName,
            'file_name'             => $stored->fileName,
            'file_type'             => $stored->mimeType,
            'file_size'             => $stored->sizeBytes,
            'is_visible_to_client'  => true,
        ]));

        return ApiResponse::success($attachment->load('uploadedByAdmin:id,name'), 'Attachment uploaded', 201);
    }

    // GET /admin/projects/{projectId}/attachments/{id}/download
    public function download(int $projectId, int $id): StreamedResponse
    {
        $project = $this->project($projectId);

        $attachment = ProjectAttachment::where('project_id', $project->id)->findOrFail($id);

        return AttachmentStorageService::download(
            $attachment->storage_type, $attachment->file_path, $attachment->original_name,
            $attachment->file_type, $attachment->google_drive_file_id, $attachment->company_id
        );
    }

    // DELETE /admin/projects/{projectId}/attachments/{id}
    public function destroy(int $projectId, int $id): JsonResponse
    {
        $project = $this->project($projectId);

        $attachment = ProjectAttachment::where('project_id', $project->id)->findOrFail($id);
        AttachmentStorageService::delete($attachment->storage_type, $attachment->file_path, $attachment->google_drive_file_id, $attachment->company_id);
        $attachment->delete();

        return ApiResponse::success(null, 'Attachment deleted');
    }
}
