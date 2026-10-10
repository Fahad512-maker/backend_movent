<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\SalesTarget;
use App\Models\User;
use App\Models\UserCompanyPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesTargetController extends Controller
{
    private function user() { return auth('sanctum')->user(); }

    private function can(string $permKey): bool
    {
        $user = $this->user();
        return UserCompanyPermission::where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->where('module_key', 'sales')
            ->where('permission_key', $permKey)
            ->exists();
    }

    // Current-month target (if any) + actual won-deal progress for a given
    // user — shared by index() (the caller's own) and team() (every Seller a
    // Lead Manager oversees), so both read identically.
    private function targetSummaryFor(User $targetUser): array
    {
        $start = now()->startOfMonth()->toDateString();
        $end   = now()->endOfMonth()->toDateString();

        $target = SalesTarget::where('user_id', $targetUser->id)
            ->where('period_start', $start)
            ->where('period_end', $end)
            ->first();

        $wonLeads = Lead::where('company_id', $targetUser->company_id)
            ->where('assigned_to', $targetUser->id)
            ->where('status', 'won')
            ->whereBetween('updated_at', [$start, $end . ' 23:59:59']);

        return [
            'user_id'        => $targetUser->id,
            'user_name'      => $targetUser->name,
            // Lets the frontend tell a Lead Manager's own row apart from a
            // Seller's in the team list — a Lead Manager already manages
            // their own target via the personal section above, and may
            // never edit a PEER Lead Manager's (see updateForUser() below).
            'role_type'      => $targetUser->role_type,
            'period_start'   => $start,
            'period_end'     => $end,
            'target_value'   => $target ? (float) $target->target_value : null,
            'target_deals'   => $target?->target_deals,
            'achieved_value' => (float) (clone $wonLeads)->sum('estimated_value'),
            'achieved_deals' => (clone $wonLeads)->count(),
        ];
    }

    // GET /user/sales/targets — the caller's own current-month target (if any
    // set by Company Admin/Lead Manager, or by the caller themselves via
    // upsert()) plus actual progress against it.
    public function index(): JsonResponse
    {
        if (!$this->can('canViewSalesTargets')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $me = $this->user();

        return ApiResponse::success([
            ...$this->targetSummaryFor($me),
            // Mirrors upsert()'s guard below exactly — otherwise the frontend
            // keeps rendering a "set my target" form for a Lead Manager that
            // can only ever 403.
            'can_update' => !self::selfEditBlocked($me) && $this->can('canUpdateSalesTargets'),
        ]);
    }

    // A Lead Manager may only ever VIEW their own target — Company Admin is
    // the only role that may set one for them (see Api\Admin\SalesTargetController
    // ::update()). Enforced by ROLE, not just by the permission default, so
    // that a canUpdateSalesTargets grant re-added by hand can never reopen
    // this path.
    private static function selfEditBlocked(User $user): bool
    {
        return $user->role_type === 'lead_manager';
    }

    // PUT /user/sales/targets — the caller sets/adjusts their OWN
    // current-month target. See updateForUser() below for a Lead Manager
    // setting a Seller's.
    public function upsert(Request $request): JsonResponse
    {
        $me = $this->user();

        if (self::selfEditBlocked($me)) {
            return ApiResponse::error('Your sales target is set by your Company Admin.', 403);
        }

        if (!$this->can('canUpdateSalesTargets')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $target = $this->saveTarget($me, $request);

        return ApiResponse::success($target, 'Target updated');
    }

    // GET /user/sales/targets/team — every SELLER in the caller's own
    // company plus their current-month target + progress, for a Lead
    // Manager overseeing the sales team. Sellers only: a Lead Manager
    // manages Sellers' targets and nothing else — their own is read-only
    // (see selfEditBlocked()) and shown by index(), and a PEER Lead
    // Manager's is Company Admin territory (Api\Admin\SalesTargetController),
    // so listing other Lead Managers here only ever produced rows nobody on
    // this page could act on. company_id is the caller's ACTIVE company —
    // SyncActiveCompanyContext resolves it per request — so another
    // company's Sellers can never appear here.
    public function team(): JsonResponse
    {
        if (!$this->can('canManageSalesTargets')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $me = $this->user();

        $team = User::where('company_id', $me->company_id)
            ->where('role_type', 'seller')
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => $this->targetSummaryFor($u))
            ->values();

        return ApiResponse::success($team);
    }

    // PUT /user/sales/targets/team/{userId} — a Lead Manager sets/adjusts a
    // Seller's current-month target. Deliberately Sellers only — a Lead
    // Manager may see a peer Lead Manager's target in team() above (company
    // overview), but never edit it; that peer manages their own via the
    // personal section, same as this caller does for themselves via
    // upsert(). Company Admin is the only role that may edit a Lead
    // Manager's target (see Api\Admin\SalesTargetController::update()).
    public function updateForUser(Request $request, int $userId): JsonResponse
    {
        if (!$this->can('canManageSalesTargets')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $me = $this->user();

        $targetUser = User::where('id', $userId)
            ->where('company_id', $me->company_id)
            ->where('role_type', 'seller')
            ->first();

        if (!$targetUser) {
            return ApiResponse::error('User not found', 404);
        }

        $target = $this->saveTarget($targetUser, $request, $me);

        return ApiResponse::success($target, 'Target updated');
    }

    private function saveTarget(User $targetUser, Request $request, ?User $createdBy = null): SalesTarget
    {
        $validated = $request->validate([
            'target_value' => ['nullable', 'numeric', 'min:0'],
            'target_deals' => ['nullable', 'integer', 'min:0'],
        ]);

        $start = now()->startOfMonth()->toDateString();
        $end   = now()->endOfMonth()->toDateString();

        return SalesTarget::updateOrCreate(
            ['user_id' => $targetUser->id, 'period_start' => $start, 'period_end' => $end],
            [
                'company_id'   => $targetUser->company_id,
                'target_value' => $validated['target_value'] ?? null,
                'target_deals' => $validated['target_deals'] ?? null,
                'created_by'   => ($createdBy ?? $targetUser)->id,
            ]
        );
    }
}
