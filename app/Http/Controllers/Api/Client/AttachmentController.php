<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Api\Client\Concerns\ScopesToPermittedClient;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectAttachment;
use App\Services\AttachmentStorageService;
use Illuminate\Http\Request;

class AttachmentController extends Controller
{
    use ScopesToPermittedClient;

    private function clientIds(Request $request): array
    {
        return $this->permittedClientIds($request, 'projects');
    }

    public function download(Request $request, int $id): mixed
    {
        $clientIds  = $this->clientIds($request);
        $projectIds = Project::whereIn('client_id', $clientIds)->pluck('id');

        $attachment = ProjectAttachment::where('id', $id)
            ->where('is_visible_to_client', true)
            ->whereIn('project_id', $projectIds)
            ->firstOrFail();

        return AttachmentStorageService::download(
            $attachment->storage_type, $attachment->file_path, $attachment->original_name,
            $attachment->file_type, $attachment->google_drive_file_id, $attachment->company_id
        );
    }
}
