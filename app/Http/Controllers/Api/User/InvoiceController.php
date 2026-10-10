<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\User\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Mail\InvoiceMail;
use App\Models\Brand;
use App\Models\Client;
use App\Models\ClientAiServiceBatchItem;
use App\Models\Company;
use App\Models\CompanyDealSettings;
use App\Models\CompanyPaymentGateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Task;
use App\Services\InvoiceNotificationService;
use App\Services\LeadBudgetContext;
use App\Models\SystemAuditLog;
use App\Models\UserCompanyPermission;
use App\Rules\ValidPhoneNumber;
use App\Support\FrontendUrl;
use App\Support\PermissionDebug;
use App\Support\PublicFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class InvoiceController extends Controller
{
    use ScopesToActiveCompany;

    private function user() { return auth('sanctum')->user(); }

    private function can(string $permKey, string $moduleKey = 'invoice'): bool
    {
        $user = $this->user();
        $result = UserCompanyPermission::where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->where('module_key', $moduleKey)
            ->where('permission_key', $permKey)
            ->exists();
        PermissionDebug::log($user->id, $user->company_id, $user->role_type, $moduleKey, $permKey, $result);
        return $result;
    }

    /**
     * This Invoice-module right, or its Finance-module equivalent.
     *
     * The Finance Area (see App\Services\Finance) serves the SAME invoices
     * through the same endpoints — it deliberately has no invoice-writing
     * controller of its own, because a second implementation of invoice
     * creation is exactly what the Finance spec forbids. So a caller reaches
     * these endpoints holding either module's key, and every gate below
     * accepts both. Purely additive: nobody who could call one of these
     * before can be refused now.
     */
    private function canEither(string $invoiceKey, string $financeKey): bool
    {
        return $this->can($invoiceKey) || $this->can($financeKey, 'finance');
    }

    // Mirrors Api\User\ClientController::visibleClients() — without
    // canViewAllCompanyInvoices, a plain Seller only saw invoices they
    // personally created, even one a Lead Manager generated for a lead that
    // was then assigned to them. Now also visible if tied (via lead/client/
    // project) to this user, same as canViewAllCompanyLeads/Clients already
    // work for their respective modules.
    private function visibleInvoices()
    {
        $user = $this->user();
        $base = Invoice::where('company_id', $this->activeCompanyId());

        // canViewAllCompanyFinance is the Finance module's own copy of this
        // switch — a Finance User holds no Invoice-module permission at all,
        // so without it every invoice screen would come back empty for the
        // one role whose entire job is looking at them. Same widening, same
        // rows; see App\Services\Finance\FinanceScope, which applies the
        // identical pair.
        if ($this->can('canViewAllCompanyInvoices') || $this->can('canViewAllCompanyFinance', 'finance')) {
            return $base;
        }

        return $base->where(function ($q) use ($user) {
            $q->where('created_by', $user->id)
              ->orWhereHas('lead', fn ($l) => $l->where('assigned_to', $user->id)->orWhere('transferred_to', $user->id))
              ->orWhereHas('client', fn ($c) => $c->where('account_manager', $user->id))
              ->orWhereHas('project', fn ($p) => $p->where('seller_id', $user->id));
        });
    }

    // Surfaces in Company Admin's notification bell (Api\Admin\NotificationController
    // reads SystemAuditLog unfiltered by action — Admin has no Notification rows).
    private function auditLog(Invoice $invoice, string $action, string $preview): void
    {
        $user = $this->user();
        SystemAuditLog::create([
            'company_id'  => $invoice->company_id,
            'user_id'     => $user->id,
            'action'      => $action,
            'module_key'  => 'invoice',
            'entity_type' => 'Invoice',
            'entity_id'   => $invoice->id,
            'new_values'  => ['preview' => $preview, 'author' => $user->name ?? 'User'],
        ]);
    }

    // GET /user/reports/invoices
    public function report(Request $request): JsonResponse
    {
        if (!$this->can('canViewInvoiceReports')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $year = (int) ($request->year ?? now()->year);

        // Same scope as index()/show() — without canViewAllCompanyInvoices a
        // Lead Manager (who mostly oversees rather than personally raises
        // every invoice) was seeing an entirely empty report, since this used
        // to hard-filter to invoices they themselves created.
        $base = $this->visibleInvoices()->whereYear('created_at', $year);

        $all = (clone $base)->get(['status', 'total_amount', 'paid_amount', 'due_date', 'currency']);

        $summary = [
            'total_count'      => $all->count(),
            'paid_count'       => $all->where('status', 'paid')->count(),
            'unpaid_count'     => $all->whereIn('status', ['draft', 'sent', 'partially_paid'])->count(),
            'overdue_count'    => $all->where('status', 'overdue')->count(),
            'cancelled_count'  => $all->where('status', 'cancelled')->count(),
            // A cancelled invoice is void — excluded from the money rollups
            // only, same as Api\Admin\ReportController::invoices(). The
            // cancelled_count above and by_status below still report them.
            'by_currency'      => $all->where('status', '!=', 'cancelled')->groupBy('currency')->map(fn($g, $currency) => [
                'currency'          => $currency,
                'total_invoiced'    => (float) $g->sum('total_amount'),
                'total_paid'        => (float) $g->sum('paid_amount'),
                'total_outstanding' => (float) $g->sum(fn($i) => max(0, $i->total_amount - $i->paid_amount)),
            ])->values(),
        ];

        $byStatus = $all->groupBy('status')->map(fn($g) => [
            'count'       => $g->count(),
            'by_currency' => $g->groupBy('currency')->map(fn($cg, $currency) => [
                'currency' => $currency,
                'amount'   => (float) $cg->sum('total_amount'),
            ])->values(),
        ])->toArray();

        $monthly = [];
        for ($m = 1; $m <= 12; $m++) $monthly[$m] = [];
        (clone $base)->get(['total_amount', 'paid_amount', 'created_at', 'currency'])
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

        // Ranked by invoice count, not amount — a client's invoices can span
        // more than one currency, and there's no meaningful single "total"
        // to sort by without blending units.
        $topClients = (clone $base)->with('client:id,name,company_name')
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
            ->sortByDesc('count')->values()->take(10);

        $visibleInvoiceIds = $this->visibleInvoices()->pluck('id');

        // Capped well above the frontend's 10-per-slide so its Next/Previous
        // slider has real pages to browse — 300 is just a sanity ceiling
        // against a pathological year.
        $recentPayments = Payment::whereIn('invoice_id', $visibleInvoiceIds)
            ->whereYear('payment_date', $year)
            ->with(
                'invoice:id,invoice_number,client_id,lead_id,currency',
                // A guest/lead-only invoice (no client_id yet) still needs a
                // name here — falls back to the Lead below.
                'invoice.client:id,name',
                'invoice.lead:id,name',
                'companyGateway:id,label,gateway'
            )
            ->latest()->limit(300)
            ->get(['id', 'invoice_id', 'amount', 'currency', 'method', 'gateway', 'company_gateway_id', 'status', 'payment_date'])
            ->map(fn($p) => [
                'id'             => $p->id,
                'amount'         => $p->amount,
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

    private function nextNumber(int $companyId): string
    {
        $year   = now()->year;
        $prefix = \App\Models\Company::find($companyId)?->invoicingProfile()['invoice_prefix'] ?? 'INV';

        $last = Invoice::whereYear('created_at', $year)
            ->where('invoice_number', 'like', "{$prefix}-{$year}-%")
            ->latest('id')
            ->value('invoice_number');

        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        do {
            $number = sprintf('%s-%d-%04d', $prefix, $year, $seq++);
        } while (Invoice::where('invoice_number', $number)->exists());

        return $number;
    }

    // GET /user/invoices/{id}
    public function show(int $id): JsonResponse
    {
        if (!$this->canEither('canViewInvoices', 'canViewFinanceInvoices')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $invoice = $this->visibleInvoices()
            ->where('id', $id)
            // company:id,name — the Edit screen names it (see the matching
            // note in Api\Admin\InvoiceController::show()). Distinct from the
            // branding block below, which on a Brand Invoice is the brand.
            ->with(['client:id,name,company_name,email,phone,address', 'items', 'payments', 'lead:id,deal_reference,proposed_project_title,fulfillment_status', 'brand', 'company:id,name'])
            ->first();

        if (!$invoice) {
            return ApiResponse::error('Invoice not found', 404);
        }

        $data = $invoice->toArray();
        $data['gateway_account_ids'] = $invoice->paymentGatewayAccounts()->pluck('company_payment_gateways.id')->toArray();
        $data['project_history'] = $this->invoiceProjectHistory($invoice);
        // Same issuer block the Admin side returns — the shared invoice detail
        // page reads one shape regardless of who is signed in.
        $profile = $invoice->brandingProfile();
        $data['branding'] = [
            'invoice_type' => $profile['invoice_type'] ?? 'company',
            'name'         => $profile['name'] ?? null,
            'logo_url'     => PublicFile::url($profile['logo_path'] ?? null),
            'email'        => $profile['email'] ?? null,
            'phone'        => $profile['phone'] ?? null,
            'website'      => $profile['website'] ?? null,
            'address'      => $profile['address'] ?? null,
            'country'      => $profile['country'] ?? null,
            'brand'        => $profile['brand'] ?? null,
        ];

        return ApiResponse::success($data);
    }

    // Every project tied to this invoice's client or deal — completed,
    // previous and latest — so the invoice page can show the same full
    // history a client sees for their own account in the Client Portal (see
    // Api\Client\ProjectController::index()). Matched on client_id AND
    // lead_id (not lead_id alone): only the project a Lead directly handed
    // off carries lead_id — a client's other/earlier projects only carry
    // client_id, and a still-unconverted Deal's invoice has no client_id at
    // all, so either id alone would miss real history.
    private function invoiceProjectHistory(Invoice $invoice): array
    {
        $clientId = $invoice->client_id ?: $invoice->lead?->client?->id;
        $leadId   = $invoice->lead_id;

        if (!$clientId && !$leadId) {
            return [];
        }

        $projects = Project::where(function ($q) use ($clientId, $leadId) {
                if ($clientId) { $q->orWhere('client_id', $clientId); }
                if ($leadId)   { $q->orWhere('lead_id', $leadId); }
            })
            ->notDraft()
            ->with('projectManager:id,name')
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'status', 'start_date', 'deadline', 'project_manager_id', 'created_at']);

        $taskStats = Task::whereIn('project_id', $projects->pluck('id'))
            ->selectRaw('project_id, COUNT(*) as total, SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as done')
            ->groupBy('project_id')
            ->get()
            ->keyBy('project_id');

        return $projects->map(function ($p) use ($taskStats) {
            $stats = $taskStats[$p->id] ?? null;
            $p->progress = in_array($p->status, ['completed', 'closed'], true)
                ? 100
                : ($stats && $stats->total > 0 ? round(($stats->done / $stats->total) * 100) : 0);
            return $p;
        })->values()->toArray();
    }

    // GET /user/invoices/gateway-accounts — active tenant gateway accounts
    // for the invoice-create/edit form's "Payment Gateways for this Invoice"
    // checkbox list. No secrets — just enough to render the list and know
    // which one(s) are each type's default.
    public function gatewayAccounts(): JsonResponse
    {
        if (!$this->canEither('canViewInvoices', 'canViewFinanceInvoices')
            && !$this->canEither('canCreateInvoices', 'canCreateFinanceInvoices')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $user = $this->user();
        $company = Company::find($user->company_id);

        $accounts = CompanyPaymentGateway::resolveActiveGateways($company)
            ->map(fn($g) => [
                'id'          => $g->id,
                'gateway_type'=> $g->gateway,
                'label'       => $g->label ?: (CompanyPaymentGateway::GATEWAYS[$g->gateway] ?? $g->gateway),
                'is_default'  => (bool) $g->is_default,
            ])
            ->values();

        // So the "New Invoice" form can prefill tax rate/due date/notes from
        // Settings > Invoice the same way the Admin side already reads them
        // off GET /admin/settings — a staff user has no other endpoint that
        // exposes these (Settings itself is Admin-only).
        $profile = $company?->invoicingProfile() ?? [];

        return ApiResponse::success([
            'accounts'                 => $accounts,
            'can_select_gateway'       => $this->can('canSelectInvoiceGateway'),
            'invoice_defaults'         => [
                'tax_rate'      => (float) ($profile['invoice_tax_rate'] ?? 0),
                'payment_terms' => (int) ($profile['invoice_payment_terms'] ?? 30),
                'notes'         => $profile['invoice_notes'] ?? '',
            ],
        ]);
    }

    // GET /user/invoices
    public function index(Request $request): JsonResponse
    {
        if (!$this->canEither('canViewInvoices', 'canViewFinanceInvoices')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $q = $this->visibleInvoices()
            ->with(['client:id,name,company_name,email', 'project:id,name'])
            ->latest();

        if ($request->filled('status'))    $q->where('status', $request->status);
        if ($request->filled('client_id')) $q->where('client_id', $request->client_id);
        if ($request->filled('from'))      $q->whereDate('created_at', '>=', $request->from);
        if ($request->filled('to'))        $q->whereDate('created_at', '<=', $request->to);
        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(fn($x) =>
                $x->where('invoice_number', 'like', "%{$s}%")
                  ->orWhereHas('client', fn($c) => $c->where('name', 'like', "%{$s}%"))
            );
        }

        return ApiResponse::success($q->get());
    }

    // Which brands this caller may invoice under, expressed the way
    // Brand::invoiceableFor() wants it: null = no assignment filter at all.
    //
    // The Admin role keeps the company's brand list (see
    // Api\User\BrandController::isBrandKeeper()), so they get EVERY active
    // brand here too — both in the dropdown and on save. Everyone else who
    // can raise invoices — Seller, Lead Manager, any user granted invoice
    // rights — is limited to the brands assigned to them.
    private function brandScopeUserId(): ?int
    {
        return $this->user()->role_type === 'admin' ? null : $this->user()->id;
    }

    // Company Invoice or Brand Invoice, validated. Returns
    // [invoice_type, brand_id, errorResponse|null].
    //
    // For everyone but the Admin role the rule is the strict one: the brand
    // must be ASSIGNED to this person. That is what makes "user kisi
    // unassigned brand ke liye invoice create ya edit na kar sake" true even
    // when the id is sent by hand rather than picked from the dropdown — and
    // it re-reads the assignment on every request, so a brand reassigned to
    // someone else stops working here immediately.
    private function resolveInvoiceBranding(array $data, int $companyId): array
    {
        $type = $data['invoice_type'] ?? 'company';

        if ($type !== 'brand') {
            return ['company', null, null];
        }

        if (empty($data['brand_id'])) {
            return ['company', null, ApiResponse::error('Select a brand for a Brand Invoice.', 422)];
        }

        $brand = Brand::invoiceableFor($companyId, $this->brandScopeUserId())->find($data['brand_id']);
        if (!$brand) {
            return ['company', null, ApiResponse::error(
                $this->brandScopeUserId() === null
                    ? 'That brand is not available for this company.'
                    : 'That brand is not assigned to you.',
                422
            )];
        }

        return ['brand', $brand->id, null];
    }

    // GET /user/invoices/brands — the Brand Invoice dropdown. Every active
    // brand of the company for the Admin role; only the assigned ones for
    // everybody else.
    // GET /user/invoices/lead-budget?lead_id=&invoice_id=
    // Staff counterpart to Api\Admin\InvoiceController::leadBudget() — same
    // shape and the same advisory-only meaning, scoped to the company this
    // caller is working under.
    public function leadBudget(Request $request): JsonResponse
    {
        if (!$this->canEither('canCreateInvoices', 'canCreateFinanceInvoices')
            && !$this->canEither('canEditInvoices', 'canUpdateFinanceInvoices')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $validated = $request->validate([
            'lead_id'    => ['required', 'integer'],
            'invoice_id' => ['nullable', 'integer'],
        ]);

        $companyId = $this->activeCompanyId();

        $lead = Lead::where('company_id', $companyId)->find($validated['lead_id']);
        if (!$lead) {
            return ApiResponse::error('Lead not found', 404);
        }

        // The policy belongs to the company, not to the staff member reading
        // it — per-company as of 2026-09-11 (it was keyed to the owning
        // Company Admin, and therefore shared across their companies, until
        // then).
        $settings = CompanyDealSettings::forCompany($companyId);

        return ApiResponse::success(
            LeadBudgetContext::for($lead, $validated['invoice_id'] ?? null) + [
                'payment_policy'        => $settings?->payment_policy ?: 'partial_allowed',
                'requires_full_payment' => (bool) $settings?->requiresFullPayment(),
            ]
        );
    }

    public function brands(): JsonResponse
    {
        if (!$this->canEither('canCreateInvoices', 'canCreateFinanceInvoices')
            && !$this->canEither('canEditInvoices', 'canUpdateFinanceInvoices')) {
            return ApiResponse::error('Permission denied', 403);
        }

        return ApiResponse::success(
            Brand::invoiceableFor($this->user()->company_id, $this->brandScopeUserId())->get()->map(fn (Brand $b) => [
                'id'       => $b->id,
                'name'     => $b->name,
                'email'    => $b->email,
                'logo_url' => PublicFile::url($b->logo_path),
            ])
        );
    }

    // POST /user/invoices
    public function store(Request $request): JsonResponse
    {
        if (!$this->canEither('canCreateInvoices', 'canCreateFinanceInvoices')) {
            return ApiResponse::error('You do not have permission to create invoices', 403);
        }

        $user      = $this->user();
        $companyId = $user->company_id;
        $company   = Company::find($companyId);

        if (!$company || CompanyPaymentGateway::resolveActiveGateways($company)->isEmpty()) {
            return ApiResponse::error('Please activate a payment gateway before creating an invoice.', 422);
        }

        // Settings > Invoice tab defaults — previously saved but never
        // actually applied to a new invoice; tax_rate/due_date/notes always
        // fell back to a hardcoded 0/null/null regardless of what was
        // configured here.
        $profile = $company->invoicingProfile();

        $data = $request->validate([
            'client_id'           => 'nullable|exists:clients,id',
            'lead_id'             => 'nullable|exists:leads,id',
            'project_id'          => 'nullable|exists:projects,id',
            'project_title'       => 'nullable|string|max:255',
            'project_reference'   => 'nullable|string|max:100',
            // "Without Project" at creation — see PaymentProjectStartService,
            // whose whole auto-create-a-project-on-payment automation this
            // opts the invoice out of. Defaults false, so every existing
            // caller that never sends it behaves exactly as before.
            'is_standalone'       => 'nullable|boolean',
            'due_date'            => 'nullable|date',
            // USD is the system's only supported currency now — locked here
            // the same as Api\Admin\InvoiceController::store().
            'currency'            => 'nullable|in:USD',
            'tax_rate'            => 'nullable|numeric|min:0|max:100',
            'discount_amount'     => 'nullable|numeric|min:0',
            'notes'               => 'nullable|string|max:2000',
            'customer_name'       => 'nullable|string|max:255',
            'customer_email'      => 'nullable|email|max:255',
            'customer_phone'      => ['nullable', 'string', 'max:50', new ValidPhoneNumber],
            'customer_address'    => 'nullable|string|max:500',
            'items'               => 'required|array|min:1',
            'items.*.description' => 'required|string|max:500',
            'items.*.quantity'    => 'required|numeric|min:0.01',
            'items.*.unit_price'  => 'required|numeric|min:0',
            'gateway_account_ids'   => 'nullable|array',
            'gateway_account_ids.*' => 'integer',
            'invoice_purpose'                  => 'nullable|string|max:255',
            'payment_type'                      => 'nullable|string|max:30',
            'required_payment_amount'           => 'nullable|numeric|min:0',
            'counts_toward_project_activation'  => 'nullable|boolean',
            // Company Invoice or Brand Invoice — see resolveInvoiceBranding().
            'invoice_type'                      => 'nullable|in:company,brand',
            'brand_id'                          => 'nullable|integer',
            // Optional — links this invoice to one of the client's
            // outstanding AI-suggested services (see App\Services\
            // ClientAiServiceBatchService), which flips it to 'taken' once
            // this invoice is paid/partially paid.
            'ai_service_batch_item_id'          => 'nullable|integer|exists:client_ai_service_batch_items,id',
        ]);

        [$invoiceType, $brandId, $brandError] = $this->resolveInvoiceBranding($data, $companyId);
        if ($brandError) {
            return $brandError;
        }

        if (!empty($data['client_id'])) {
            Client::where('company_id', $companyId)->findOrFail($data['client_id']);
        }

        $lead = null;
        if (!empty($data['lead_id'])) {
            $lead = Lead::where('company_id', $companyId)->findOrFail($data['lead_id']);
        }

        if (!empty($data['project_id'])) {
            Project::where('company_id', $companyId)->findOrFail($data['project_id']);
        }

        // Standalone wins over any project_id/project_title/project_reference
        // a tampered or stale request still sent — a standalone invoice must
        // never end up carrying project fields the opt-out is meant to erase.
        $isStandalone = !empty($data['is_standalone']);
        if ($isStandalone) {
            $data['project_id'] = null;
            $data['project_title'] = null;
            $data['project_reference'] = null;
        }

        if (!empty($data['ai_service_batch_item_id'])) {
            if (empty($data['client_id'])) {
                return ApiResponse::error('A linked suggested service requires a client.', 422);
            }
            $linkedItem = ClientAiServiceBatchItem::whereHas(
                'batch',
                fn ($q) => $q->where('client_id', $data['client_id'])
            )->find($data['ai_service_batch_item_id']);
            if (!$linkedItem || $linkedItem->status === 'taken') {
                return ApiResponse::error('Selected suggested service is no longer available.', 422);
            }
        }

        $taxRate  = (float) ($data['tax_rate']        ?? $profile['invoice_tax_rate'] ?? 0);
        $discount = (float) ($data['discount_amount'] ?? 0);

        // Payment Terms (days) — only fills a due_date the requester didn't
        // already set explicitly; issue date is "now", same reference point
        // the invoice itself is created at.
        $dueDate = $data['due_date'] ?? (
            !empty($profile['invoice_payment_terms'])
                ? now()->addDays((int) $profile['invoice_payment_terms'])->toDateString()
                : null
        );

        $subtotal = 0;
        $items    = $data['items'];
        foreach ($items as &$item) {
            $item['total'] = round((float) $item['quantity'] * (float) $item['unit_price'], 2);
            $subtotal += $item['total'];
        }
        unset($item);

        if ($subtotal <= 0) {
            return ApiResponse::error('Invoice amount must be greater than 0', 422);
        }

        $taxAmt = round($subtotal * $taxRate / 100, 2);

        $invoice = Invoice::create([
            'company_id'       => $companyId,
            'invoice_type'     => $invoiceType,
            'brand_id'         => $brandId,
            'client_id'        => $data['client_id']       ?? null,
            'lead_id'          => $lead?->id,
            'project_id'       => $data['project_id']       ?? null,
            'project_title'    => $data['project_title']    ?? null,
            'project_reference'=> $data['project_reference']?? null,
            'is_standalone'    => $isStandalone,
            'ai_service_batch_item_id' => $data['ai_service_batch_item_id'] ?? null,
            'created_by'       => $user->id,
            'invoice_number'   => $this->nextNumber($companyId),
            'subtotal'         => $subtotal,
            'tax_rate'         => $taxRate,
            'tax_amount'       => $taxAmt,
            'discount_amount'  => $discount,
            'total_amount'     => $subtotal + $taxAmt - $discount,
            'paid_amount'      => 0,
            // The currency Company Admin configured in Settings is
            // authoritative — never a bare 'USD' literal (see
            // Company::invoicingProfile()).
            'currency'         => $data['currency']        ?? $profile['currency'] ?? 'USD',
            'status'           => 'draft',
            'due_date'         => $dueDate,
            'notes'            => $data['notes']           ?? $profile['invoice_notes'] ?? null,
            'customer_name'    => $data['customer_name']   ?? null,
            'customer_email'   => $data['customer_email']  ?? null,
            'customer_phone'   => $data['customer_phone']  ?? null,
            'customer_address' => $data['customer_address']?? null,
            'invoice_purpose'                  => $data['invoice_purpose']         ?? null,
            'payment_type'                       => $data['payment_type']            ?? null,
            'required_payment_amount'            => $data['required_payment_amount'] ?? null,
            'counts_toward_project_activation'   => $data['counts_toward_project_activation'] ?? true,
        ]);

        foreach ($items as $i => $item) {
            InvoiceItem::create([
                'invoice_id'  => $invoice->id,
                'description' => $item['description'],
                'quantity'    => $item['quantity'],
                'unit_price'  => $item['unit_price'],
                'total'       => $item['total'],
                'sort_order'  => $i,
            ]);
        }

        // "New Project" mode (project_title set, no existing project_id) — no
        // Project row is raised yet. Per explicit request, a project must not
        // exist at all until the invoice is actually paid — see
        // PaymentProjectStartService::handle(), which creates it (already
        // 'active') the moment that happens.

        // Only a user holding canSelectInvoiceGateway may restrict/override
        // which gateway account(s) this invoice accepts — otherwise, per
        // spec, silently fall back to the company's default gateway(s) by
        // simply leaving the pivot empty (every gateway-resolving read path
        // already treats "no explicit selection" as "use the tenant's
        // per-type defaults").
        // Goes through resolveActiveGateways() rather than querying by
        // company_admin_id — the latter was tenant-wide and would pin this
        // company's invoice to another company's gateway account now that
        // accounts are per-company.
        if (!empty($data['gateway_account_ids']) && $this->can('canSelectInvoiceGateway')) {
            $company  = Company::find($companyId);
            $validIds = $company
                ? CompanyPaymentGateway::resolveActiveGateways($company)
                    ->whereIn('id', $data['gateway_account_ids'])
                    ->pluck('id')
                    ->values()
                    ->toArray()
                : [];
            $invoice->paymentGatewayAccounts()->sync($validIds);
        }

        if ($lead) {
            $lead->logActivity('note_added', "Invoice {$invoice->invoice_number} created for this lead", $user->name ?? 'User');
            \App\Services\DealEligibilityService::recomputeFulfillmentStatus($lead);
        }
        $this->auditLog($invoice, 'invoice_created', "Invoice {$invoice->invoice_number} created"
            . ($lead ? " from lead \"{$lead->name}\"" : ''));

        return ApiResponse::success(
            $invoice->load(['client:id,name,company_name,email,phone,address', 'items']),
            'Invoice created',
            201
        );
    }

    // POST /user/invoices/{id}/send-email
    public function sendEmail(Request $request, int $id): JsonResponse
    {
        if (!$this->can('canSendInvoices') && !$this->canEither('canCreateInvoices', 'canCreateFinanceInvoices')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $user    = $this->user();
        // Same visibility as index()/show() — a plain `created_by` filter
        // blocked a Lead Manager (or any user with canViewAllCompanyInvoices)
        // from sharing/emailing an invoice they can already see but didn't
        // personally create.
        $invoice = $this->visibleInvoices()->where('id', $id)->firstOrFail();

        $data = $request->validate([
            'email'       => 'required|email|max:255',
            'expiry_days' => 'nullable|integer|min:1|max:365',
        ], [
            'email.required' => 'Customer email is required to send invoice.',
            'email.email'    => 'Customer email is required to send invoice.',
        ]);

        if (!$invoice->payment_token || ($invoice->token_expires_at && $invoice->token_expires_at->isPast())) {
            $invoice->generatePublicToken($data['expiry_days'] ?? 30);
            $invoice->refresh();
        }

        $company     = Company::find($user->company_id);
        $paymentUrl  = FrontendUrl::to('pay/invoice/' . $invoice->payment_token);
        // Brand Invoice → the brand's name on the email, not the company's.
        $companyName = $invoice->brandingProfile()['name'];

        // Only flip the invoice to 'sent' AFTER the email genuinely goes out —
        // previously this happened before Mail::send(), so a failed send left
        // the invoice incorrectly marked 'sent' with nothing ever delivered.
        try {
            Mail::to($data['email'])->send(new InvoiceMail($invoice, $paymentUrl, $companyName));
        } catch (\Throwable $e) {
            Log::error('[sales-invoice] email send failed', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
            return ApiResponse::error('Invoice created, but email could not be sent. Please try sending again.', 422);
        }

        $wasFirstSend = $invoice->status === 'draft';
        $invoice->update(array_filter([
            'status'  => $wasFirstSend ? 'sent' : $invoice->status,
            'sent_at' => $wasFirstSend ? now() : $invoice->sent_at,
            'sent_by' => $user->id,
        ], fn ($v) => $v !== null));

        // Portal-enabled clients also get an in-portal notification, on top of
        // the email above — only on the first send, when the invoice actually
        // becomes visible in the portal. Lead-only and guest/external invoices
        // have no portal inbox, so for them the email is the whole delivery.
        if ($wasFirstSend) {
            InvoiceNotificationService::notifyClientInvoiceSent($invoice);
        }

        if ($invoice->lead_id) {
            Lead::find($invoice->lead_id)?->logActivity('note_added',
                "Invoice {$invoice->invoice_number} sent to {$data['email']}", $user->name ?? 'User');
        }
        $this->auditLog($invoice, 'invoice_sent', "Invoice {$invoice->invoice_number} sent to {$data['email']}");

        return ApiResponse::success([
            'payment_url' => $paymentUrl,
            'sent_to'     => $data['email'],
        ], 'Invoice sent successfully');
    }

    // POST /user/invoices/{id}/generate-link
    public function generateLink(Request $request, int $id): JsonResponse
    {
        if (!$this->can('canSendInvoices') && !$this->canEither('canCreateInvoices', 'canCreateFinanceInvoices')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $user    = $this->user();
        // Same visibility as index()/show() — see sendEmail() above.
        $invoice = $this->visibleInvoices()->where('id', $id)->firstOrFail();

        if ($invoice->status === 'cancelled') {
            return ApiResponse::error('Cannot share a cancelled invoice', 422);
        }

        $data = $request->validate([
            'expiry_days' => 'nullable|integer|min:1|max:365',
        ]);

        // Auto-mark draft as sent when sharing
        if ($invoice->status === 'draft') {
            $invoice->update(['status' => 'sent', 'sent_at' => now()]);
            // Now visible in the portal — same first-send-only rule as sendEmail().
            InvoiceNotificationService::notifyClientInvoiceSent($invoice);
        }

        $token = $invoice->generatePublicToken($data['expiry_days'] ?? null);

        return ApiResponse::success([
            'payment_token'    => $token,
            'token_expires_at' => $invoice->fresh()->token_expires_at?->toIso8601String(),
            'payment_url'      => FrontendUrl::to('pay/invoice/' . $token),
        ], 'Payment link generated');
    }

    /**
     * PUT /user/invoices/{id}
     *
     * The staff counterpart to Api\Admin\InvoiceController::update(), which
     * had no equivalent here at all — canEditInvoices has been grantable
     * since the Invoice module shipped and every role's defaults include it,
     * but no staff-side route ever consumed it, so the permission did
     * nothing and the Edit screen was Company Admin-only. Finance >
     * Invoices needs Update to work for a staff user, so this is that
     * endpoint rather than a Finance-private copy of it.
     *
     * Deliberately a mirror of the Admin method, not a variation: same
     * validation, same "paid or cancelled invoices are frozen" rule, same
     * totals recomputation, same items replacement, same gateway-selection
     * handling, same deal-eligibility recompute. The differences are the two
     * that have to differ — the row is fetched through visibleInvoices()
     * (company + ownership scope) instead of a company_id whitelist, and
     * gateway re-selection additionally requires canSelectInvoiceGateway,
     * exactly as store() already does for a staff caller.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        if (!$this->canEither('canEditInvoices', 'canUpdateFinanceInvoices')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $invoice = $this->visibleInvoices()->where('id', $id)->first();
        if (!$invoice) {
            return ApiResponse::error('Invoice not found', 404);
        }

        if (in_array($invoice->status, ['paid', 'cancelled'], true)) {
            return ApiResponse::error('Cannot edit a paid or cancelled invoice', 422);
        }

        $data = $request->validate([
            'due_date'            => 'nullable|date',
            // Same USD-only lock as store(): an override is accepted but
            // pinned, so a direct API call cannot relabel an existing
            // (possibly historical non-USD) invoice.
            'currency'            => 'nullable|in:USD',
            'tax_rate'            => 'nullable|numeric|min:0|max:100',
            'discount_amount'     => 'nullable|numeric|min:0',
            'notes'               => 'nullable|string|max:2000',
            'items'               => 'required|array|min:1',
            'items.*.description' => 'required|string|max:500',
            'items.*.quantity'    => 'required|numeric|min:0.01',
            'items.*.unit_price'  => 'required|numeric|min:0',
            'gateway_account_ids'   => 'nullable|array',
            'gateway_account_ids.*' => 'integer',
            'invoice_purpose'                  => 'nullable|string|max:255',
            'payment_type'                     => 'nullable|string|max:30',
            'required_payment_amount'          => 'nullable|numeric|min:0',
            'counts_toward_project_activation' => 'nullable|boolean',
            'invoice_type'                     => 'nullable|in:company,brand',
            'brand_id'                         => 'nullable|integer',
        ]);

        $invoiceType = $invoice->invoice_type;
        $brandId     = $invoice->brand_id;
        if ($request->has('invoice_type')) {
            // Re-reads the brand assignment on every save, so a brand
            // reassigned away from this user stops being selectable
            // immediately — the same rule store() applies.
            [$invoiceType, $brandId, $brandError] = $this->resolveInvoiceBranding($data, $invoice->company_id);
            if ($brandError) {
                return $brandError;
            }
        }

        $taxRate  = (float) ($data['tax_rate']        ?? $invoice->tax_rate);
        $discount = (float) ($data['discount_amount'] ?? $invoice->discount_amount);

        $subtotal = 0;
        $items    = $data['items'];
        foreach ($items as &$item) {
            $item['total'] = round((float) $item['quantity'] * (float) $item['unit_price'], 2);
            $subtotal += $item['total'];
        }
        unset($item);

        if ($subtotal <= 0) {
            return ApiResponse::error('Invoice amount must be greater than 0', 422);
        }

        $taxAmt = round($subtotal * $taxRate / 100, 2);

        $invoice->update([
            'invoice_type'    => $invoiceType,
            'brand_id'        => $brandId,
            'subtotal'        => $subtotal,
            'tax_rate'        => $taxRate,
            'tax_amount'      => $taxAmt,
            'discount_amount' => $discount,
            'total_amount'    => $subtotal + $taxAmt - $discount,
            'due_date'        => $data['due_date'] ?? $invoice->due_date,
            'currency'        => $data['currency'] ?? $invoice->currency,
            'notes'           => $data['notes']    ?? $invoice->notes,
            'invoice_purpose'                  => $data['invoice_purpose']                  ?? $invoice->invoice_purpose,
            'payment_type'                     => $data['payment_type']                     ?? $invoice->payment_type,
            'required_payment_amount'          => $data['required_payment_amount']          ?? $invoice->required_payment_amount,
            'counts_toward_project_activation' => $data['counts_toward_project_activation'] ?? $invoice->counts_toward_project_activation,
        ]);

        $invoice->items()->delete();
        foreach ($items as $i => $item) {
            InvoiceItem::create([
                'invoice_id'  => $invoice->id,
                'description' => $item['description'],
                'quantity'    => $item['quantity'],
                'unit_price'  => $item['unit_price'],
                'total'       => $item['total'],
                'sort_order'  => $i,
            ]);
        }

        // Only touched when the request actually carried the field — an
        // update that omits it leaves the existing selection alone rather
        // than silently wiping it (same rule as the Admin method). The extra
        // canSelectInvoiceGateway check is the staff-side rule store()
        // already applies: without it, the company's per-type defaults stand.
        if ($request->has('gateway_account_ids') && $this->can('canSelectInvoiceGateway')) {
            $company  = Company::find($invoice->company_id);
            $validIds = $company
                ? CompanyPaymentGateway::resolveActiveGateways($company)
                    ->whereIn('id', $data['gateway_account_ids'] ?? [])
                    ->pluck('id')->values()->toArray()
                : [];
            $invoice->paymentGatewayAccounts()->sync($validIds);
        }

        if ($invoice->lead_id) {
            \App\Services\DealEligibilityService::recomputeFulfillmentStatus($invoice->lead);
        }

        $this->auditLog($invoice, 'invoice_updated', "Invoice {$invoice->invoice_number} updated");

        return ApiResponse::success(
            $invoice->fresh()->load(['client:id,name,company_name,email,phone,address', 'items', 'payments']),
            'Invoice updated'
        );
    }
}
