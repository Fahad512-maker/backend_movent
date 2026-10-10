<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Project;
use App\Models\ProjectFolder;
use App\Services\AttachmentStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectDocumentController extends Controller
{
    private function admin()   { return auth('admin')->user(); }
    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    private function project(int $projectId): Project
    {
        return Project::whereIn('company_id', $this->companyIds())->findOrFail($projectId);
    }

    public function index(Request $request, int $projectId): JsonResponse
    {
        $project = $this->project($projectId);

        $q = Document::where('linked_to_type', 'project')
            ->where('linked_to_id', $project->id)
            ->with(['uploadedBy:id,name', 'folder:id,name']);

        if ($request->filled('folder')) {
            $folder = ProjectFolder::where('project_id', $project->id)->where('name', $request->folder)->first();
            $q->where('folder_id', $folder?->id);
        }

        return ApiResponse::success($q->orderByDesc('created_at')->get());
    }

    public function store(Request $request, int $projectId): JsonResponse
    {
        $project = $this->project($projectId);

        if ($project->isDraft()) {
            return ApiResponse::error(Project::DRAFT_BLOCKED_MESSAGE, 422);
        }

        $validated = $request->validate([
            'file'                  => ['required', 'file', 'max:' . AttachmentStorageService::maxUploadKb($project->company_id, 20480)],
            'folder'                => ['required', 'string'],
            'title'                 => ['nullable', 'string', 'max:255'],
            'is_visible_to_client'  => ['nullable', 'boolean'],
        ]);

        $folder = ProjectFolder::where('project_id', $project->id)->where('name', $validated['folder'])->first();

        if (!$folder) {
            return ApiResponse::error('Unknown project folder.', 422);
        }

        $file = $request->file('file');

        $stored = AttachmentStorageService::store($file, $project->company_id, 'documents', $folder->folder_path);
        if (!$stored) {
            return AttachmentStorageService::errorResponse($project->company_id);
        }

        $document = Document::create(array_merge($stored->locationAttributes(), [
            'company_id'            => $project->company_id,
            // uploaded_by FKs to `users`; Company Admin actor isn't a User row
            'uploaded_by'           => null,
            'title'                 => $validated['title'] ?? $stored->originalName,
            'type'                  => $this->typeFromExtension($file->getClientOriginalExtension()),
            'file_name'             => $stored->originalName,
            'file_size_bytes'       => $stored->sizeBytes,
            'linked_to_type'        => 'project',
            'linked_to_id'          => $project->id,
            'folder_id'             => $folder->id,
            'is_visible_to_client'  => $validated['is_visible_to_client'] ?? false,
        ]));

        return ApiResponse::success($document->load('uploadedBy:id,name'), 'File uploaded', 201);
    }

    public function download(int $projectId, int $id): StreamedResponse
    {
        $project = $this->project($projectId);

        $document = Document::where('linked_to_type', 'project')
            ->where('linked_to_id', $project->id)
            ->findOrFail($id);

        return AttachmentStorageService::download(
            $document->storage_type, $document->file_path, $document->file_name,
            null, $document->google_drive_file_id, $document->company_id
        );
    }

    public function destroy(int $projectId, int $id): JsonResponse
    {
        $project = $this->project($projectId);

        $document = Document::where('linked_to_type', 'project')
            ->where('linked_to_id', $project->id)
            ->findOrFail($id);

        $document->delete();

        return ApiResponse::success(null, 'File deleted');
    }

    private function typeFromExtension(string $ext): string
    {
        $ext = strtolower($ext);
        return match (true) {
            $ext === 'pdf' => 'pdf',
            in_array($ext, ['xls', 'xlsx', 'csv']) => 'spreadsheet',
            in_array($ext, ['doc', 'docx']) => 'word',
            in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']) => 'image',
            default => 'other',
        };
    }
}
