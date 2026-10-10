<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Models\CompanyModule;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Project;
use App\Models\UserCompanyPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

// Seller-facing Sales reports — lead report, conversion report, sales
// performance (including invoice-from-sales and linked-project stats when
// those modules are active). Scoped the same way as
// Api\User\LeadController::visibleLeads() (own/assigned unless
// canViewAllCompanyLeads).
class SalesReportController extends Controller
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

    private function moduleActive(string $key): bool
    {
        return CompanyModule::where('company_id', $this->user()->company_id)
            ->where('module_key', $key)
            ->where('is_enabled', true)
            ->exists();
    }

    private function visibleLeads()
    {
        $user = $this->user();
        $base = Lead::where('company_id', $user->company_id);

        if ($this->can('canViewAllCompanyLeads')) {
            return $base;
        }

        return $base->where('assigned_to', $user->id);
    }

    // GET /user/sales/reports/leads
    public function leadReport(): JsonResponse
    {
        if (!$this->can('canViewSalesReports')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $byStatus = (clone $this->visibleLeads())
            ->selectRaw('status, COUNT(*) as count, SUM(estimated_value) as value')
            ->groupBy('status')->get()->keyBy('status');

        $bySource = (clone $this->visibleLeads())
            ->whereNotNull('source')
            ->selectRaw('source, COUNT(*) as count')
            ->groupBy('source')->pluck('count', 'source');

        return ApiResponse::success([
            'by_status' => $byStatus,
            'by_source' => $bySource,
            'total'     => (clone $this->visibleLeads())->count(),
        ]);
    }

    // GET /user/sales/reports/conversion
    public function conversionReport(Request $request): JsonResponse
    {
        if (!$this->can('canViewSalesReports')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $year = (int) ($request->year ?? now()->year);
        $base = (clone $this->visibleLeads())->whereYear('created_at', $year);

        $months = [];
        for ($m = 1; $m <= 12; $m++) $months[$m] = ['month' => $m, 'total' => 0, 'won' => 0, 'lost' => 0];

        (clone $base)
            ->get(['status', 'created_at'])
            ->each(function ($l) use (&$months) {
                $m = (int) date('n', strtotime($l->created_at));
                $months[$m]['total']++;
                if ($l->status === 'won')  $months[$m]['won']++;
                if ($l->status === 'lost') $months[$m]['lost']++;
            });

        $total = (clone $base)->count();
        $won   = (clone $base)->where('status', 'won')->count();

        return ApiResponse::success([
            'monthly'          => array_values($months),
            'overall_win_rate' => $total > 0 ? round(($won / $total) * 100, 1) : 0,
            'year'             => $year,
        ]);
    }

    // GET /user/sales/reports/performance
    public function performanceReport(): JsonResponse
    {
        if (!$this->can('canViewSalesReports')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $user = $this->user();

        $leadStats = [
            'total'       => (clone $this->visibleLeads())->count(),
            'won'         => (clone $this->visibleLeads())->where('status', 'won')->count(),
            'lost'        => (clone $this->visibleLeads())->where('status', 'lost')->count(),
            'won_value'   => (float) (clone $this->visibleLeads())->where('status', 'won')->sum('estimated_value'),
        ];

        $result = ['leads' => $leadStats];

        if ($this->moduleActive('invoices')) {
            // Same canViewAllCompanyLeads override visibleLeads() above
            // already applies — without it, a Lead Manager (who doesn't
            // personally create every invoice, just oversees the team's)
            // saw this whole "invoices"/"projects" block reporting zero
            // regardless of how much the team actually sold.
            // A cancelled invoice is void — it must not inflate "Invoiced
            // Value" (total_value). Same exclusion as
            // Api\Admin\SalesReportController's equivalent query.
            $invoiceQuery = Invoice::where('company_id', $user->company_id)
                ->whereNotNull('lead_id')
                ->where('status', '!=', 'cancelled');
            if (!$this->can('canViewAllCompanyLeads')) {
                $invoiceQuery->where('created_by', $user->id);
            }
            $invoices = $invoiceQuery->get(['status', 'total_amount', 'paid_amount']);

            $result['invoices'] = [
                'created_from_sales' => $invoices->count(),
                'sent_from_sales'    => $invoices->whereIn('status', ['sent', 'partially_paid', 'paid', 'overdue'])->count(),
                'paid_from_sales'    => $invoices->where('status', 'paid')->count(),
                'total_value'        => (float) $invoices->sum('total_amount'),
                'total_paid'         => (float) $invoices->sum('paid_amount'),
            ];
        }

        if ($this->moduleActive('projects')) {
            $projectQuery = Project::where('company_id', $user->company_id)->whereNotNull('lead_id');
            if (!$this->can('canViewAllCompanyLeads')) {
                $projectQuery->whereHas('lead', fn ($q) => $q->where('assigned_to', $user->id));
            }
            $result['projects'] = [
                'linked_from_leads' => $projectQuery->count(),
            ];
        }

        return ApiResponse::success($result);
    }

    // GET /user/sales/reports/leads/export — CSV
    public function exportLeadReport(): JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        if (!$this->can('canExportSalesReports')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $leads = (clone $this->visibleLeads())->with('assignedTo:id,name')->get();

        return Response::streamDownload(function () use ($leads) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Name', 'Email', 'Phone', 'Status', 'Priority', 'Estimated Value', 'Assigned To', 'Created At']);
            foreach ($leads as $l) {
                fputcsv($out, [
                    $l->name, $l->email, $l->phone, $l->status, $l->priority,
                    $l->estimated_value, $l->assignedTo?->name ?? '—', $l->created_at,
                ]);
            }
            fclose($out);
        }, 'sales-leads-report.csv', ['Content-Type' => 'text/csv']);
    }
}
