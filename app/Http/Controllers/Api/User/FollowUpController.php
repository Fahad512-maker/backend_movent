<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\User\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadTransfer;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserCompanyPermission;
use App\Support\PermissionDebug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FollowUpController extends Controller
{
    use ScopesToActiveCompany;

    private function user()     { return auth('sanctum')->user(); }
    private function userName(): string { return $this->user()->name ?? 'User'; }

    private function can(string $permKey): bool
    {
        $user = $this->user();
        $result = UserCompanyPermission::where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->where('module_key', 'sales')
            ->where('permission_key', $permKey)
            ->exists();
        PermissionDebug::log($user->id, $user->company_id, $user->role_type, 'sales', $permKey, $result);
        return $result;
    }

    // GET /user/follow-ups — scoped to whichever company is currently
    // active (see ScopesToActiveCompany), not just the raw primary
    // users.company_id column, so a multi-company Sales/Lead Manager who
    // switches company sees that company's follow-ups instead of always
    // whichever company their account defaulted to.
    public function queue(Request $request): JsonResponse
    {
        if (!$this->can('canViewLeads')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $user      = $this->user();
        $companyId = $this->activeCompanyId();
        $filter    = $request->get('filter', 'today'); // today | upcoming | overdue | all
        $today     = now()->toDateString();

        // Mirrors LeadController::visibleLeads() — without canViewAllCompanyLeads,
        // a plain Seller must only see follow-ups that are actually theirs:
        // either assigned to them directly, or on a lead assigned to them.
        // Before this, queue() only ever filtered by company_id, so every
        // Seller saw every follow-up in the company — including ones on
        // leads assigned to someone else, or on leads assigned to no one at
        // all.
        $ownershipScope = function ($query) use ($user) {
            $query->where('assigned_to', $user->id)
                ->orWhereHas('lead', fn ($l) => $l->where('assigned_to', $user->id));
        };

        // Deliberately not status-scoped to 'pending' — a follow-up marked
        // completed/missed must keep showing here (with its real status,
        // handled by the frontend) for whichever date bucket it was
        // scheduled in, the same as it still shows on the Lead detail page's
        // own follow-up list, which is never status-filtered either. Before
        // this, completing/missing a follow-up just made it vanish from
        // this queue entirely.
        $q = FollowUp::where('company_id', $companyId)
            ->with(['lead:id,name,status', 'assignedTo:id,name'])
            ->orderBy('scheduled_at');

        if (!$this->can('canViewAllCompanyLeads')) {
            $q->where($ownershipScope);
        }

        match($filter) {
            'today'    => $q->whereDate('scheduled_at', $today),
            'overdue'  => $q->whereDate('scheduled_at', '<', $today),
            'upcoming' => $q->whereDate('scheduled_at', '>', $today),
            default    => null,
        };

        $countsBase = fn () => tap(
            FollowUp::where('company_id', $companyId)->where('status', 'pending'),
            fn ($query) => $this->can('canViewAllCompanyLeads') ? null : $query->where($ownershipScope),
        );

        return ApiResponse::success([
            'follow_ups' => $q->get()->map(fn($f) => $this->fmt($f)),
            'counts' => [
                'today'    => $countsBase()->whereDate('scheduled_at', $today)->count(),
                'overdue'  => $countsBase()->whereDate('scheduled_at', '<', $today)->count(),
                'upcoming' => $countsBase()->whereDate('scheduled_at', '>', $today)->count(),
            ],
        ]);
    }

    // POST /user/leads/{leadId}/follow-ups — was previously reachable only
    // via the admin guard; the frontend's "Add Follow-up" button/modal on
    // the Lead detail page hard-blocked non-admins with "Add follow-ups
    // from admin panel" despite complete()/miss()/cancel() below already
    // working for a Seller. Mirrors Api\Admin\FollowUpController::store()
    // exactly, gated the same as this controller's own complete()/miss()/
    // cancel() (canEditLeads), just company-scoped instead of admin-guard-
    // unrestricted.
    public function store(Request $request, int $leadId): JsonResponse
    {
        if (!$this->can('canEditLeads')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $user = $this->user();
        $lead = Lead::where('company_id', $user->company_id)->findOrFail($leadId);

        $validated = $request->validate([
            'type'             => ['required', 'in:call,email,meeting,whatsapp,demo,other'],
            'scheduled_at'     => ['required', 'date'],
            'notes'            => ['nullable', 'string'],
            'assigned_to'      => ['nullable', 'integer', 'exists:users,id'],
            'reminder_enabled' => ['boolean'],
        ]);

        $validated['lead_id']    = $lead->id;
        $validated['company_id'] = $lead->company_id;
        $validated['created_by'] = $user->id;
        $validated['status']     = 'pending';

        $fu = FollowUp::create($validated);
        $fu->load('assignedTo:id,name');

        $scheduled = $fu->scheduled_at->toDateString();
        if (!$lead->next_followup_date || $scheduled < $lead->next_followup_date->toDateString()) {
            $lead->update(['next_followup_date' => $scheduled]);
        }

        $lead->logActivity('followup_added',
            "Follow-up ({$fu->type}) scheduled for " . $fu->scheduled_at->format('d M Y H:i'),
            $this->userName(), ['followup_id' => $fu->id, 'type' => $fu->type]);

        $this->notifyLeadManagers($lead, $fu);

        return ApiResponse::success($this->fmt($fu), 'Follow-up added', 201);
    }

    // Lets whichever Lead Manager created or last transferred this lead know
    // a follow-up was scheduled on it — otherwise a Seller working leads
    // they were handed off has no visibility into whether/when follow-up is
    // actually happening. Notifies BOTH if the lead's creator and its most
    // recent transferrer are two different Lead Managers; skips whichever
    // one is the person who just created this follow-up (notifyUser()'s own
    // self-skip convention, mirrored here since this controller has no
    // shared helper with Api\User\LeadController).
    private function notifyLeadManagers(Lead $lead, FollowUp $fu): void
    {
        $actorId = $this->user()->id;

        $candidateIds = collect([$lead->created_by]);

        $lastTransfer = LeadTransfer::where('lead_id', $lead->id)->latest('created_at')->first();
        if ($lastTransfer) {
            $candidateIds->push($lastTransfer->transferred_by_user_id);
        }

        $leadManagerIds = User::whereIn('id', $candidateIds->filter()->unique())
            ->where('role_type', 'lead_manager')
            ->pluck('id')
            ->reject(fn ($id) => $id === $actorId);

        foreach ($leadManagerIds as $managerId) {
            Notification::create([
                'user_id'    => $managerId,
                'company_id' => $lead->company_id,
                'type'       => 'lead_followup_created',
                'title'      => "New follow-up on \"{$lead->name}\"",
                'body'       => "{$this->userName()} scheduled a follow-up ({$fu->type}) on lead \"{$lead->name}\".",
                'data'       => ['lead_id' => $lead->id, 'followup_id' => $fu->id, 'link' => "/leads/{$lead->id}"],
            ]);
        }
    }

    // PATCH /user/follow-ups/{id}/complete
    public function complete(int $id): JsonResponse
    {
        if (!$this->can('canEditLeads')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $fu = FollowUp::where('company_id', $this->user()->company_id)->findOrFail($id);
        $fu->update(['status' => 'completed', 'completed_at' => now()]);

        $fu->lead->logActivity('followup_completed',
            "Follow-up ({$fu->type}) marked as completed",
            $this->userName(), ['followup_id' => $fu->id]);

        $fu->load('assignedTo:id,name');
        return ApiResponse::success($this->fmt($fu));
    }

    // PATCH /user/follow-ups/{id}/miss
    public function miss(int $id): JsonResponse
    {
        if (!$this->can('canEditLeads')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $fu = FollowUp::where('company_id', $this->user()->company_id)->findOrFail($id);
        $fu->update(['status' => 'missed']);
        $fu->load('assignedTo:id,name');
        return ApiResponse::success($this->fmt($fu));
    }

    // PATCH /user/follow-ups/{id}/cancel
    public function cancel(int $id): JsonResponse
    {
        if (!$this->can('canEditLeads')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $fu = FollowUp::where('company_id', $this->user()->company_id)->findOrFail($id);
        $fu->update(['status' => 'cancelled']);
        $fu->load('assignedTo:id,name');
        return ApiResponse::success($this->fmt($fu));
    }

    private function fmt(FollowUp $f): array
    {
        return [
            'id'               => $f->id,
            'lead_id'          => $f->lead_id,
            'lead_name'        => $f->lead?->name,
            'lead_status'      => $f->lead?->status,
            'type'             => $f->type,
            'scheduled_at'     => $f->scheduled_at->toIso8601String(),
            'completed_at'     => $f->completed_at?->toIso8601String(),
            'notes'            => $f->notes,
            'status'           => $f->status,
            'reminder_enabled' => $f->reminder_enabled,
            'assigned_to'      => $f->assigned_to,
            'assigned_user'    => $f->assignedTo ? ['id' => $f->assignedTo->id, 'name' => $f->assignedTo->name] : null,
            'created_at'       => $f->created_at->toIso8601String(),
        ];
    }
}
