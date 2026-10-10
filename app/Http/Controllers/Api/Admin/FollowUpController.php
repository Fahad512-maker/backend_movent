<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Admin\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\FollowUp;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FollowUpController extends Controller
{
    use ScopesToActiveCompany;

    private function admin() { return auth('admin')->user(); }
    private function adminName(): string { return $this->admin()->name ?? 'Admin'; }
    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    // GET /admin/leads/{leadId}/follow-ups
    public function index(int $leadId): JsonResponse
    {
        $lead = Lead::whereIn('company_id', $this->companyIds())->findOrFail($leadId);
        $followUps = $lead->followUps()->with('assignedTo:id,name')->orderByDesc('scheduled_at')->get();
        return ApiResponse::success(['follow_ups' => $followUps->map(fn($f) => $this->fmt($f))]);
    }

    // POST /admin/leads/{leadId}/follow-ups
    public function store(Request $request, int $leadId): JsonResponse
    {
        $lead = Lead::whereIn('company_id', $this->companyIds())->findOrFail($leadId);

        $validated = $request->validate([
            'type'             => ['required', 'in:call,email,meeting,whatsapp,demo,other'],
            'scheduled_at'     => ['required', 'date'],
            'notes'            => ['nullable', 'string'],
            'assigned_to'      => ['nullable', 'integer', 'exists:users,id'],
            'reminder_enabled' => ['boolean'],
        ]);

        $validated['lead_id']    = $lead->id;
        $validated['company_id'] = $lead->company_id;
        $validated['created_by'] = null; // admin (no user model here)
        $validated['status']     = 'pending';

        $fu = FollowUp::create($validated);
        $fu->load('assignedTo:id,name');

        // Update lead's next_followup_date if this is sooner
        $scheduled = $fu->scheduled_at->toDateString();
        if (!$lead->next_followup_date || $scheduled < $lead->next_followup_date->toDateString()) {
            $lead->update(['next_followup_date' => $scheduled]);
        }

        $lead->logActivity('followup_added',
            "Follow-up ({$fu->type}) scheduled for " . $fu->scheduled_at->format('d M Y H:i'),
            $this->adminName(), ['followup_id' => $fu->id, 'type' => $fu->type]);

        return ApiResponse::success($this->fmt($fu), 'Follow-up added', 201);
    }

    // PUT /admin/follow-ups/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $fu = FollowUp::whereIn('company_id', $this->companyIds())->findOrFail($id);

        $validated = $request->validate([
            'type'             => ['sometimes', 'in:call,email,meeting,whatsapp,demo,other'],
            'scheduled_at'     => ['sometimes', 'date'],
            'notes'            => ['nullable', 'string'],
            'assigned_to'      => ['nullable', 'integer', 'exists:users,id'],
            'reminder_enabled' => ['boolean'],
        ]);

        $fu->update($validated);
        $fu->load('assignedTo:id,name');
        return ApiResponse::success($this->fmt($fu));
    }

    // PATCH /admin/follow-ups/{id}/complete
    public function complete(int $id): JsonResponse
    {
        $fu = FollowUp::whereIn('company_id', $this->companyIds())->findOrFail($id);
        $fu->update(['status' => 'completed', 'completed_at' => now()]);

        $fu->lead->logActivity('followup_completed',
            "Follow-up ({$fu->type}) marked as completed",
            $this->adminName(), ['followup_id' => $fu->id]);

        return ApiResponse::success($this->fmt($fu));
    }

    // PATCH /admin/follow-ups/{id}/miss
    public function miss(int $id): JsonResponse
    {
        $fu = FollowUp::whereIn('company_id', $this->companyIds())->findOrFail($id);
        $fu->update(['status' => 'missed']);
        return ApiResponse::success($this->fmt($fu));
    }

    // PATCH /admin/follow-ups/{id}/cancel
    public function cancel(int $id): JsonResponse
    {
        $fu = FollowUp::whereIn('company_id', $this->companyIds())->findOrFail($id);
        $fu->update(['status' => 'cancelled']);
        return ApiResponse::success($this->fmt($fu));
    }

    // DELETE /admin/follow-ups/{id}
    public function destroy(int $id): JsonResponse
    {
        FollowUp::whereIn('company_id', $this->companyIds())->findOrFail($id)->delete();
        return ApiResponse::success(null, 'Follow-up deleted');
    }

    // GET /admin/follow-ups   — today / upcoming / overdue queue, scoped to
    // the active company (or every owned company under "All Companies") —
    // same Company-Wise Dashboard Filtering pattern as every other admin
    // list page. Previously used companyIds() (every company this admin
    // owns) regardless of the active-company selector, so Company 1's
    // follow-ups kept showing while Company 2 was selected, and vice versa.
    public function queue(Request $request): JsonResponse
    {
        $companyIds = $this->activeCompanyIds();
        $filter     = $request->get('filter', 'today'); // today | upcoming | overdue | all
        $today      = now()->toDateString();

        // Deliberately not status-scoped to 'pending' — a follow-up marked
        // completed/missed must keep showing here (with its real status,
        // handled by the frontend) for whichever date bucket it was
        // scheduled in, the same as it still shows on the Lead detail page's
        // own follow-up list (index() above, never status-filtered either).
        // Before this, completing/missing a follow-up just made it vanish
        // from this queue entirely.
        $q = FollowUp::whereIn('company_id', $companyIds)
            ->with(['lead:id,name,status', 'assignedTo:id,name'])
            ->orderBy('scheduled_at');

        match($filter) {
            'today'    => $q->whereDate('scheduled_at', $today),
            'overdue'  => $q->whereDate('scheduled_at', '<', $today),
            'upcoming' => $q->whereDate('scheduled_at', '>', $today),
            default    => null,
        };

        return ApiResponse::success([
            'follow_ups' => $q->get()->map(fn($f) => $this->fmt($f)),
            'counts' => [
                'today'    => FollowUp::whereIn('company_id', $companyIds)->where('status', 'pending')->whereDate('scheduled_at', $today)->count(),
                'overdue'  => FollowUp::whereIn('company_id', $companyIds)->where('status', 'pending')->whereDate('scheduled_at', '<', $today)->count(),
                'upcoming' => FollowUp::whereIn('company_id', $companyIds)->where('status', 'pending')->whereDate('scheduled_at', '>', $today)->count(),
            ],
        ]);
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
