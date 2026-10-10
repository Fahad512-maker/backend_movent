<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Services\SuperAdminNotificationService as Feed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// The Super Admin's bell.
//
// Two filters, both mandatory, and the second is the point of this screen:
//
//  1. recipient_super_admin_id = the caller. Never another Super Admin's rows,
//     and never a Company Admin's or a staff member's — those live in the same
//     table and are addressed by their own recipient columns.
//  2. type IN SuperAdminNotificationService::TYPES. The Super Admin runs the
//     platform, so only platform-level events belong here — accounts,
//     companies, payments, subscriptions, and platform-wide alerts. A tenant's
//     internal traffic (tasks, invoices, project chat) is not theirs to read
//     and cannot appear even if some future caller wrote such a row against
//     this recipient by mistake.
//
// Read/clear state is per row (is_read / cleared_at), the same shape the staff
// and Client Portal bells already use — no "last read at" timestamp here.
class NotificationController extends Controller
{
    private const PAGE_SIZE = 30;

    private function superAdmin()
    {
        return auth('super_admin')->user();
    }

    // Every query starts here, so neither filter can be forgotten at a call site.
    private function feed()
    {
        return Notification::where('recipient_super_admin_id', $this->superAdmin()->id)
            ->whereIn('type', array_keys(Feed::TYPES));
    }

    // GET /super-admin/notifications?category=payments&unread=1
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['nullable', 'string', 'in:' . implode(',', array_keys(Feed::CATEGORIES))],
            'unread'   => ['nullable', 'boolean'],
        ]);

        $rows = $this->feed()
            ->whereNull('cleared_at')
            ->with('company:id,name')
            // A category is a set of types — filtering on the types it maps to
            // keeps the stored `module` column from being load-bearing.
            ->when($validated['category'] ?? null, fn ($q, $category) => $q->whereIn(
                'type',
                array_keys(array_filter(Feed::TYPES, fn ($c) => $c === $category))
            ))
            ->when($request->boolean('unread'), fn ($q) => $q->where('is_read', false))
            ->orderByDesc('created_at')
            ->limit(self::PAGE_SIZE)
            ->get();

        return ApiResponse::success([
            'notifications' => $rows->map(fn (Notification $n) => [
                'id'            => $n->id,
                'type'          => $n->type,
                'category'      => Feed::categoryFor($n->type),
                'category_label' => Feed::CATEGORIES[Feed::categoryFor($n->type)] ?? null,
                'title'         => $n->title,
                'body'          => $n->body,
                // Null for platform-level events that belong to no single
                // tenant — the UI just omits the chip for those.
                'company_id'    => $n->company_id,
                'company_name'  => $n->company?->name,
                'is_read'       => (bool) $n->is_read,
                'created_at'    => $n->created_at,
                'link'          => $n->url ?? ($n->data['link'] ?? null),
            ])->values(),
            'unread_count' => $this->feed()->whereNull('cleared_at')->where('is_read', false)->count(),
            // Counts per category so the filter can show them without a
            // request per tab.
            'categories'   => collect(Feed::CATEGORIES)->map(fn ($label, $key) => [
                'key'    => $key,
                'label'  => $label,
                'unread' => $this->feed()->whereNull('cleared_at')->where('is_read', false)
                    ->whereIn('type', array_keys(array_filter(Feed::TYPES, fn ($c) => $c === $key)))
                    ->count(),
            ])->values(),
        ]);
    }

    // GET /super-admin/notifications/unread-count — for the bell's dot alone,
    // so polling doesn't drag the whole list along with it.
    public function unreadCount(): JsonResponse
    {
        return ApiResponse::success([
            'unread_count' => $this->feed()->whereNull('cleared_at')->where('is_read', false)->count(),
        ]);
    }

    public function markRead(int $id): JsonResponse
    {
        $notification = $this->feed()->find($id);
        if (!$notification) {
            return ApiResponse::error('Notification not found', 404);
        }

        $notification->update(['is_read' => true, 'read_at' => now()]);

        return ApiResponse::success(null, 'Marked as read');
    }

    public function markAllRead(): JsonResponse
    {
        $this->feed()->whereNull('cleared_at')->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return ApiResponse::success(null, 'All notifications marked as read');
    }

    // Soft clear — the row stays as a record, it just leaves the bell.
    public function clear(int $id): JsonResponse
    {
        $notification = $this->feed()->find($id);
        if (!$notification) {
            return ApiResponse::error('Notification not found', 404);
        }

        $notification->update([
            'cleared_at' => now(),
            'is_read'    => true,
            'read_at'    => $notification->read_at ?? now(),
        ]);

        return ApiResponse::success(null, 'Notification cleared');
    }

    public function clearAll(): JsonResponse
    {
        $this->feed()->whereNull('cleared_at')
            ->update(['cleared_at' => now(), 'is_read' => true, 'read_at' => now()]);

        return ApiResponse::success(null, 'All notifications cleared');
    }
}
