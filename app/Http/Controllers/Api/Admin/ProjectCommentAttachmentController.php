<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectComment;
use App\Models\ProjectCommentAttachment;
use App\Services\AttachmentStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Admin mirror of Api\User\ProjectCommentAttachmentController — Company
// Admin sees every comment regardless of visibility (no internal/client
// split applies to the Admin guard), and may attach to any comment (not
// restricted to their own authorship, since Admin oversees everything).
class ProjectCommentAttachmentController extends Controller
{
    private const MAX_FILE_KB = 10240;
    private const ALLOWED_MIMES = 'pdf,doc,docx,xls,xlsx,png,jpg,jpeg,zip';

    private function admin()   { return auth('admin')->user(); }
    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    private function comment(int $projectId, int $commentId): ProjectComment
    {
        $project = Project::whereIn('company_id', $this->companyIds())->findOrFail($projectId);
        return ProjectComment::where('project_id', $project->id)->findOrFail($commentId);
    }

    public function index(int $projectId, int $commentId): JsonResponse
    {
        $comment = $this->comment($projectId, $commentId);

        $attachments = ProjectCommentAttachment::where('comment_id', $comment->id)
            ->with(['uploadedByAdmin:id,name', 'uploadedByUser:id,name'])
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success($attachments);
    }

    public function store(Request $request, int $projectId, int $commentId): JsonResponse
    {
        $comment = $this->comment($projectId, $commentId);
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
            'uploaded_by_admin_id' => $this->admin()->id,
            'uploaded_by_user_id'  => null,
            'original_name'        => $stored->originalName,
            'file_name'            => $stored->fileName,
            'file_type'            => $stored->mimeType,
            'file_size'            => $stored->sizeBytes,
        ]));

        return ApiResponse::success($attachment->load('uploadedByAdmin:id,name'), 'Attachment uploaded', 201);
    }

    public function download(int $projectId, int $commentId, int $id): StreamedResponse
    {
        $comment = $this->comment($projectId, $commentId);
        $attachment = ProjectCommentAttachment::where('comment_id', $comment->id)->findOrFail($id);

        return AttachmentStorageService::download(
            $attachment->storage_type, $attachment->file_path, $attachment->original_name,
            $attachment->file_type, $attachment->google_drive_file_id, $attachment->company_id
        );
    }
}
