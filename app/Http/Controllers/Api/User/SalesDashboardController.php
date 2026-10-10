<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\User\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\FollowUp;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\UserCompanyPermission;
use App\Services\InvoiceStatsService;
use App\Services\LeadRevenueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Mirrors Api\Admin\SalesDashboardController, scoped to the leads this Seller
// can see (own/assigned, unless canViewAllCompanyLeads is granted) instead of
// the whole company — same data shape so the frontend dashboard component is
// reusable across both guards.
class SalesDashboardController extends Controller
{
    use ScopesToActiveCompany;

    private function user() { return auth('sanctum')->user(); }

    // Checked against activeCompanyId() (not the user's static company_id) —
    // a Lead Manager/Seller assigned to multiple companies holds a distinct
    // permission set per CompanyUserAssignment, so every check here must
    // follow the company switcher, same as visibleLeads()/visibleInvoices()
    // below.
    private function can(string $permKey): bool
    {
        $user = $this->user();
        return UserCompanyPermission::where('user_id', $user->id)
            ->where('company_id', $this->activeCompanyId())
            ->where('module_key', 'sales')
            ->where('permission_key', $permKey)
            ->exists();
    }

    // Mirrors can() above but against the 'invoice' module — same pattern
    // Api\User\InvoiceController::can() uses for canViewAllCompanyInvoices.
    private function canInvoice(string $permKey): bool
    {
        $user = $this->user();
        return UserCompanyPermission::where('user_id', $user->id)
            ->where('company_id', $this->activeCompanyId())
            ->where('module_key', 'invoice')
            ->where('permission_key', $permKey)
            ->exists();
    }

    // Mirrors Api\User\LeadController::visibleLeads() — scoped to
    // activeCompanyId() so the lead funnel, monthly chart, and seller
    // ("Team Performance") table all follow the company switcher exactly
    // like the Invoice Summary section already does.
    private function visibleLeads()
    {
        $user = $this->user();
        $base = Lead::where('company_id', $this->activeCompanyId());

        if ($this->can('canViewAllCompanyLeads')) {
            return $base;
        }

        return $base->where('assigned_to', $user->id);
    }

    // Mirrors Api\User\InvoiceController::visibleInvoices() exactly — same
    // ownership legs (created it directly, or it's tied via lead/client/
    // project to this Seller), so "my invoices" never drifts out of sync
    // between the Invoices page and this dashboard's summary.
    private function visibleInvoices()
    {
        $user = $this->user();
        $base = Invoice::where('company_id', $this->activeCompanyId());

        if ($this->canInvoice('canViewAllCompanyInvoices')) {
            return $base;
        }

        return $base->where(function ($q) use ($user) {
            $q->where('created_by', $user->id)
              ->orWhereHas('lead', fn ($l) => $l->where('assigned_to', $user->id)->orWhere('transferred_to', $user->id))
              ->orWhereHas('client', fn ($c) => $c->where('account_manager', $user->id))
              ->orWhereHas('project', fn ($p) => $p->where('seller_id', $user->id));
        });
    }

