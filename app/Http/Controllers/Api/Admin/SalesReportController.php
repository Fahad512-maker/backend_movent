<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Admin\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\CompanyModule;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

// Company Admin's own Sales Reports — every lead/invoice/project for the
// company, not scoped to "assigned to me" like Api\User\SalesReportController
// (the Seller-facing version this mirrors). Company Admin was previously
// stuck using that SAME /user/sales/reports/* endpoint from the frontend,
// which 403'd every request: its canViewSalesReports check queries
// UserCompanyPermission by $user->company_id — a column CompanyAdmin simply
// doesn't have (it owns companies via a companies() relation instead), so
// the lookup always missed and denied. No permission check here at all,
// consistent with every other Admin controller in this app.
class SalesReportController extends Controller
{
    use ScopesToActiveCompany;

    private function admin() { return auth('admin')->user(); }

    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    private function moduleActive(string $key): bool
    {
        return CompanyModule::where('company_id', $this->activeCompanyId())
            ->where('module_key', $key)
            ->where('is_enabled', true)
            ->exists();
    }

    private function visibleLeads()
    {
        return Lead::whereIn('company_id', $this->activeCompanyIds());
    }

    // GET /admin/sales/reports/leads
    public function leadReport(): JsonResponse
    {
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

    // GET /admin/sales/reports/conversion
    public function conversionReport(Request $request): JsonResponse
    {
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

    // GET /admin/sales/reports/performance
    public function performanceReport(): JsonResponse
    {
        $companyIds = $this->activeCompanyIds();

        $leadStats = [
            'total'       => (clone $this->visibleLeads())->count(),
            'won'         => (clone $this->visibleLeads())->where('status', 'won')->count(),
            'lost'        => (clone $this->visibleLeads())->where('status', 'lost')->count(),
            'won_value'   => (float) (clone $this->visibleLeads())->where('status', 'won')->sum('estimated_value'),
        ];

        $result = ['leads' => $leadStats];

        if ($this->moduleActive('invoices')) {
            // A cancelled invoice is void — it was never really billed, so it
            // must not inflate "Invoiced Value" (total_value) on the Sales
            // Reports page. Excluded from the whole set, not just the sum, so
            // created_from_sales/total_paid stay consistent with it rather
            // than counting invoices the value deliberately ignores.
            $invoices = Invoice::whereIn('company_id', $companyIds)
                ->whereNotNull('lead_id')
                ->where('status', '!=', 'cancelled')
                ->get(['status', 'total_amount', 'paid_amount']);

            $result['invoices'] = [
                'created_from_sales' => $invoices->count(),
                'sent_from_sales'    => $invoices->whereIn('status', ['sent', 'partially_paid', 'paid', 'overdue'])->count(),
                'paid_from_sales'    => $invoices->where('status', 'paid')->count(),
                'total_value'        => (float) $invoices->sum('total_amount'),
                'total_paid'         => (float) $invoices->sum('paid_amount'),
            ];
        }

        if ($this->moduleActive('projects')) {
            $result['projects'] = [
                'linked_from_leads' => Project::whereIn('company_id', $companyIds)
                    ->whereNotNull('lead_id')
                    ->count(),
            ];
        }

        return ApiResponse::success($result);
    }

    // GET /admin/sales/reports/leads/export — CSV
    public function exportLeadReport(): JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse
    {
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
