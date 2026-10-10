<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Admin\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\SalesTarget;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesTargetController extends Controller
{
    use ScopesToActiveCompany;

    private function admin() { return auth('admin')->user(); }

    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    // Current-month target (if any) + actual won-deal progress for a given
    // user — same shape as Api\User\SalesTargetController's own helper, so
    // the frontend team-targets table can be shared between Admin and Lead
    // Manager.
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
            // Same field Api\User\SalesTargetController exposes — Admin's
            // own table doesn't restrict editing by role, but the frontend
            // component is shared between both pages.
            'role_type'      => $targetUser->role_type,
            'period_start'   => $start,
            'period_end'     => $end,
            'target_value'   => $target ? (float) $target->target_value : null,
            'target_deals'   => $target?->target_deals,
            'achieved_value' => (float) (clone $wonLeads)->sum('estimated_value'),
            'achieved_deals' => (clone $wonLeads)->count(),
        ];
    }

    // GET /admin/sales/targets — every Seller/Lead Manager in the active
    // company plus their current-month target + progress.
    public function index(): JsonResponse
    {
        $team = User::where('company_id', $this->activeCompanyId())
            ->whereIn('role_type', ['seller', 'lead_manager'])
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => $this->targetSummaryFor($u))
            ->values();

        return ApiResponse::success($team);
    }

    // PUT /admin/sales/targets/{user} — Company Admin sets/adjusts any
    // Seller or Lead Manager's current-month target.
    public function update(Request $request, int $user): JsonResponse
    {
        $targetUser = User::where('id', $user)
            ->where('company_id', $this->activeCompanyId())
            ->whereIn('role_type', ['seller', 'lead_manager'])
            ->first();

        if (!$targetUser) {
            return ApiResponse::error('User not found', 404);
        }

        $validated = $request->validate([
            'target_value' => ['nullable', 'numeric', 'min:0'],
            'target_deals' => ['nullable', 'integer', 'min:0'],
        ]);

        $start = now()->startOfMonth()->toDateString();
        $end   = now()->endOfMonth()->toDateString();

        $target = SalesTarget::updateOrCreate(
            ['user_id' => $targetUser->id, 'period_start' => $start, 'period_end' => $end],
            [
                'company_id'   => $targetUser->company_id,
                'target_value' => $validated['target_value'] ?? null,
                'target_deals' => $validated['target_deals'] ?? null,
                // created_by identifies a `users` row; a Company Admin isn't
                // one, so there is no id to record here. It used to store
                // $targetUser->id, which read back as "this person set their
                // own target" — the opposite of what happened. Null is the
                // honest value (the column is nullable with no FK).
                'created_by'   => null,
            ]
        );

        return ApiResponse::success($target, 'Target updated');
    }
}
