<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\CompanyUserAssignment;
use App\Models\Notification;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    private const CATEGORY_TYPES = [
        'tasks'    => ['task_assigned', 'production_task_assigned', 'task_status_changed'],
        'projects' => ['project_assigned', 'project_team_assigned'],
    ];

    private function user() { return auth('sanctum')->user(); }

    // Every company this user currently has an ACTIVE assignment to — a
    // user assigned to more than one company must see notifications from
    // all of them regardless of which one is the frontend's "active
    // company" right now (Cross-Company Notifications). Suspended
    // assignments are excluded, same as CheckCompanyModule.
    private function activeCompanyIds($user): array
    {
        return CompanyUserAssignment::where('user_id', $user->id)
            ->where('status', 'active')
            ->pluck('company_id')
            ->all();
    }

    public function index(): JsonResponse
    {
        $user = $this->user();
        $companyIds = $this->activeCompanyIds($user);

        $notifications = Notification::where('user_id', $user->id)
            ->whereIn('company_id', $companyIds)
            ->whereNull('cleared_at')
            ->with('company:id,name')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        $unreadCount = Notification::where('user_id', $user->id)
            ->whereIn('company_id', $companyIds)
            ->whereNull('cleared_at')
            ->where('is_read', false)
            ->count();

        return ApiResponse::success([
            'notifications' => $notifications,
            'unread_count'  => $unreadCount,
        ]);
    }

    // PATCH /user/notifications/{id}/open — the click-through handler for
    // Cross-Company Notifications. Marks read (same as markRead()) AND
    // resolves, fresh on every call (never trusting a possibly-stale cached
    // company_assignments cookie), whether the user still has active access
    // to this notification's company — the frontend uses this to decide
    // whether to switch its active-company cookie + navigate, or show an
    // access-denied message instead.
    //
    // Also re-validates a project-linked notification's project (e.g.
    // "Added to project chat"): a draft/unpaid project has no real chat
    // conversation happening yet (see Project::isDraft()), so a stale
    // notification for one must show a message instead of redirecting —
    // same rule Project::DRAFT_BLOCKED_MESSAGE already states everywhere
    // else this comes up.
    public function open(int $id): JsonResponse
    {
        $user = $this->user();
        $notification = Notification::where('user_id', $user->id)->with('company:id,name')->findOrFail($id);
        $notification->update(['is_read' => true, 'read_at' => $notification->read_at ?? now()]);

        $hasAccess = CompanyUserAssignment::where('user_id', $user->id)
            ->where('company_id', $notification->company_id)
            ->where('status', 'active')
            ->exists();

        $projectId = $notification->data['project_id'] ?? null;
        $projectActive = null;
        if ($projectId) {
            $project = Project::where('company_id', $notification->company_id)->find($projectId);
            $projectActive = $project !== null && !$project->isDraft();
        }

        return ApiResponse::success([
            'company_id'     => $notification->company_id,
            'company_name'   => $notification->company?->name,
            'link'           => $notification->data['link'] ?? $notification->url,
            'access_granted' => $hasAccess,
            'project_active' => $projectActive,
            'message'        => $projectActive === false ? Project::DRAFT_BLOCKED_MESSAGE : null,
        ]);
    }

    // Powers the Sidebar's per-nav-item red dots (Tasks/Projects) — a
    // lightweight, uncapped count per category, separate from index()'s
    // top-30-limited feed so a busy account can't undercount here.
    public function unreadCounts(): JsonResponse
    {
        $userId = $this->user()->id;

        $tasks = Notification::where('user_id', $userId)
            ->whereNull('cleared_at')
            ->where('is_read', false)
            ->whereIn('type', self::CATEGORY_TYPES['tasks'])
            ->count();

        $projects = Notification::where('user_id', $userId)
            ->whereNull('cleared_at')
            ->where('is_read', false)
            ->whereIn('type', self::CATEGORY_TYPES['projects'])
            ->count();

        return ApiResponse::success(['tasks' => $tasks, 'projects' => $projects]);
    }

    // Called when the sub-user visits the Tasks or Projects list page, so
    // that page's dot clears without touching the other category or the
    // general bell dropdown's unread rows.
    public function markCategoryRead(Request $request): JsonResponse
    {
        $data = $request->validate(['category' => 'required|in:tasks,projects']);

        Notification::where('user_id', $this->user()->id)
            ->where('is_read', false)
            ->whereIn('type', self::CATEGORY_TYPES[$data['category']])
            ->update(['is_read' => true, 'read_at' => now()]);

        return ApiResponse::success(null, 'Marked as read');
    }

    public function markRead(int $id): JsonResponse
    {
        $notification = Notification::where('user_id', $this->user()->id)->findOrFail($id);
        $notification->update(['is_read' => true, 'read_at' => now()]);

        return ApiResponse::success($notification, 'Notification marked as read');
    }

    public function markAllRead(): JsonResponse
    {
        Notification::where('user_id', $this->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return ApiResponse::success(null, 'All notifications marked as read');
    }

    // Soft-clear (cleared_at, not deleted) — hides it from index()/unread
    // counts for THIS user only; the row and any other user's notifications
    // are untouched. Marks it read too, since a cleared item shouldn't still
    // count as unread anywhere it might be summed.
    public function clear(int $id): JsonResponse
    {
        $notification = Notification::where('user_id', $this->user()->id)->findOrFail($id);
        $notification->update(['cleared_at' => now(), 'is_read' => true, 'read_at' => $notification->read_at ?? now()]);

        return ApiResponse::success(null, 'Notification cleared');
    }

    public function clearAll(): JsonResponse
    {
        Notification::where('user_id', $this->user()->id)
            ->whereNull('cleared_at')
            ->update(['cleared_at' => now(), 'is_read' => true, 'read_at' => now()]);

        return ApiResponse::success(null, 'All notifications cleared');
    }
}
