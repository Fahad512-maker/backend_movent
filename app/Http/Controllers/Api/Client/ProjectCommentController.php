<?php

namespace App\Http\Controllers\Api\Client;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Client\Concerns\ScopesToPermittedClient;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectComment;
use App\Models\SystemAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// Client Communication Rules — the client's own view of Client-facing
// Project Comments (never a live chat inside project/task pages). A client
// can only ever read/add visibility='client' comments on their own projects,
// and never sees an 'internal' comment even if one exists on the same
// project (index() below hard-filters to 'client' only, not by request).
class ProjectCommentController extends Controller
{
    use ScopesToPermittedClient;

    private function clientIds(Request $request): array
    {
        return $this->permittedClientIds($request, 'projects');
    }

    private function project(Request $request, int $projectId): Project
    {
        return Project::whereIn('client_id', $this->clientIds($request))->notDraft()->findOrFail($projectId);
    }

    // GET /client/projects/{id}/comments
    public function index(Request $request, int $projectId): JsonResponse
    {
        $project = $this->project($request, $projectId);

        $comments = ProjectComment::where('project_id', $project->id)
            ->where('visibility', 'client')
            ->with(['authorAdmin:id,name', 'authorUser:id,name'])
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success($comments);
    }

    // POST /client/projects/{id}/comments — always visibility='client'; a
    // client can never author an internal comment.
    public function store(Request $request, int $projectId): JsonResponse
    {
        $project = $this->project($request, $projectId);

        $validated = $request->validate(['body' => ['required', 'string']]);

        $comment = ProjectComment::create([
            'company_id'     => $project->company_id,
            'project_id'     => $project->id,
            'author_user_id' => $request->user()->id,
            'body'           => $validated['body'],
            'visibility'     => 'client',
        ]);

        $this->notifyStaff($project, $comment, $request->user()->id);

        return ApiResponse::success($comment->load('authorUser:id,name'), 'Comment added', 201);
    }

    // Notify the PM and any linked Seller — never the wider internal team
    // (Developer/Designer/QA/Production/Team Member never see this at all,
    // since index() on the internal-side controllers already scopes
    // visibility='client' comments to internal staff only when they hold
    // canViewTasks/canViewAllCompanyProjects, same as before this feature).
    // Company Admin needs no Notification row — the SystemAuditLog write
    // below already surfaces on their bell, same as every other comment.
    private function notifyStaff(Project $project, ProjectComment $comment, int $actorUserId): void
    {
        $authorName = $comment->authorUser?->name ?? 'Client';

        $ids = collect([$project->project_manager_id, $project->seller_id, $project->created_by])->filter()->unique();

        if ($project->lead_id) {
            $lead = Lead::find($project->lead_id);
            if ($lead) $ids = $ids->merge(collect([$lead->assigned_to, $lead->transferred_to])->filter());
        }
        if ($project->client_id) {
            $client = Client::find($project->client_id);
            if ($client?->account_manager) $ids->push($client->account_manager);
        }

        $recipients = $ids->unique()->reject(fn ($uid) => $uid === $actorUserId);

        foreach ($recipients as $uid) {
            Notification::create([
                'user_id'    => $uid,
                'company_id' => $project->company_id,
                'type'       => 'client_facing_comment',
                'title'      => "New client comment on {$project->name}",
                'body'       => "{$authorName}: " . Str::limit($comment->body, 120),
                'data'       => [
                    'project_id' => $project->id,
                    'comment_id' => $comment->id,
                    'link'       => "/projects/{$project->id}",
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
