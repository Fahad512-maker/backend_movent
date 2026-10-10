<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Admin\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\FollowUp;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\User;
use App\Services\InvoiceStatsService;
use App\Services\LeadRevenueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesDashboardController extends Controller
{
    use ScopesToActiveCompany;

    private function companyIds(): array
    {
        return auth('admin')->user()->companies()->pluck('id')->toArray();
    }

    public function index(Request $request): JsonResponse
    {
        $companyIds = $this->activeCompanyIds();
        $today      = now()->toDateString();
        $month      = $request->get('month', now()->month);
        $year       = $request->get('year',  now()->year);

        $base = Lead::whereIn('company_id', $companyIds)->whereYear('created_at', $year);

        // ── Summary counts ──────────────────────────────────────────────────
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

        $todayFollowUps = FollowUp::whereIn('company_id', $companyIds)
            ->where('status', 'pending')
            ->whereDate('scheduled_at', $today)
            ->count();

        $overdueFollowUps = FollowUp::whereIn('company_id', $companyIds)
            ->where('status', 'pending')
            ->whereDate('scheduled_at', '<', $today)
            ->count();

        // ── Monthly summary ─────────────────────────────────────────────────
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

        // ── Stage distribution ──────────────────────────────────────────────
        $byStage = (clone $base)->whereIn('status', $openStatuses)
            ->selectRaw('status, COUNT(*) as count, SUM(estimated_value) as value')
            ->groupBy('status')
            ->get()
            ->keyBy('status')
            ->map(fn($r) => ['count' => $r->count, 'value' => (float) $r->value]);

        // ── Seller performance ──────────────────────────────────────────────
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

        // ── Invoice Summary ─────────────────────────────────────────────────
        // Every invoice belonging to the currently-selected company (all
        // companies if no single one is active) — same InvoiceStatsService
        // used by the Client detail page's own invoice stats, so this never
        // duplicates the invoiced/paid/outstanding/overdue formula. total_amount/
        // paid_amount already reflect real, successful payments (including
        // partials) via InvoicePaymentService, so no separate Payment-table
        // aggregation is needed here. Not year-scoped like the lead funnel
        // above — this reflects the company's real invoice book, not a
        // per-year lead cohort.
        $invoiceSummary = InvoiceStatsService::summarize(
            Invoice::whereIn('company_id', $companyIds)->get(['id', 'company_id', 'total_amount', 'paid_amount', 'status', 'due_date'])
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
