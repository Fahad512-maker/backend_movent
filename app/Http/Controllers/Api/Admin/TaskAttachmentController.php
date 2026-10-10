<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectTaskAttachment;
use App\Models\SystemAuditLog;
use App\Models\Task;
use App\Services\AttachmentStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaskAttachmentController extends Controller
{
    private const MAX_FILE_KB = 10240; // 10 MB per file
    private const ALLOWED_MIMES = 'pdf,doc,docx,xls,xlsx,png,jpg,jpeg,zip';

    private function admin()   { return auth('admin')->user(); }
    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    private function task(int $projectId, int $taskId): Task
    {
        $project = Project::whereIn('company_id', $this->companyIds())->findOrFail($projectId);

        return Task::where('project_id', $project->id)->findOrFail($taskId);
    }

    // GET /admin/projects/{projectId}/tasks/{taskId}/attachments
    public function index(int $projectId, int $taskId): JsonResponse
    {
        $task = $this->task($projectId, $taskId);

        $attachments = ProjectTaskAttachment::where('task_id', $task->id)
            ->with(['uploadedByAdmin:id,name', 'uploadedByUser:id,name'])
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success($attachments);
    }

    // POST /admin/projects/{projectId}/tasks/{taskId}/attachments (multipart/form-data, one file per request)
    public function store(Request $request, int $projectId, int $taskId): JsonResponse
    {
        $task = $this->task($projectId, $taskId);
        $project = $task->project;

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:' . AttachmentStorageService::maxUploadKb($project->company_id, self::MAX_FILE_KB), 'mimes:' . self::ALLOWED_MIMES],
        ]);

        $file = $validated['file'];
        $folder = ($project->storage_folder ?: 'companies/' . $project->company_id . '/projects/' . $project->id) . '/tasks/' . $task->id . '/attachments';

        $stored = AttachmentStorageService::store($file, $project->company_id, 'tasks', $folder);
        if (!$stored) {
            return AttachmentStorageService::errorResponse($project->company_id);
        }

        $attachment = ProjectTaskAttachment::create(array_merge($stored->locationAttributes(), [
            'company_id'           => $project->company_id,
            'project_id'           => $project->id,
            'task_id'              => $task->id,
            // Company Admin has no `users` row; only one of these two is ever set.
            'uploaded_by_admin_id' => $this->admin()->id ?? null,
            'uploaded_by_user_id'  => null,
            'original_name'        => $stored->originalName,
            'file_name'            => $stored->fileName,
            'file_type'            => $stored->mimeType,
            'file_size'            => $stored->sizeBytes,
        ]));

        SystemAuditLog::create([
            'company_id'  => $project->company_id,
            'user_id'     => null, // Company Admin actor isn't a User row
            'action'      => 'attachment_uploaded',
            'module_key'  => 'project_management',
            'entity_type' => 'Task',
            'entity_id'   => $task->id,
            'new_values'  => ['attachment_id' => $attachment->id, 'original_name' => $attachment->original_name],
        ]);

        return ApiResponse::success($attachment->load('uploadedByAdmin:id,name'), 'Attachment uploaded', 201);
    }

    // GET /admin/projects/{projectId}/tasks/{taskId}/attachments/{id}/download
    public function download(int $projectId, int $taskId, int $id): StreamedResponse
    {
        $task = $this->task($projectId, $taskId);

        $attachment = ProjectTaskAttachment::where('task_id', $task->id)->findOrFail($id);

        return AttachmentStorageService::download(
            $attachment->storage_type, $attachment->file_path, $attachment->original_name,
            $attachment->file_type, $attachment->google_drive_file_id, $attachment->company_id
        );
    }

    // DELETE /admin/projects/{projectId}/tasks/{taskId}/attachments/{id}
    public function destroy(int $projectId, int $taskId, int $id): JsonResponse
    {
        $task = $this->task($projectId, $taskId);

        $attachment = ProjectTaskAttachment::where('task_id', $task->id)->findOrFail($id);
        AttachmentStorageService::delete($attachment->storage_type, $attachment->file_path, $attachment->google_drive_file_id, $attachment->company_id);
        $attachment->delete();

        return ApiResponse::success(null, 'Attachment deleted');
    }
}
