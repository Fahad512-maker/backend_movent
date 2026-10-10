<?php

namespace App\Http\Controllers\Api\Client;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Client\Concerns\ScopesToPermittedClient;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    use ScopesToPermittedClient;

    private function clientIds(Request $request): array
    {
        return $this->permittedClientIds($request, 'reports');
    }

    public function projects(Request $request): JsonResponse
    {
        $clientIds = $this->clientIds($request);

        $projects = Project::whereIn('client_id', $clientIds)
            ->notDraft()
            ->with(['projectManager:id,name'])
            ->get(['id', 'name', 'status', 'start_date', 'deadline', 'completed_at', 'project_manager_id', 'created_at']);

        $summary = [
            'total'       => $projects->count(),
            'active'      => $projects->whereIn('status', ['active', 'in_progress'])->count(),
            'planning'    => $projects->whereIn('status', ['planning', 'draft'])->count(),
            'completed'   => $projects->where('status', 'completed')->count(),
            'on_hold'     => $projects->where('status', 'on_hold')->count(),
        ];

        return ApiResponse::success([
            'summary'  => $summary,
            'projects' => $projects,
        ]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $clientIds = $this->clientIds($request);

        $invoices = Invoice::whereIn('client_id', $clientIds)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->orderBy('created_at')
            ->get(['id', 'invoice_number', 'total_amount', 'paid_amount', 'currency', 'status', 'created_at', 'due_date']);

        // Grouped by currency, not blended — see Client\DashboardController's
        // stats for the same reasoning (a client can have invoices in more
        // than one currency; summing raw amounts across currencies as one
        // number is meaningless).
        $summary = $invoices->groupBy('currency')->map(fn($group, $currency) => [
            'currency'       => $currency,
            'total_invoiced' => (float) $group->sum('total_amount'),
            'total_paid'     => (float) $group->where('status', 'paid')->sum('total_amount'),
            'total_pending'  => (float) $group->whereIn('status', ['sent', 'payment_pending'])->sum('total_amount'),
            'total_overdue'  => (float) $group->where('status', 'overdue')->sum('total_amount'),
        ])->values();

        // Monthly breakdown — last 6 months, per currency within each month
        $monthly = $invoices
            ->groupBy(fn($inv) => Carbon::parse($inv->created_at)->format('Y-m'))
            ->map(fn($group, $month) => [
                'month'       => $month,
                'by_currency' => $group->groupBy('currency')->map(fn($g, $currency) => [
                    'currency' => $currency,
                    'count'    => $g->count(),
                    'total'    => (float) $g->sum('total_amount'),
                    'paid'     => (float) $g->where('status', 'paid')->sum('total_amount'),
                    'pending'  => (float) $g->whereIn('status', ['sent', 'payment_pending', 'overdue'])->sum('total_amount'),
                ])->values(),
            ])
            ->sortKeys()
            ->slice(-6)
            ->values();

        return ApiResponse::success([
            'summary' => $summary,
            'monthly' => $monthly,
            'list'    => $invoices,
        ]);
    }
}