    public function index(Request $request): JsonResponse
    {
        if (!$this->can('canViewSalesDashboard') && !$this->can('canViewLeads')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $user       = $this->user();
        $companyId  = $this->activeCompanyId();
        $today      = now()->toDateString();
        $year       = $request->get('year', now()->year);

        $base = $this->visibleLeads()->whereYear('created_at', $year);

        $total     = (clone $base)->count();
        $newCount  = (clone $base)->where('status', 'new')->count();
        $contacted = (clone $base)->where('status', 'contacted')->count();
        $qualified = (clone $base)->where('status', 'qualified')->count();
        $won       = (clone $base)->where('status', 'won')->count();
        $lost      = (clone $base)->where('status', 'lost')->count();
        $converted = (clone $base)->whereNotNull('converted_at')->count();

        $openStatuses = ['new', 'contacted', 'qualified', 'proposal', 'negotiation'];
        $openDeals    = (clone $base)->whereIn('status', $openStatuses)->count();
        $pipelineVal  = (clone $base)->whereIn('status', $openStatuses)->sum('estimated_value');

        // Won revenue is real invoice/payment money where it exists (see
        // LeadRevenueService), not the frozen deal estimate — computed once
        // here and reused below by the monthly chart and seller table so all
        // three numbers stay consistent with each other.
        $wonLeads = (clone $base)->where('status', 'won')
            ->with('invoices:id,lead_id,status,total_amount,paid_amount')
            ->get(['id', 'assigned_to', 'estimated_value', 'created_at']);

        $wonValue = 0.0;
        $pendingValue = 0.0;
        $revenueByLeadId = [];
        foreach ($wonLeads as $wonLead) {
            $revenueByLeadId[$wonLead->id] = LeadRevenueService::wonAndPending($wonLead);
            $wonValue     += $revenueByLeadId[$wonLead->id]['won'];
            $pendingValue += $revenueByLeadId[$wonLead->id]['pending'];
        }

        $winRate = ($won + $lost) > 0 ? round($won / ($won + $lost) * 100, 1) : 0.0;

        // Follow-ups are always scoped to the Seller's own queue (not
        // affected by canViewAllCompanyLeads) — mirrors FollowUpController's
        // own scoping, which is company-wide there because it's the PM/Admin
        // oversight view; here it's "my day", so scope to assigned_to.
        $todayFollowUps = FollowUp::where('company_id', $companyId)
            ->where('assigned_to', $user->id)
            ->where('status', 'pending')
            ->whereDate('scheduled_at', $today)
            ->count();

        $overdueFollowUps = FollowUp::where('company_id', $companyId)
            ->where('assigned_to', $user->id)
            ->where('status', 'pending')
            ->whereDate('scheduled_at', '<', $today)
            ->count();

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = ['month' => $m, 'total' => 0, 'won' => 0, 'lost' => 0, 'value' => 0];
        }
        (clone $base)
            ->get(['id', 'status', 'estimated_value', 'created_at'])
            ->each(function ($l) use (&$months, $revenueByLeadId) {
                $m = (int) date('n', strtotime($l->created_at));
                $months[$m]['total']++;
                if ($l->status === 'won')  { $months[$m]['won']++;  $months[$m]['value'] += $revenueByLeadId[$l->id]['won'] ?? 0.0; }
                if ($l->status === 'lost') $months[$m]['lost']++;
            });

        $byStage = (clone $base)->whereIn('status', $openStatuses)
            ->selectRaw('status, COUNT(*) as count, SUM(estimated_value) as value')
            ->groupBy('status')
            ->get()
            ->keyBy('status')
            ->map(fn($r) => ['count' => $r->count, 'value' => (float) $r->value]);

        $sellers = (clone $base)->whereNotNull('assigned_to')
            ->with('assignedTo:id,name')
            ->get(['id', 'assigned_to', 'status', 'estimated_value'])
            ->groupBy('assigned_to')
            ->map(function ($group) use ($revenueByLeadId) {
                $seller  = $group->first()->assignedTo;
                $wonRows = $group->where('status', 'won');
                $won     = $wonRows->count();
                $lost    = $group->where('status', 'lost')->count();
                return [
                    'id'            => $seller?->id,
                    'name'          => $seller?->name ?? 'Unknown',
                    'total'         => $group->count(),
                    'won'           => $won,
                    'lost'          => $lost,
                    'open'          => $group->whereIn('status', ['new','contacted','qualified','proposal','negotiation'])->count(),
                    'won_value'     => (float) $wonRows->sum(fn ($l) => $revenueByLeadId[$l->id]['won'] ?? 0.0),
                    'pending_value' => (float) $wonRows->sum(fn ($l) => $revenueByLeadId[$l->id]['pending'] ?? 0.0),
                    'win_rate'      => ($won + $lost) > 0 ? round($won / ($won + $lost) * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc('won_value')
            ->values();

        // ── Invoice Summary — this Seller's own invoices only ──────────────
        // Same InvoiceStatsService formula as Api\Admin\SalesDashboardController's
        // Invoice Summary — total_amount/paid_amount already reflect real,
        // successful (including partial) payments via InvoicePaymentService,
        // so no separate Payment-table aggregation is needed. Not year-scoped
        // like the lead funnel above, same reasoning as the Admin dashboard.
        $invoiceSummary = InvoiceStatsService::summarize(
            $this->visibleInvoices()->get(['id', 'company_id', 'total_amount', 'paid_amount', 'status', 'due_date'])
        );

        return ApiResponse::success([
            'summary' => [
                'total'              => $total,
                'new'                => $newCount,
                'contacted'          => $contacted,
                'qualified'          => $qualified,
                'won'                => $won,
                'lost'               => $lost,
                'converted'          => $converted,
                'open_deals'         => $openDeals,
                'pipeline_value'     => (float) $pipelineVal,
                'won_value'          => (float) $wonValue,
                'pending_value'      => (float) $pendingValue,
                'win_rate'           => $winRate,
                'today_followups'    => $todayFollowUps,
                'overdue_followups'  => $overdueFollowUps,
            ],
            'monthly'         => array_values($months),
            'by_stage'        => $byStage,
            'sellers'         => $sellers,
            'invoice_summary' => $invoiceSummary,
            'year'            => (int) $year,
        ]);
    }
}
