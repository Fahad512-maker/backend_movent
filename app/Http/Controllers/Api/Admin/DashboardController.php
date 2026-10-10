<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Admin\Concerns\ScopesToActiveCompany;
use App\Models\Client;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Module;
use App\Models\Payment;
use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    use ScopesToActiveCompany;

    private function admin()
    {
        return auth('admin')->user();
    }

    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    public function index(): JsonResponse
    {
        $admin      = $this->admin();
        $package    = $admin->package;
        // Company-Wise Dashboard Filtering — every metric block below
        // already just filters on this one array, so narrowing it to a
        // single active company (instead of every company this admin owns)
        // is the entire change; a 1-element whereIn() behaves like where().
        $companyIds = $this->activeCompanyIds();

        // ── Leads ───────────────────────────────────────────────────────
        $leads = Lead::whereIn('company_id', $companyIds);
        $leadsTotal  = (clone $leads)->count();
        $leadsNew    = (clone $leads)->where('status', 'new')->count();
        $leadsWon    = (clone $leads)->where('status', 'won')->count();
        $leadsLost   = (clone $leads)->where('status', 'lost')->count();

        $leadsByStatus = (clone $leads)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        // ── Clients ─────────────────────────────────────────────────────
        $clients       = Client::whereIn('company_id', $companyIds);
        $clientsTotal  = (clone $clients)->count();
        $clientsPortal = (clone $clients)->where('portal_access', true)->count();
        $clientsActive = (clone $clients)->where('status', 'active')->count();

        // ── Projects ────────────────────────────────────────────────────
        $projects        = Project::whereIn('company_id', $companyIds);
        $projectsTotal   = (clone $projects)->count();
        $projectsActive  = (clone $projects)->where('status', 'active')->count();
        $projectsDone    = (clone $projects)->where('status', 'completed')->count();
        $projectsOnHold  = (clone $projects)->where('status', 'on_hold')->count();

        $projectsByStatus = (clone $projects)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        // ── Tasks ───────────────────────────────────────────────────────
        $projectIdList = Project::whereIn('company_id', $companyIds)->pluck('id');
        $tasks         = Task::whereIn('project_id', $projectIdList);
        $tasksTodo     = (clone $tasks)->where('status', 'todo')->count();
        $tasksInProg   = (clone $tasks)->where('status', 'in_progress')->count();
        $tasksDone     = (clone $tasks)->where('status', 'completed')->count();
        $tasksOverdue  = (clone $tasks)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereNotNull('due_date')
            ->where('due_date', '<', now()->toDateString())
            ->count();

        // ── Invoices ────────────────────────────────────────────────────
        // status enum: draft, sent, partially_paid, paid, overdue, cancelled
        $invoices         = Invoice::whereIn('company_id', $companyIds);
        $invoicesTotal    = (clone $invoices)->count();
        $invoicesUnpaid   = (clone $invoices)->whereIn('status', ['sent', 'partially_paid'])->count();
        $invoicesOverdue  = (clone $invoices)->where('status', 'overdue')->count();
        $invoicesPaid     = (clone $invoices)->where('status', 'paid')->count();

        // Grouped by currency, not blended — an admin can have invoices in
        // more than one currency (e.g. after switching their Settings
        // currency), and summing raw amounts across currencies as one
        // number is meaningless. Mirrors Admin\ReportController::invoices().
        $invoiceMoney = (clone $invoices)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->get(['status', 'total_amount', 'currency']);
        $invoicesByCurrency = $invoiceMoney->groupBy('currency')->map(fn($g, $currency) => [
            'currency'     => $currency,
            'total_billed' => (float) $g->sum('total_amount'),
            'total_unpaid' => (float) $g->whereIn('status', ['sent', 'partially_paid', 'overdue'])->sum('total_amount'),
        ])->values();

        $invoicesByStatus = (clone $invoices)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        // ── Payments ────────────────────────────────────────────────────
        // payments has no company_id — join through invoices
        // status enum: confirmed, pending, failed, refunded
        $invoiceIds = Invoice::whereIn('company_id', $companyIds)->pluck('id');
        $paymentMoney = Payment::whereIn('invoice_id', $invoiceIds)
            ->where('status', 'confirmed')
            ->with('invoice:id,currency')
            ->get(['id', 'invoice_id', 'amount', 'currency', 'created_at']);
        $paymentsByCurrency = $paymentMoney
            ->groupBy(fn($p) => $p->currency ?? $p->invoice?->currency ?? 'USD')
            ->map(fn($g, $currency) => [
                'currency'       => $currency,
                'total_received' => (float) $g->sum('amount'),
                'this_month'     => (float) $g->filter(
                    fn($p) => $p->created_at->month === now()->month && $p->created_at->year === now()->year
                )->sum('amount'),
            ])->values();

        // ── Employees / Users ────────────────────────────────────────────
        $employeesTotal  = Employee::whereIn('company_id', $companyIds)->count();
        $usersTotal      = User::whereIn('company_id', $companyIds)
            ->where('role_type', '!=', 'client')
            ->count();
        $clientPortalUsers = User::whereIn('company_id', $companyIds)
            ->where('role_type', 'client')
            ->count();

        // ── Support Tickets ─────────────────────────────────────────────
        $ticketsOpen     = SupportTicket::whereIn('company_id', $companyIds)
            ->where('status', 'open')->count();

        // ── Recent Items ────────────────────────────────────────────────
        $recentLeads = Lead::whereIn('company_id', $companyIds)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'name', 'email', 'status', 'source', 'created_at']);

        $recentClients = Client::whereIn('company_id', $companyIds)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'name', 'company_name', 'portal_access', 'status', 'created_at']);

        $recentInvoices = Invoice::whereIn('company_id', $companyIds)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'invoice_number', 'client_id', 'total_amount', 'currency', 'status', 'due_date', 'created_at']);

        $recentProjects = Project::whereIn('company_id', $companyIds)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'name', 'status', 'deadline', 'created_at']);

        // ── Companies breakdown ──────────────────────────────────────────
        $companies = $admin->companies()->withCount([
            'clients',
            'clients as portal_clients_count' => fn($q) => $q->where('portal_access', true),
        ])->get(['id', 'name', 'is_active']);

        $compInfo = $this->companyInfo($admin, $package);

        // Modules enabled for the ACTIVE company (not just "first" — these
        // gate which dashboard sections even render, so they must match
        // whichever company the stats above were just scoped to, or
        // switching companies would show one company's stats under
        // another's module set).
        // Super-Admin-deactivated modules dropped, the same as AdminResource —
        // CheckCompanyModule refuses them, so no dashboard section for them.
        $enabledModules = Company::find($companyIds[0])?->modules()
            ->where('is_enabled', true)
            ->pluck('module_key')
            ->diff(Module::disabledSubModuleKeys())
            ->values()
            ->toArray() ?? [];

        // Org-level user seat stats (admin counts as 1)
        $usersUsed  = User::whereIn('company_id', $companyIds)->count() + 1;
        $usersLimit = $package?->max_users_per_company;

        return ApiResponse::success([
            // Purchased modules (used by frontend to gate dashboard sections)
            'modules' => $enabledModules,

            // Plan
            'plan' => [
                'name'                  => $package?->name,
                'tier'                  => $package?->tier,
                'max_companies'         => $package?->max_companies,
                'max_users_per_company' => $usersLimit,
                'companies_used'        => $compInfo['used'],
                'can_add_company'       => $compInfo['can_add'],
                'subscription_status'   => $admin->subscription_status,
                'trial_ends_at'         => $admin->trial_ends_at?->toDateString(),
                'subscription_ends_at'  => $admin->subscription_ends_at?->toDateString(),
                'users_used'            => $usersUsed,
                'users_limit'           => $usersLimit,
            ],

            // Stats
            'stats' => [
                'leads'     => ['total' => $leadsTotal,    'new' => $leadsNew,       'won' => $leadsWon,        'lost' => $leadsLost],
                'clients'   => ['total' => $clientsTotal,  'portal' => $clientsPortal,'active' => $clientsActive],
                'projects'  => ['total' => $projectsTotal, 'active' => $projectsActive,'done' => $projectsDone,  'on_hold' => $projectsOnHold],
                'tasks'     => ['todo' => $tasksTodo, 'in_progress' => $tasksInProg, 'completed' => $tasksDone, 'overdue' => $tasksOverdue],
                'invoices'  => ['total' => $invoicesTotal, 'unpaid' => $invoicesUnpaid, 'overdue' => $invoicesOverdue, 'paid' => $invoicesPaid,
                                'by_currency' => $invoicesByCurrency],
                'payments'  => ['by_currency' => $paymentsByCurrency],
                'employees' => ['total' => $employeesTotal, 'users' => $usersTotal, 'portal_users' => $clientPortalUsers],
                'support'   => ['open_tickets' => $ticketsOpen],
            ],

            // Breakdowns
            'by_status' => [
                'leads'    => $leadsByStatus,
                'projects' => $projectsByStatus,
                'invoices' => $invoicesByStatus,
            ],

            // Recent activity
            'recent' => [
                'leads'    => $recentLeads,
                'clients'  => $recentClients,
                'invoices' => $recentInvoices,
                'projects' => $recentProjects,
            ],

            // Companies
            'companies' => $companies->map(fn($c) => [
                'id'                  => $c->id,
                'name'                => $c->name,
                'is_active'           => $c->is_active,
                'clients_count'       => $c->clients_count,
                'portal_clients_count'=> $c->portal_clients_count,
                'seat_limit'          => $package?->max_users_per_company,
            ]),
        ]);
    }

    private function companyInfo($admin, $package): array
    {
        $max  = $package?->max_companies ?? null;
        $used = $admin->companies()->count();
        return ['max' => $max, 'used' => $used, 'can_add' => $max === null || $used < $max];
    }
}
