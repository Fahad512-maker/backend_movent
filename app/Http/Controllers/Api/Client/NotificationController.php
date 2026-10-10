<?php

namespace App\Http\Controllers\Api\Client;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Deliberately NOT company-scoped, unlike its staff twin
     * (Api\User\NotificationController::index()): a single client login can be a
     * client of several companies at once — the same reason every other
     * Api\Client\* controller resolves a LIST of client ids rather than one.
     * Scoping to users.company_id (set once at portal creation) would silently
     * hide every notification from the client's other companies.
     */
    // Internal task/production notification types — never meant for a
    // Client Portal login. Every writer already validates task/production
    // assignment against role_type NOT IN ('seller','client'), but a row can
    // still end up pointing at a Client if that user's role_type changed
    // *after* the row was written (e.g. a staff member later converted to a
    // Client login keeps their old users.id, and therefore their old
    // notifications). Excluded here as defense-in-depth so the Client Portal
    // bell can never surface task-lifecycle noise regardless of how a row
    // got mis-targeted.
    private const EXCLUDED_TYPES = [
        'task_assigned', 'task_ready_for_qa', 'task_qa_failed',
        'task_ready_for_production', 'task_completed', 'production_task_assigned',
    ];

    private function scope(Request $request)
    {
        return Notification::where('user_id', $request->user()->id)
            ->whereNotIn('type', self::EXCLUDED_TYPES)
            ->whereNull('cleared_at');
    }

    public function index(Request $request): JsonResponse
    {
        $notifications = $this->scope($request)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        $unreadCount = $this->scope($request)->where('is_read', false)->count();

        return ApiResponse::success([
            'notifications' => $notifications,
            'unread_count'  => $unreadCount,
        ]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $notification = $this->scope($request)->findOrFail($id);
        $notification->update(['is_read' => true, 'read_at' => now()]);

        return ApiResponse::success($notification, 'Notification marked as read');
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $this->scope($request)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return ApiResponse::success(null, 'All notifications marked as read');
    }

    // Soft-clear (cleared_at, not deleted) — mirrors the staff controller so a
    // dismissed row stops counting as unread anywhere it might be summed.
    public function clear(Request $request, int $id): JsonResponse
    {
        $notification = $this->scope($request)->findOrFail($id);
        $notification->update([
            'cleared_at' => now(),
            'is_read'    => true,
            'read_at'    => $notification->read_at ?? now(),
        ]);

        return ApiResponse::success(null, 'Notification cleared');
    }

    public function clearAll(Request $request): JsonResponse
    {
        $this->scope($request)->update([
            'cleared_at' => now(),
            'is_read'    => true,
            'read_at'    => now(),
        ]);

        return ApiResponse::success(null, 'All notifications cleared');
    }
}
