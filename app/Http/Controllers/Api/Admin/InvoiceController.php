<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Admin\Concerns\ScopesToActiveCompany;
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
use App\Models\Project;
use App\Models\Task;
use App\Rules\ValidPhoneNumber;
use App\Services\InvoiceNotificationService;
use App\Services\InvoiceStatsService;
use App\Services\LeadBudgetContext;
use App\Support\FrontendUrl;
use App\Support\PublicFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class InvoiceController extends Controller
{
    use ScopesToActiveCompany;

    private function admin() { return auth('admin')->user(); }

    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    private function nextNumber(int $companyId): string
    {
        $year   = now()->year;
        $prefix = \App\Models\Company::find($companyId)?->invoicingProfile()['invoice_prefix'] ?? 'INV';

        // Find the highest sequence used globally this year for this prefix
        $last = Invoice::whereYear('created_at', $year)
            ->where('invoice_number', 'like', "{$prefix}-{$year}-%")
            ->latest('id')
            ->value('invoice_number');

        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        // Loop until we land on a number not yet taken (handles gaps and race conditions)
        do {
            $number = sprintf('%s-%d-%04d', $prefix, $year, $seq++);
        } while (Invoice::where('invoice_number', $number)->exists());

        return $number;
    }

    // Restricts submitted gateway_account_ids to ones that actually belong to
    // this invoice's own COMPANY and are currently active — silently drops
    // anything else (a stale/removed account id in an old request shouldn't
    // block saving the rest of the invoice) rather than erroring.
    //
    // Goes through resolveActiveGateways() rather than querying by
    // company_admin_id, which is what made this tenant-wide: it would happily
    // pin Company A's invoice to a gateway account belonging to Company B.
    private function validGatewayAccountIds(int $companyId, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $company = Company::find($companyId);
        if (!$company) {
            return [];
        }

        return CompanyPaymentGateway::resolveActiveGateways($company)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->values()
            ->toArray();
    }

    private function hasActivePaymentGateway(int $companyId): bool
    {
        $company = Company::find($companyId);

        return $company
            ? CompanyPaymentGateway::resolveActiveGateways($company)->isNotEmpty()
            : false;
    }

    private function computeTotals(array &$items, float $taxRate, float $discount): array
    {
        $subtotal = 0;
        foreach ($items as &$item) {
            $item['total'] = round((float) $item['quantity'] * (float) $item['unit_price'], 2);
            $subtotal += $item['total'];
        }
        unset($item);
        $taxAmt = round($subtotal * $taxRate / 100, 2);
        return [
            'subtotal'        => $subtotal,
            'tax_amount'      => $taxAmt,
            'total_amount'    => $subtotal + $taxAmt - $discount,
        ];
    }

    // GET /admin/invoices
    public function index(Request $request): JsonResponse
    {
        // Company-Wise Dashboard Filtering — scoped to the active company.
        $q = Invoice::whereIn('company_id', $this->activeCompanyIds())
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

    // Company Invoice or Brand Invoice, validated. Returns
    // [invoice_type, brand_id, errorResponse|null] — the caller returns the
    // error as-is when there is one.
    //
    // A Company Admin may raise a brand invoice under ANY active brand of the
    // company (they own the brand list and do the assigning); the staff side
    // narrows this to brands assigned to that person — see
    // Brand::invoiceableFor() and Api\User\InvoiceController.
    private function resolveInvoiceBranding(array $data, int $companyId): array
    {
        $type = $data['invoice_type'] ?? 'company';

        if ($type !== 'brand') {
            return ['company', null, null];
        }

        if (empty($data['brand_id'])) {
            return ['company', null, ApiResponse::error('Select a brand for a Brand Invoice.', 422)];
        }

        $brand = Brand::invoiceableFor($companyId, null)->find($data['brand_id']);
        if (!$brand) {
            return ['company', null, ApiResponse::error('That brand is not available for this company.', 422)];
        }

        return ['brand', $brand->id, null];
    }

    // The issuer block every invoice screen renders: name, logo and — for a
    // Brand Invoice — the brand's own contact details. Shared shape with
    // Api\User\InvoiceController and the public/client endpoints.
    private function brandingPayload(Invoice $invoice): array
    {
        $profile = $invoice->brandingProfile();

        return [
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
    }

    // GET /admin/invoices/brands?company_id= — the Brand Invoice dropdown.
    // POST /admin/invoices creates below.
    // Every active brand of the company: the admin assigns brands, so nothing
    // is withheld from them.
    // GET /admin/invoices/lead-budget?lead_id=&invoice_id=
    //
    // What the invoice form needs before it saves: how much of this lead's
    // budget its other invoices have already used, and whether this tenant
    // accepts part payments. Both are advisory here — the budget is never a
    // limit (milestones are normal, and tax pushes a total past the estimate
    // legitimately — see Services\LeadBudgetContext), and the policy is
    // enforced where a client actually pays.
    public function leadBudget(Request $request): JsonResponse
    {
        $companyIds = $this->companyIds();

        $validated = $request->validate([
            'lead_id'    => ['required', 'integer'],
            'invoice_id' => ['nullable', 'integer'],
        ]);

        $lead = Lead::whereIn('company_id', $companyIds)->find($validated['lead_id']);
        if (!$lead) {
            return ApiResponse::error('Lead not found', 404);
        }

        // Per-company as of 2026-09-11 — read the policy off the lead's OWN
        // company, not the tenant account, so an admin owning two companies
        // with different payment policies gets the right one.
        $settings = CompanyDealSettings::forCompany($lead->company_id);

        return ApiResponse::success(
            LeadBudgetContext::for($lead, $validated['invoice_id'] ?? null) + [
                'payment_policy'        => $settings->payment_policy ?: 'partial_allowed',
                'requires_full_payment' => $settings->requiresFullPayment(),
            ]
        );
    }

    // GET /admin/invoices/brands?company_id=
    //
    // An explicit company_id narrows to that company. WITHOUT one it follows
    // the top bar's own filter via activeCompanyIds(), which on "All
    // Companies" is every company this admin owns — so picking Brand Invoice
    // there offers every brand rather than an empty list.
    //
    // That is why each row carries its company: a brand belongs to exactly
    // one company, so the brand the admin picks IS the answer to "which
    // company is this invoice from", and the form reads company_id straight
    // off it instead of asking twice. store() re-derives the same thing
    // server-side, so a tampered pairing can't take effect.
    public function brands(Request $request): JsonResponse
    {
        $companyIds = $this->operationalCompanyIds();

        $validated = $request->validate([
            'company_id' => ['nullable', 'integer', 'in:' . implode(',', $companyIds)],
        ]);

        $scope = !empty($validated['company_id'])
            ? [(int) $validated['company_id']]
            : ($this->activeCompanyIds() ?: $companyIds);

        if (!$scope) {
            return ApiResponse::success([]);
        }

        // Loaded, not queried per row — this list is short but it is rendered
        // on every open of the invoice form.
        $companyNames = Company::whereIn('id', $scope)->pluck('name', 'id');

        return ApiResponse::success(
            Brand::invoiceableFor($scope, null)->get()->map(fn (Brand $b) => [
                'id'           => $b->id,
                'name'         => $b->name,
                'email'        => $b->email,
                'logo_url'     => PublicFile::url($b->logo_path),
                'company_id'   => $b->company_id,
                'company_name' => $companyNames[$b->company_id] ?? null,
            ])
        );
    }

    public function store(Request $request): JsonResponse
    {
        $ids = $this->companyIds();

        $data = $request->validate([
            'company_id'          => 'required|integer|in:' . implode(',', $ids),
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
            'send_now'            => 'nullable|boolean',
            'due_date'            => 'nullable|date',
            // USD is the system's only supported currency now — an override
            // is still accepted here (never silently rejected) but locked to
            // 'USD', so a direct API call can't slip a different currency in
            // even though the frontend no longer offers the picker.
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
            // Deal-facing fields — what this invoice is FOR, and whether it
            // counts toward the Deal's kickoff-payment requirement.
            'invoice_purpose'                  => 'nullable|string|max:255',
            'payment_type'                     => 'nullable|string|max:30',
            'required_payment_amount'          => 'nullable|numeric|min:0',
            'counts_toward_project_activation' => 'nullable|boolean',
            // Whose name this invoice goes out under — the company's own, or
            // one of its Brands. Omitted means 'company', which is what every
            // caller predating Brands sends.
            'invoice_type'                     => 'nullable|in:company,brand',
            'brand_id'                         => 'nullable|integer',
            // Optional — links this invoice to one of the client's
            // outstanding AI-suggested services (see App\Services\
            // ClientAiServiceBatchService), which flips it to 'taken' once
            // this invoice is paid/partially paid. Existence alone isn't
            // enough (see the client/status check below).
            'ai_service_batch_item_id'         => 'nullable|integer|exists:client_ai_service_batch_items,id',
        ]);

        if (!$this->hasActivePaymentGateway((int) $data['company_id'])) {
            return ApiResponse::error('Please activate a payment gateway before creating an invoice.', 422);
        }

        [$invoiceType, $brandId, $brandError] = $this->resolveInvoiceBranding($data, (int) $data['company_id']);
        if ($brandError) {
            return $brandError;
        }

        // Ensure client belongs to the company (only when a client is specified)
        if (!empty($data['client_id'])) {
            Client::where('company_id', $data['company_id'])->findOrFail($data['client_id']);
        }
        $lead = null;
        if (!empty($data['lead_id'])) {
            $lead = Lead::where('company_id', $data['company_id'])->findOrFail($data['lead_id']);
        }

        if (!empty($data['project_id'])) {
            Project::where('company_id', $data['company_id'])->findOrFail($data['project_id']);
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

        // Settings > Invoice tab defaults — previously saved but never
        // actually applied to a new invoice; tax_rate/due_date/notes always
        // fell back to a hardcoded 0/null/null regardless of what was
        // configured here.
        $profile = Company::find($data['company_id'])?->invoicingProfile() ?? [];

        $taxRate  = (float) ($data['tax_rate']        ?? $profile['invoice_tax_rate'] ?? 0);
        $discount = (float) ($data['discount_amount'] ?? 0);
        $totals   = $this->computeTotals($data['items'], $taxRate, $discount);

        if ($totals['subtotal'] <= 0) {
            return ApiResponse::error('Invoice amount must be greater than 0', 422);
        }

        // Payment Terms (days) — only fills a due_date the requester didn't
        // already set explicitly; issue date is "now", same reference point
        // the invoice itself is created at.
        $dueDate = $data['due_date'] ?? (
            !empty($profile['invoice_payment_terms'])
                ? now()->addDays((int) $profile['invoice_payment_terms'])->toDateString()
                : null
        );

        $sendNow = !empty($data['send_now']) && !empty($data['client_id']);

        $invoice = Invoice::create([
            'company_id'      => $data['company_id'],
            'invoice_type'    => $invoiceType,
            'brand_id'        => $brandId,
            'client_id'       => $data['client_id'] ?? null,
            'lead_id'         => $data['lead_id'] ?? null,
            'project_id'         => $data['project_id']         ?? null,
            'project_title'      => $data['project_title']      ?? null,
            'project_reference'  => $data['project_reference']  ?? null,
            'is_standalone'      => $isStandalone,
            'ai_service_batch_item_id' => $data['ai_service_batch_item_id'] ?? null,
            'invoice_number'  => $this->nextNumber($data['company_id']),
            'subtotal'        => $totals['subtotal'],
            'tax_rate'        => $taxRate,
            'tax_amount'      => $totals['tax_amount'],
            'discount_amount' => $discount,
            'total_amount'    => $totals['total_amount'],
            'paid_amount'     => 0,
            // This SPECIFIC company's own currency — never the admin's
            // other companies', never a bare 'USD' literal. An admin who
            // owns e.g. a PKR company and a USD company must get each
            // invoiced in its own currency (see Company::invoicingProfile(),
            // where this is resolved the same way).
            'currency'        => $data['currency'] ?? $profile['currency'] ?? 'USD',
            'status'          => $sendNow ? 'sent' : 'draft',
            'sent_at'         => $sendNow ? now() : null,
            'due_date'        => $dueDate,
            'notes'           => $data['notes']           ?? $profile['invoice_notes'] ?? null,
            'customer_name'   => $data['customer_name']   ?? null,
            'customer_email'  => $data['customer_email']  ?? null,
            'customer_phone'  => $data['customer_phone']  ?? null,
            'customer_address'=> $data['customer_address']?? null,
            'invoice_purpose'                  => $data['invoice_purpose']          ?? null,
            'payment_type'                      => $data['payment_type']             ?? null,
            'required_payment_amount'           => $data['required_payment_amount']  ?? null,
            'counts_toward_project_activation'  => $data['counts_toward_project_activation'] ?? true,
            // The sub-user side writes created_by; Admin isn't a `users` row,
            // so it records itself here instead. Both are what
            // PaymentProjectStartService hands the project it auto-creates.
            'created_by_admin_id'               => $this->admin()->id,
        ]);

        foreach ($data['items'] as $i => $item) {
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

        $invoice->paymentGatewayAccounts()->sync(
            $this->validGatewayAccountIds($data['company_id'], $data['gateway_account_ids'] ?? [])
        );

        // Created straight into 'sent' (send_now) — so it is already visible in
        // the portal and this is its first send. A draft created here notifies
        // later instead, when send()/sendEmail() flips it.
        if ($sendNow) {
            InvoiceNotificationService::notifyClientInvoiceSent($invoice);
        }

        if ($lead) {
            \App\Services\DealEligibilityService::recomputeFulfillmentStatus($lead);
        }

        return ApiResponse::success(
            $invoice->load(['client:id,name,company_name,email,phone,address', 'items']),
            'Invoice created',
            201
        );
    }

    // GET /admin/invoices/{invoice}
    public function show(Invoice $invoice): JsonResponse
    {
        if (!in_array($invoice->company_id, $this->companyIds())) {
            return ApiResponse::error('Not found', 404);
        }

        // company: named on the Edit screen so an admin working across "All
        // Companies" can see whose invoice they are editing. Only id+name —
        // the letterhead identity is resolved separately in
        // brandingPayload() below, which for a Brand Invoice is the brand's
        // name, not the company's.
        $invoice->load(['client', 'items', 'payments', 'lead:id,deal_reference,proposed_project_title,fulfillment_status', 'brand', 'company:id,name']);
        $data = $invoice->toArray();
        $data['gateway_account_ids'] = $invoice->paymentGatewayAccounts()->pluck('company_payment_gateways.id')->toArray();
        $data['project_history'] = $this->invoiceProjectHistory($invoice);
        // Whose letterhead this invoice carries — the detail screen and the
        // edit form both read this rather than guessing from brand_id.
        $data['branding'] = $this->brandingPayload($invoice);
        // So the Record Payment form can enforce/reflect the same "Full
        // Payment Only" policy PaymentController::store() enforces server-side,
        // instead of only finding out from a 422 after submitting.
        $data['requires_full_payment'] = CompanyDealSettings::forInvoice($invoice)?->requiresFullPayment() ?? false;

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

    // PUT /admin/invoices/{invoice}
    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        if (!in_array($invoice->company_id, $this->companyIds())) {
            return ApiResponse::error('Not found', 404);
        }
        if (in_array($invoice->status, ['paid', 'cancelled'])) {
            return ApiResponse::error('Cannot edit a paid or cancelled invoice', 422);
        }

        $data = $request->validate([
            'due_date'            => 'nullable|date',
            // Same USD-only lock as store() — omitted entirely by the
            // frontend now, so this just guards against a direct API call
            // relabeling an existing (possibly historical non-USD) invoice.
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
            'payment_type'                      => 'nullable|string|max:30',
            'required_payment_amount'           => 'nullable|numeric|min:0',
            'counts_toward_project_activation'  => 'nullable|boolean',
            // Editing may switch an invoice between Company and Brand, and
            // between brands — same validation as store(). Omitted leaves it
            // exactly as it was.
            'invoice_type'                      => 'nullable|in:company,brand',
            'brand_id'                          => 'nullable|integer',
        ]);

        $invoiceType = $invoice->invoice_type;
        $brandId     = $invoice->brand_id;
        if ($request->has('invoice_type')) {
            [$invoiceType, $brandId, $brandError] = $this->resolveInvoiceBranding($data, $invoice->company_id);
            if ($brandError) {
                return $brandError;
            }
        }

        $taxRate  = (float) ($data['tax_rate']        ?? $invoice->tax_rate);
        $discount = (float) ($data['discount_amount'] ?? $invoice->discount_amount);
        $totals   = $this->computeTotals($data['items'], $taxRate, $discount);

        if ($totals['subtotal'] <= 0) {
            return ApiResponse::error('Invoice amount must be greater than 0', 422);
        }

        $invoice->update([
            'invoice_type'    => $invoiceType,
            'brand_id'        => $brandId,
            'subtotal'        => $totals['subtotal'],
            'tax_rate'        => $taxRate,
            'tax_amount'      => $totals['tax_amount'],
            'discount_amount' => $discount,
            'total_amount'    => $totals['total_amount'],
            'due_date'        => $data['due_date'] ?? $invoice->due_date,
            'currency'        => $data['currency'] ?? $invoice->currency,
            'notes'           => $data['notes']    ?? $invoice->notes,
            'invoice_purpose'                  => $data['invoice_purpose']                  ?? $invoice->invoice_purpose,
            'payment_type'                      => $data['payment_type']                     ?? $invoice->payment_type,
            'required_payment_amount'           => $data['required_payment_amount']          ?? $invoice->required_payment_amount,
            'counts_toward_project_activation'  => $data['counts_toward_project_activation']  ?? $invoice->counts_toward_project_activation,
        ]);

        $invoice->items()->delete();
        foreach ($data['items'] as $i => $item) {
            InvoiceItem::create([
                'invoice_id'  => $invoice->id,
                'description' => $item['description'],
                'quantity'    => $item['quantity'],
                'unit_price'  => $item['unit_price'],
                'total'       => $item['total'],
                'sort_order'  => $i,
            ]);
        }

        // Only touch the gateway selection if the request actually included
        // the field — an update that omits it (e.g. an older client) should
        // leave the invoice's existing selection alone, not silently wipe it.
        if ($request->has('gateway_account_ids')) {
            $invoice->paymentGatewayAccounts()->sync(
                $this->validGatewayAccountIds($invoice->company_id, $data['gateway_account_ids'] ?? [])
            );
        }

        if ($invoice->lead_id) {
            \App\Services\DealEligibilityService::recomputeFulfillmentStatus($invoice->lead);
        }

        return ApiResponse::success(
            $invoice->load(['client', 'items', 'payments']),
            'Invoice updated'
        );
    }

    // PATCH /admin/invoices/{invoice}/send
    public function send(Invoice $invoice): JsonResponse
    {
        if (!in_array($invoice->company_id, $this->companyIds())) {
            return ApiResponse::error('Not found', 404);
        }
        if ($invoice->status !== 'draft') {
            return ApiResponse::error('Only draft invoices can be sent', 422);
        }

        $invoice->update(['status' => 'sent', 'sent_at' => now()]);
        // Draft-only guard above means this is always the first send.
        InvoiceNotificationService::notifyClientInvoiceSent($invoice);

        return ApiResponse::success(['status' => 'sent'], 'Invoice marked as sent');
    }

    // PATCH /admin/invoices/{invoice}/cancel
    public function cancel(Invoice $invoice): JsonResponse
    {
        if (!in_array($invoice->company_id, $this->companyIds())) {
            return ApiResponse::error('Not found', 404);
        }
        if (in_array($invoice->status, ['paid', 'cancelled'])) {
            return ApiResponse::error('Cannot cancel a ' . $invoice->status . ' invoice', 422);
        }

        $invoice->update(['status' => 'cancelled']);
        return ApiResponse::success(['status' => 'cancelled'], 'Invoice cancelled');
    }

    // DELETE /admin/invoices/{invoice} — a true delete, distinct from cancel()
    // above. Only ever a draft still sitting on this side: nobody has been
    // emailed a payment link, no payment can exist against it, and (per
    // PaymentProjectStartService) no Project could have been auto-created
    // from it yet — so removing it has zero downstream effects to clean up.
    // Anything past draft (sent/partially_paid/paid/overdue) must be
    // cancelled instead, which preserves the record for the audit trail.
    public function destroy(Invoice $invoice): JsonResponse
    {
        if (!in_array($invoice->company_id, $this->companyIds())) {
            return ApiResponse::error('Not found', 404);
        }
        if ($invoice->status !== 'draft') {
            return ApiResponse::error('Only a draft invoice can be deleted — cancel it instead.', 422);
        }

        $invoice->items()->delete();
        $invoice->delete();

        return ApiResponse::success(null, 'Invoice deleted');
    }

    // POST /admin/invoices/{invoice}/generate-link
    public function generateLink(Request $request, Invoice $invoice): JsonResponse
    {
        if (!in_array($invoice->company_id, $this->companyIds())) {
            return ApiResponse::error('Not found', 404);
        }
        if ($invoice->status === 'cancelled') {
            return ApiResponse::error('Cannot share a cancelled invoice', 422);
        }

        $data = $request->validate([
            'expiry_days'      => 'nullable|integer|min:1|max:365',
            'customer_name'    => 'nullable|string|max:255',
            'customer_email'   => 'nullable|email|max:255',
            'customer_phone'   => ['nullable', 'string', 'max:50', new ValidPhoneNumber],
            'customer_address' => 'nullable|string|max:500',
        ]);

        // Update customer details if provided
        $customerFields = array_filter([
            'customer_name'    => $data['customer_name']    ?? null,
            'customer_email'   => $data['customer_email']   ?? null,
            'customer_phone'   => $data['customer_phone']   ?? null,
            'customer_address' => $data['customer_address'] ?? null,
        ], fn($v) => $v !== null);

        if ($customerFields) {
            $invoice->update($customerFields);
        }

        // Auto-mark draft as sent when sharing — same convention as
        // Api\User\InvoiceController::generateLink(). Previously this
        // hard-rejected a draft invoice instead, which meant "Create & Copy
        // Link" (the frontend's shared button for both guards) silently
        // never sent/notified the client when Company Admin used it, even
        // though the identical action worked for a Seller.
        if ($invoice->status === 'draft') {
            $invoice->update(['status' => 'sent', 'sent_at' => now()]);
            InvoiceNotificationService::notifyClientInvoiceSent($invoice);
        }

        $token = $invoice->generatePublicToken($data['expiry_days'] ?? null);

        return ApiResponse::success([
            'payment_token'    => $token,
            'token_expires_at' => $invoice->fresh()->token_expires_at?->toIso8601String(),
            'payment_url'      => FrontendUrl::to('pay/invoice/' . $token),
        ], 'Payment link generated');
    }

    // DELETE /admin/invoices/{invoice}/generate-link
    public function revokeLink(Invoice $invoice): JsonResponse
    {
        if (!in_array($invoice->company_id, $this->companyIds())) {
            return ApiResponse::error('Not found', 404);
        }

        $invoice->revokePublicToken();

        return ApiResponse::success(null, 'Payment link revoked');
    }

    // GET /admin/clients/{client}/invoices
    public function forClient(Client $client): JsonResponse
    {
        if (!in_array($client->company_id, $this->companyIds())) {
            return ApiResponse::error('Not found', 404);
        }

        $invoices = Invoice::where('client_id', $client->id)
            ->with(['items', 'payments'])
            ->latest()
            ->get();

        return ApiResponse::success([
            'invoices' => $invoices,
            'stats'    => InvoiceStatsService::summarize($invoices),
        ]);
    }

    // POST /admin/invoices/{invoice}/send-email
    public function sendEmail(Request $request, Invoice $invoice): JsonResponse
    {
        if (!in_array($invoice->company_id, $this->companyIds())) {
            return ApiResponse::error('Not found', 404);
        }

        $data = $request->validate([
            'email'       => 'required|email|max:255',
            'expiry_days' => 'nullable|integer|min:1|max:365',
        ], [
            'email.required' => 'Customer email is required to send invoice.',
            'email.email'    => 'Customer email is required to send invoice.',
        ]);

        // Generate or reuse public payment link
        if (!$invoice->payment_token || ($invoice->token_expires_at && $invoice->token_expires_at->isPast())) {
            $invoice->generatePublicToken($data['expiry_days'] ?? 30);
            $invoice->refresh();
        }

        $paymentUrl  = FrontendUrl::to('pay/invoice/' . $invoice->payment_token);
        // Brand Invoice → the brand's name on the email, not the company's.
        $companyName = $invoice->brandingProfile()['name'];

        // Only flip to 'sent' AFTER the email genuinely goes out — previously
        // this happened before Mail::send(), so a failed send left the
        // invoice incorrectly marked 'sent' with nothing ever delivered.
        try {
            Mail::to($data['email'])->send(new InvoiceMail($invoice, $paymentUrl, $companyName));
        } catch (\Throwable $e) {
            Log::error('[admin-invoice] email send failed', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
            return ApiResponse::error('Invoice created, but email could not be sent. Please try sending again.', 422);
        }

        // Portal-enabled clients also get an in-portal notification on top of
        // the email above — only on this first send, when the invoice actually
        // becomes visible in the portal. Lead-only and guest/external invoices
        // have no portal inbox, so for them the email is the whole delivery.
        if ($invoice->status === 'draft') {
            $invoice->update(['status' => 'sent', 'sent_at' => now()]);
            InvoiceNotificationService::notifyClientInvoiceSent($invoice);
        }

        if ($invoice->lead_id) {
            Lead::find($invoice->lead_id)?->logActivity('note_added',
                "Invoice {$invoice->invoice_number} sent to {$data['email']}", $this->admin()->name ?? 'Admin');
        }

        return ApiResponse::success([
            'payment_url' => $paymentUrl,
            'sent_to'     => $data['email'],
        ], 'Invoice sent successfully');
    }
}
