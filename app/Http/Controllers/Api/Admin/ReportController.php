<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Admin\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\CompanyPaymentGateway;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    use ScopesToActiveCompany;

    private function admin() { return auth('admin')->user(); }

    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    // GET /admin/reports/invoices
    public function invoices(Request $request): JsonResponse
    {
        $year = (int) ($request->year ?? now()->year);

        // Company-Wise Dashboard Filtering — defaults to the active company
        // (or every owned company when "All Companies" is selected, per the
        // Navbar's CompanySelector), narrowed further by an explicit
        // ?company_id= override when given. This previously always used
        // every owned company regardless of the active-company selector,
        // so switching companies had no visible effect on this page.
        if ($request->filled('company_id')) {
            $requested  = (int) $request->company_id;
            $companyIds = in_array($requested, $this->companyIds(), true) ? [$requested] : $this->activeCompanyIds();
        } else {
            $companyIds = $this->activeCompanyIds();
        }

        $base = Invoice::whereIn('company_id', $companyIds)->whereYear('created_at', $year);

        // ── Summary ──────────────────────────────────────────────────────────
        // Counts are currency-agnostic (just document counts, safe to
        // blend); every money figure is grouped by currency instead —
        // an admin can have invoices in more than one currency (e.g. after
        // switching their Settings currency), and summing raw amounts
        // across currencies as one number is meaningless.
        $all = (clone $base)->get(['status', 'total_amount', 'paid_amount', 'due_date', 'currency']);

        $summary = [
            'total_count'      => $all->count(),
            'paid_count'       => $all->where('status', 'paid')->count(),
            'unpaid_count'     => $all->whereIn('status', ['draft', 'sent', 'partially_paid'])->count(),
            'overdue_count'    => $all->where('status', 'overdue')->count(),
            'cancelled_count'  => $all->where('status', 'cancelled')->count(),
            // A cancelled invoice is void — excluded from the money rollups
            // only. cancelled_count above and the by_status breakdown below
            // still report them in full.
            'by_currency'      => $all->where('status', '!=', 'cancelled')->groupBy('currency')->map(fn($g, $currency) => [
                'currency'          => $currency,
                'total_invoiced'    => (float) $g->sum('total_amount'),
                'total_paid'        => (float) $g->sum('paid_amount'),
                'total_outstanding' => (float) $g->sum(fn($i) => max(0, $i->total_amount - $i->paid_amount)),
            ])->values(),
        ];

        // ── By status ─────────────────────────────────────────────────────────
        $byStatus = $all->groupBy('status')->map(fn($g) => [
            'count'       => $g->count(),
            'by_currency' => $g->groupBy('currency')->map(fn($cg, $currency) => [
                'currency' => $currency,
                'amount'   => (float) $cg->sum('total_amount'),
            ])->values(),
        ])->toArray();

        // ── Monthly trend (selected year) — per currency within each month ────
        $monthly = [];
        for ($m = 1; $m <= 12; $m++) {
            $monthly[$m] = [];
        }
        (clone $base)
            ->get(['total_amount', 'paid_amount', 'created_at', 'currency'])
            ->each(function ($inv) use (&$monthly) {
                $m   = (int) date('n', strtotime($inv->created_at));
                $cur = $inv->currency;
                $monthly[$m][$cur] ??= ['currency' => $cur, 'invoiced' => 0, 'paid' => 0, 'count' => 0];
                $monthly[$m][$cur]['invoiced'] += $inv->total_amount;
                $monthly[$m][$cur]['paid']     += $inv->paid_amount;
                $monthly[$m][$cur]['count']++;
            });
        $monthlyArr = array_values(array_map(
            fn($m, $d) => ['month' => $m, 'by_currency' => array_values($d)],
            array_keys($monthly), $monthly
        ));

        // ── Top clients by invoice count ────────────────────────────────────────
        // Ranked by document count, not amount — a client's invoices can
        // span more than one currency, and there's no meaningful single
        // "total" to sort by without blending units.
        $topClients = (clone $base)
            ->with('client:id,name,company_name')
            ->whereNotNull('client_id')
            ->get(['client_id', 'total_amount', 'paid_amount', 'status', 'currency'])
            ->groupBy('client_id')
            ->map(fn($g) => [
                'client_id'   => $g->first()->client_id,
                'name'        => $g->first()->client?->name ?? 'Unknown',
                'company'     => $g->first()->client?->company_name,
                'count'       => $g->count(),
                'by_currency' => $g->groupBy('currency')->map(fn($cg, $currency) => [
                    'currency'    => $currency,
                    'total'       => (float) $cg->sum('total_amount'),
                    'paid'        => (float) $cg->sum('paid_amount'),
                    'outstanding' => (float) $cg->sum(fn($i) => max(0, $i->total_amount - $i->paid_amount)),
                ])->values(),
            ])
            ->sortByDesc('count')
            ->values()
            ->take(10);

        // ── Recent payments ────────────────────────────────────────────────────
        // Show every recent attempt, not just confirmed ones — the frontend
        // now colors/labels each row by its own status (red for a rejected
        // payment, green for confirmed, amber for pending) so the admin can
        // tell them apart, instead of silently filtering rejected ones out.
        // Capped well above the frontend's 10-per-slide so its Next/Previous
        // slider has real pages to browse instead of always landing on slide
        // 1 of 1 — 300 is just a sanity ceiling against a pathological year.
        $recentPayments = Payment::whereHas('invoice', fn($q) => $q->whereIn('company_id', $companyIds))
            ->whereYear('payment_date', $year)
            ->with(
                'invoice:id,invoice_number,client_id,lead_id,currency',
                // A guest/lead-only invoice (no client_id yet) still needs a
                // name here — falls back to the Lead below.
                'invoice.client:id,name',
                'invoice.lead:id,name',
                'companyGateway:id,label,gateway'
            )
            ->latest()
            ->limit(300)
            ->get(['id', 'invoice_id', 'amount', 'currency', 'method', 'gateway', 'company_gateway_id', 'status', 'payment_date', 'created_at'])
            ->map(fn($p) => [
                'id'             => $p->id,
                'amount'         => $p->amount,
                // Falls back to the invoice's own currency for a payment
                // recorded before payments.currency was captured.
                'currency'       => $p->currency ?? $p->invoice?->currency,
                'method'         => $p->method,
                'status'         => $p->status,
                'payment_date'   => $p->payment_date,
                'invoice_number' => $p->invoice?->invoice_number,
                'customer_name'  => $p->invoice?->client?->name ?? $p->invoice?->lead?->name ?? '—',
                // Only meaningful for an online charge — bank_transfer/cash/
                // cheque have no gateway account behind them.
                'gateway_name'   => $p->method === 'gateway'
                    ? ($p->companyGateway?->label ?? CompanyPaymentGateway::GATEWAYS[$p->gateway] ?? ($p->gateway ? ucfirst($p->gateway) : null))
                    : null,
            ]);

        return ApiResponse::success([
            'summary'         => $summary,
            'by_status'       => $byStatus,
            'monthly'         => $monthlyArr,
            'top_clients'     => $topClients,
            'recent_payments' => $recentPayments,
            'year'            => $year,
        ]);
    }
}
