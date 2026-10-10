<?php

namespace App\Http\Controllers\Api\Client;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Client\Concerns\ScopesToPermittedClient;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Deliverable;
use App\Models\Document;
use App\Models\Project;
use App\Models\ProjectAttachment;
use App\Models\ProjectDeliverySubmission;
use App\Models\Revision;
use App\Models\Task;
use App\Services\AttachmentStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectController extends Controller
{
    use ScopesToPermittedClient;

    private function clientIds(Request $request): array
    {
        return $this->permittedClientIds($request, 'projects');
    }

    public function index(Request $request): JsonResponse
    {
        $clientIds = $this->clientIds($request);

        $query = Project::whereIn('client_id', $clientIds)
            ->notDraft()
            ->with(['projectManager:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $projects = $query->orderByDesc('created_at')
            ->get(['id', 'name', 'status', 'start_date', 'deadline', 'project_manager_id', 'created_at']);

        $projectIds = $projects->pluck('id');
        $taskStats = Task::whereIn('project_id', $projectIds)
            ->selectRaw('project_id, COUNT(*) as total, SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as done')
            ->groupBy('project_id')
            ->get()
            ->keyBy('project_id');

        $projects = $projects->map(function ($p) use ($taskStats) {
            $stats = $taskStats[$p->id] ?? null;
            // A completed/closed project reads 100% regardless of its task
            // ratio — otherwise one with zero tasks (or any tasks still open
            // at completion time) shows 0%/partial forever despite the work
            // being done.
            $p->progress = in_array($p->status, ['completed', 'closed'], true)
                ? 100
                : ($stats && $stats->total > 0 ? round(($stats->done / $stats->total) * 100) : 0);
            return $p;
        });

        return ApiResponse::success($projects);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $clientIds = $this->clientIds($request);

        $project = Project::where('id', $id)
            ->whereIn('client_id', $clientIds)
            ->notDraft()
            ->with([
                'projectManager:id,name',
                'deliverySubmittedBy:id,name',
                'deliveryApprovedByAdmin:id,name',
                'deliverables' => fn($q) => $q->whereIn('status', ['delivered', 'approved', 'revision_requested'])
                                              ->with(['uploadedBy:id,name']),
                // Every time this project's final package was (re)delivered —
                // projects.delivery_* only ever holds the CURRENT one, so
                // without this the client only ever sees the latest delivery
                // and loses access to earlier ones the moment a corrected
                // package replaces it. See ProjectDeliverySubmission.
                'deliverySubmissions.deliveredByAdmin:id,name',
            ])
            ->firstOrFail();

        $totalTasks = Task::where('project_id', $project->id)->count();
        $doneTasks  = Task::where('project_id', $project->id)->where('status', 'completed')->count();
        // A completed/closed project reads 100% regardless of its task
        // ratio — see the same override in index() above.
        $project->progress = in_array($project->status, ['completed', 'closed'], true)
            ? 100
            : ($totalTasks > 0 ? round(($doneTasks / $totalTasks) * 100) : 0);

        $documents = Document::where('linked_to_type', 'project')
            ->where('linked_to_id', $id)
            ->where('is_visible_to_client', true)
            ->with(['uploadedBy:id,name'])
            ->orderByDesc('created_at')
            ->get(['id', 'title', 'type', 'file_name', 'file_size_bytes', 'uploaded_by', 'created_at'])
            ->map(fn ($d) => [
                'id' => $d->id, 'source' => 'document', 'title' => $d->title, 'type' => $d->type,
                'file_name' => $d->file_name, 'file_size_bytes' => $d->file_size_bytes,
                'uploaded_by' => $d->uploadedBy, 'created_at' => $d->created_at,
            ]);

        $attachments = ProjectAttachment::where('project_id', $id)
            ->where('is_visible_to_client', true)
            ->with(['uploadedByAdmin:id,name', 'uploadedByUser:id,name'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id, 'source' => 'attachment', 'title' => $a->original_name, 'type' => $a->file_type,
                'file_name' => $a->original_name, 'file_size_bytes' => $a->file_size,
                'uploaded_by' => $a->uploadedByAdmin ?? $a->uploadedByUser, 'created_at' => $a->created_at,
            ]);

        $deliveries = $project->deliverySubmissions->map(fn ($d) => [
            'id' => $d->id, 'source' => 'delivery', 'title' => $d->file_name, 'type' => $d->file_type,
            'file_name' => $d->file_name, 'file_size_bytes' => $d->file_size,
            'uploaded_by' => $d->deliveredByAdmin, 'created_at' => $d->delivered_at,
        ]);

        $files = $documents->concat($attachments)->concat($deliveries)->sortByDesc('created_at')->values();

        $activity = collect();
        $activity->push(['date' => $project->created_at, 'icon' => '🚀', 'text' => 'Project created', 'by' => null]);

        foreach ($project->deliverables as $d) {
            $activity->push(['date' => $d->updated_at, 'icon' => '📦', 'text' => "Deliverable \"{$d->title}\" marked as {$d->status}", 'by' => $d->uploadedBy?->name]);
        }

        foreach ($documents->concat($attachments) as $f) {
            $activity->push(['date' => $f['created_at'], 'icon' => '📎', 'text' => "File \"{$f['title']}\" uploaded", 'by' => $f['uploaded_by']?->name]);
        }

        if ($project->status === 'completed' && $project->completed_at) {
            $activity->push(['date' => $project->completed_at, 'icon' => '✅', 'text' => 'Project completed', 'by' => null]);
        }

        // Every delivery, not just the current one — mirrors the loop above
        // for documents/attachments instead of the old single check against
        // projects.delivery_approved_at, which only ever reflected the LATEST
        // delivery and dropped earlier ones from the history.
        foreach ($deliveries as $d) {
            $activity->push(['date' => $d['created_at'], 'icon' => '📦', 'text' => 'Final project package delivered', 'by' => $d['uploaded_by']?->name]);
        }

        return ApiResponse::success([
            'project'  => $project,
            'files'    => $files,
            'activity' => $activity->sortByDesc('date')->values(),
        ]);
    }

    public function downloadDelivery(Request $request, int $id): StreamedResponse
    {
        $clientIds = $this->clientIds($request);

        $project = Project::where('id', $id)
            ->whereIn('client_id', $clientIds)
            ->notDraft()
            ->firstOrFail();

        if ($project->delivery_status !== 'delivered_to_client'
            || (!$project->delivery_file_path && !$project->delivery_google_drive_file_id)) {
            abort(404, 'Project delivery is not available yet.');
        }

        if ($project->delivery_storage_type === 'internal' && !Storage::exists($project->delivery_file_path)) {
            abort(404, 'Project delivery file not found.');
        }

        return AttachmentStorageService::download(
            $project->delivery_storage_type, $project->delivery_file_path,
            $project->delivery_file_name ?? "{$project->name}-delivery.zip",
            $project->delivery_file_type, $project->delivery_google_drive_file_id, $project->company_id
        );
    }

    // GET /client/delivery-submissions/{deliveryId}/download — a specific
    // past delivery, not just the current one (mirrors Admin\ProjectController
    // ::downloadDeliverySubmission() — the client-facing half of the same
    // "complete delivery history" feature). Flat, no project id in the
    // path — ownership is resolved through the submission's own project,
    // same convention as documents'/attachments' download routes.
    public function downloadDeliverySubmission(Request $request, int $deliveryId): StreamedResponse
    {
        $clientIds = $this->clientIds($request);

        $delivery = ProjectDeliverySubmission::where('id', $deliveryId)
            ->whereHas('project', fn ($q) => $q->whereIn('client_id', $clientIds)->notDraft())
            ->firstOrFail();

        if ($delivery->storage_type === 'internal' && !Storage::exists($delivery->file_path)) {
            abort(404, 'This delivery file is missing from storage.');
        }

        return AttachmentStorageService::download(
            $delivery->storage_type, $delivery->file_path, $delivery->file_name ?? 'delivery.zip',
            $delivery->file_type, $delivery->google_drive_file_id, $delivery->project->company_id
        );
    }

    public function approveDeliverable(Request $request, int $id): JsonResponse
    {
        $clientIds = $this->clientIds($request);

        $deliverable = Deliverable::whereHas('project', fn($q) => $q->whereIn('client_id', $clientIds))
            ->findOrFail($id);

        $deliverable->update(['status' => 'approved']);

        return ApiResponse::success(null, 'Deliverable approved');
    }

    public function requestRevision(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['notes' => 'nullable|string|max:1000']);

        $clientIds = $this->clientIds($request);

        $deliverable = Deliverable::whereHas('project', fn($q) => $q->whereIn('client_id', $clientIds))
            ->findOrFail($id);

        Revision::create([
            'deliverable_id' => $deliverable->id,
            'requested_by'   => $request->user()->id,
            'feedback'       => $validated['notes'] ?? null,
            'status'         => 'open',
        ]);

        $deliverable->update(['status' => 'revision_requested']);

        return ApiResponse::success(null, 'Revision requested');
    }
}
