<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\CompanyDealSettings;
use App\Models\CompanyPaymentGateway;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\ProjectDeliverySubmission;
use App\Models\Task;
use App\Services\AttachmentStorageService;
use App\Services\CurrencyConversionService;
use App\Services\InvoiceGatewayChargeService;
use App\Services\InvoicePaymentService;
use App\Services\PaymentGateways\GatewayCurrencySupport;
use App\Services\PaymentGateways\PaymentGatewayManager;
use App\Support\FrontendUrl;
use App\Support\PublicFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicInvoiceController extends Controller
{
    // GET /public/invoices/{token}
    public function show(Request $request, string $token): JsonResponse
    {
        $invoice = Invoice::where('payment_token', $token)
            ->with(['company', 'items', 'client', 'project', 'lead:id'])
            ->first();

        if (!$invoice) {
            return ApiResponse::error('Invalid payment link', 404);
        }

        if ($invoice->token_expires_at && $invoice->token_expires_at->isPast()) {
            return ApiResponse::error('Payment link has expired', 410);
        }

        if (in_array($invoice->status, ['cancelled'])) {
            return ApiResponse::error('This invoice is no longer active', 422);
        }

        // Log the public link view
        InvoicePaymentService::logView($invoice, $request->ip());

        $hasPendingPayment = $invoice->payments()->where('status', 'pending')->exists();

        $company = $invoice->company;
        // Whose letterhead this invoice carries — the company's own, or the
        // Brand it was raised under. Everything downstream (name, logo,
        // contact block) reads from this one resolution, so the share link
        // and payment page can never disagree with the emailed copy.
        $profile = $invoice->brandingProfile();

        // A suspended company must not accept NEW payments through this
        // public link — but the invoice itself (amount, status, delivery,
        // history) still needs to render normally; nothing here says the
        // link should show a hard error or expose why payment is off. Same
        // no-new-gateways shape the page already renders when a tenant's
        // configured gateway accounts are all inactive (see
        // resolveInvoiceGateways() below), so no frontend change is needed —
        // the pay page already handles an empty gateway list with a message.
        if ($company && !$company->isOperational()) {
            $gateways = [];
            $gatewayUnavailableMessage = 'Payments for this account are temporarily unavailable. Please contact the business directly.';
        } else {
            [$gateways, $gatewayUnavailableMessage] = $this->resolveInvoiceGateways($invoice);
        }

        // Add bank transfer if the tenant has bank details on file — not a
        // real gateway account, so it carries no id, only the same
        // {id,type,label} shape as the real entries above for the frontend
        // to render uniformly.
        $hasBankDetails = $profile['bank_name'] || $profile['bank_account_number'];
        if ($hasBankDetails && $company?->isOperational() !== false) {
            $gateways[] = ['id' => null, 'type' => 'bank_transfer', 'label' => 'Bank Transfer', 'supports_invoice_currency' => true, 'conversion_preview' => null];
        }

        $bankDetails = null;
        if ($hasBankDetails) {
            $bankDetails = array_filter([
                'bank_name'      => $profile['bank_name'],
                'account_name'   => $profile['bank_account_name'],
                'account_number' => $profile['bank_account_number'],
                'iban'           => $profile['bank_iban'],
                'swift'          => $profile['bank_swift'],
            ]);
        }

        return ApiResponse::success([
            'invoice_number'   => $invoice->invoice_number,
            // Still named company_* for the frontend's sake — for a Brand
            // Invoice these carry the brand's name and logo instead (see
            // Invoice::brandingProfile()); 'invoice_type'/'brand' below say
            // which, so the page can show the brand's own contact block.
            //
            // company_logo goes out as a URL, not the raw storage path it
            // used to be: this response is read by a public page that has no
            // session and no way to resolve a path, so the <img> could never
            // have loaded. PublicFile::url() is absolute (see that class), so
            // it works from the Next.js origin too.
            'company_name'     => $profile['name'],
            'company_logo'     => PublicFile::url($profile['logo_path'] ?? null),
            'invoice_type'     => $profile['invoice_type'] ?? 'company',
            // The full "Billed From" identity, so the share link shows who
            // issued this the same way the emailed copy and the client portal
            // do rather than a bare name.
            'issuer'           => [
                'name'    => $profile['name'] ?? null,
                'email'   => $profile['email'] ?? null,
                'phone'   => $profile['phone'] ?? null,
                'website' => $profile['website'] ?? null,
                'address' => $profile['address'] ?? null,
                'country' => $profile['country'] ?? null,
            ],
            'brand'            => $profile['brand'] ?? null,
            // customer_* columns are only ever populated for a guest/lead
            // invoice (frontend/app/invoices/new/page.tsx sends just
            // client_id, no customer_* fields, when a real Client is
            // selected) — falls back to the linked Client's own record here,
            // mirroring how the admin/user invoice detail page and
            // InvoiceMail already resolve the same "Billed To" identity.
            'customer_name'    => $invoice->customer_name ?? $invoice->client?->name,
            'customer_email'   => $invoice->customer_email ?? $invoice->client?->email,
            'customer_phone'   => $invoice->customer_phone ?? $invoice->client?->phone,
            'customer_address' => $invoice->customer_address ?? $invoice->client?->address,
            'subtotal'         => (float) $invoice->subtotal,
            'tax_rate'         => (float) $invoice->tax_rate,
            'tax_amount'       => (float) $invoice->tax_amount,
            'discount_amount'  => (float) $invoice->discount_amount,
            'total_amount'     => (float) $invoice->total_amount,
            'paid_amount'      => (float) $invoice->paid_amount,
            'currency'         => $invoice->currency,
            'status'           => $invoice->status,
            // Paid / remaining / percentage / payment_status in the one shape
            // every invoice surface reads (Invoice::paymentProgress()), so the
            // share link shows the client exactly what the admin sees.
            'payment_progress' => $invoice->paymentProgress(),
            // Whether this tenant accepts part payments. The page itself has
            // never offered an amount field — it always submits the full
            // outstanding balance — so this is what lets it TELL the client
            // the invoice has to be settled in one payment, rather than them
            // finding out by asking. pay() enforces it either way, which is
            // what closes the door on a request made straight to the API.
            'allows_partial_payment' => !(CompanyDealSettings::forInvoice($invoice)?->requiresFullPayment() ?? false),
            'due_date'         => $invoice->due_date?->toDateString(),
            'notes'            => $invoice->notes,
            // What this invoice is FOR ("50% Advance Payment", "Milestone 2",
            // …). The line items carry their own descriptions, but a
            // project/milestone invoice raised from the project billing screen
            // has only a generic one — without this the client saw an amount
            // and no explanation of what they were paying for.
            'invoice_purpose'  => $invoice->invoice_purpose,
            'token_expires_at' => $invoice->token_expires_at?->toIso8601String(),
            'items'            => $invoice->items->map(fn($i) => [
                'description' => $i->description,
                'quantity'    => (float) $i->quantity,
                'unit_price'  => (float) $i->unit_price,
                'total'       => (float) $i->total,
            ]),
            'available_gateways'          => $gateways,
            'gateway_unavailable_message' => $gatewayUnavailableMessage,
            'bank_details'                => $bankDetails ?: null,
            'has_pending_payment'         => $hasPendingPayment,
            'project'                     => $this->projectSummary($invoice),
            'project_history'             => $this->projectHistory($invoice),
        ]);
    }

    // Every PAID project tied to this guest/lead's account — completed,
    // previous and latest — same client_id/lead_id match as
    // Api\{Admin,User}\InvoiceController::invoiceProjectHistory(). No
    // financials here (unlike projectSummary()'s current-project card) — a
    // guest link shouldn't surface pricing on invoices/projects other than
    // the one it was sent for.
    //
    // Gated on the CURRENT invoice being paid, and on each listed project
    // having at least one paid invoice of its own — this is a "proof of
    // delivery" section for a paying customer, not a preview of unpaid/
    // in-progress work, so it must never appear on a draft/sent/pending
    // invoice, and must never list a project nobody has actually paid for.
    private function projectHistory(Invoice $invoice): array
    {
        if ($invoice->status !== 'paid') {
            return [];
        }

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
            ->whereHas('invoices', fn ($q) => $q->where('status', 'paid'))
            // Every delivery, not just the current/latest one — projects.
            // delivery_* only ever holds the latest, so a project delivered
            // more than once (e.g. 3 separate files sent over time) would
            // otherwise lose every file but the last from this list. Mirrors
            // Api\Client\ProjectController::show()'s own 'deliverySubmissions'.
            ->with('deliverySubmissions:id,project_id,file_name,delivered_at')
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'status', 'start_date', 'deadline', 'created_at']);

        $taskStats = Task::whereIn('project_id', $projects->pluck('id'))
            ->selectRaw('project_id, COUNT(*) as total, SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as done')
            ->groupBy('project_id')
            ->get()
            ->keyBy('project_id');

        return $projects->map(function ($p) use ($taskStats) {
            $stats = $taskStats[$p->id] ?? null;
            $progress = in_array($p->status, ['completed', 'closed'], true)
                ? 100
                : ($stats && $stats->total > 0 ? round(($stats->done / $stats->total) * 100) : 0);

            return [
                'id'         => $p->id,
                'name'       => $p->name,
                'status'     => $p->status,
                'progress'   => $progress,
                'start_date' => $p->start_date?->toDateString(),
                'deadline'   => $p->deadline?->toDateString(),
                // Every file sent for this project, oldest last — each one
                // downloads via downloadDeliverySubmission() below.
                'deliveries' => $p->deliverySubmissions->map(fn ($d) => [
                    'id'           => $d->id,
                    'file_name'    => $d->file_name,
                    'delivered_at' => $d->delivered_at?->toIso8601String(),
                ])->values(),
            ];
        })->values()->toArray();
    }

    // GET /public/invoices/{token}/delivery/download — the guest-customer
    // counterpart to Api\Client\ProjectController::downloadDelivery(). A
    // guest invoice (no client_id, so no portal account exists at all) has
    // no other way to reach the delivered file, so this reuses the same
    // payment_token this page already runs on, guarded the same way show()
    // is: an invalid or expired link still 404s/410s here, and the file is
    // only ever served once Admin has actually approved the delivery.
    public function downloadDelivery(string $token): StreamedResponse|JsonResponse
    {
        $invoice = Invoice::where('payment_token', $token)->first();

        if (!$invoice) {
            return ApiResponse::error('Invalid payment link', 404);
        }

        if ($invoice->token_expires_at && $invoice->token_expires_at->isPast()) {
            return ApiResponse::error('Payment link has expired', 410);
        }

        $project = $invoice->project
            ?: Project::where('company_id', $invoice->company_id)
                ->where('invoice_id', $invoice->id)
                ->first();

        if (!$project) {
            return ApiResponse::error('No project is linked to this invoice.', 404);
        }

        if ($project->delivery_status !== 'delivered_to_client'
            || (!$project->delivery_file_path && !$project->delivery_google_drive_file_id)) {
            return ApiResponse::error('No project delivery is available yet.', 422);
        }

        if ($project->delivery_storage_type === 'internal' && !Storage::exists($project->delivery_file_path)) {
            return ApiResponse::error('The delivery file is missing from storage.', 422);
        }

        return AttachmentStorageService::download(
            $project->delivery_storage_type, $project->delivery_file_path,
            $project->delivery_file_name ?? "{$project->name}-delivery.zip",
            $project->delivery_file_type, $project->delivery_google_drive_file_id, $project->company_id
        );
    }

    // GET /public/invoices/{token}/projects/{project}/delivery/download — the
    // Project History counterpart to downloadDelivery() above: lets a guest
    // download the delivered file from any OLD/other project in their
    // history, not just the one this invoice is directly linked to. $project
    // must actually belong to the same client_id/lead_id as the token's
    // invoice (same match as projectHistory()) — otherwise a guest could
    // swap in an arbitrary project id and pull a stranger's delivered file.
    public function downloadProjectDelivery(string $token, int $project): StreamedResponse|JsonResponse
    {
        $projectId = $project;
        $invoice = Invoice::where('payment_token', $token)->with('lead:id')->first();

        if (!$invoice) {
            return ApiResponse::error('Invalid payment link', 404);
        }

        if ($invoice->token_expires_at && $invoice->token_expires_at->isPast()) {
            return ApiResponse::error('Payment link has expired', 410);
        }

        $clientId = $invoice->client_id ?: $invoice->lead?->client?->id;
        $leadId   = $invoice->lead_id;

        // Neither id resolved — this token's invoice has no account to match
        // a project against. Must reject explicitly: an empty where()
        // closure below would add no condition at all, silently widening the
        // query to every project in the company instead of narrowing it.
        if (!$clientId && !$leadId) {
            return ApiResponse::error('Project not found', 404);
        }

        $project = Project::where('id', $projectId)
            ->where('company_id', $invoice->company_id)
            ->where(function ($q) use ($clientId, $leadId) {
                if ($clientId) { $q->orWhere('client_id', $clientId); }
                if ($leadId)   { $q->orWhere('lead_id', $leadId); }
            })
            ->first();

        if (!$project) {
            return ApiResponse::error('Project not found', 404);
        }

        if ($project->delivery_status !== 'delivered_to_client'
            || (!$project->delivery_file_path && !$project->delivery_google_drive_file_id)) {
            return ApiResponse::error('No project delivery is available yet.', 422);
        }

        if ($project->delivery_storage_type === 'internal' && !Storage::exists($project->delivery_file_path)) {
            return ApiResponse::error('The delivery file is missing from storage.', 422);
        }

        return AttachmentStorageService::download(
            $project->delivery_storage_type, $project->delivery_file_path,
            $project->delivery_file_name ?? "{$project->name}-delivery.zip",
            $project->delivery_file_type, $project->delivery_google_drive_file_id, $project->company_id
        );
    }

    // GET /public/invoices/{token}/deliveries/{delivery}/download — one
    // specific past delivery, not just a project's latest file. A project
    // delivered more than once (e.g. 3 separate files sent over time) has
    // one ProjectDeliverySubmission row per file — downloadProjectDelivery()
    // above only ever serves projects.delivery_file_path, the CURRENT one,
    // so earlier files were unreachable from this page. Mirrors
    // Api\Client\ProjectController::downloadDeliverySubmission() — ownership
    // resolved through the submission's own project, same client_id/lead_id
    // match as everywhere else in this controller.
    public function downloadDeliverySubmission(string $token, int $delivery): StreamedResponse|JsonResponse
    {
        $invoice = Invoice::where('payment_token', $token)->with('lead:id')->first();

        if (!$invoice) {
            return ApiResponse::error('Invalid payment link', 404);
        }

        if ($invoice->token_expires_at && $invoice->token_expires_at->isPast()) {
            return ApiResponse::error('Payment link has expired', 410);
        }

        $clientId = $invoice->client_id ?: $invoice->lead?->client?->id;
        $leadId   = $invoice->lead_id;

        if (!$clientId && !$leadId) {
            return ApiResponse::error('Delivery not found', 404);
        }

        $submission = ProjectDeliverySubmission::where('id', $delivery)
            ->whereHas('project', function ($q) use ($invoice, $clientId, $leadId) {
                $q->where('company_id', $invoice->company_id)
                    ->where(function ($q2) use ($clientId, $leadId) {
                        if ($clientId) { $q2->orWhere('client_id', $clientId); }
                        if ($leadId)   { $q2->orWhere('lead_id', $leadId); }
                    });
            })
            ->first();

        if (!$submission) {
            return ApiResponse::error('Delivery not found', 404);
        }

        if ($submission->storage_type === 'internal' && !Storage::exists($submission->file_path)) {
            return ApiResponse::error('The delivery file is missing from storage.', 422);
        }

        return AttachmentStorageService::download(
            $submission->storage_type, $submission->file_path, $submission->file_name ?? 'delivery.zip',
            $submission->file_type, $submission->google_drive_file_id, $invoice->company_id
        );
    }

    private function projectSummary(Invoice $invoice): ?array
    {
        $project = $invoice->project
            ?: Project::where('company_id', $invoice->company_id)
                ->where('invoice_id', $invoice->id)
                ->first();

        if (!$project) {
            return null;
        }

        $taskStats = Task::where('project_id', $project->id)
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as done')
            ->first();
        $totalTasks = (int) ($taskStats?->total ?? 0);
        $doneTasks  = (int) ($taskStats?->done ?? 0);
        // A completed/closed project reads 100% regardless of its task ratio
        // — same override as Api\Admin\User\Client\ProjectController — else a
        // zero-task (or partially-open-task) completed project shows 0%/
        // partial here forever, since projects.progress is never persisted.
        $progress   = in_array($project->status, ['completed', 'closed'], true)
            ? 100
            : ($totalTasks > 0 ? (int) round(($doneTasks / $totalTasks) * 100) : (int) ($project->progress ?? 0));

        $projectInvoices = Invoice::where('company_id', $project->company_id)
            ->where(fn ($q) => $q->where('project_id', $project->id)->orWhere('id', $project->invoice_id))
            ->orderBy('created_at')
            ->get(['id', 'invoice_number', 'total_amount', 'paid_amount', 'status', 'due_date', 'currency', 'project_id']);
        $totalInvoiced = (float) $projectInvoices->sum('total_amount');
        $totalPaid     = (float) $projectInvoices->sum('paid_amount');
        $isMainInvoice = (int) $project->invoice_id === (int) $invoice->id;
        if ($projectInvoices->count() <= 1) {
            $isMainInvoice = true;
        }

        $portalActive = (bool) ($invoice->client?->portal_access && $invoice->client?->user_id);
        $showFull = $isMainInvoice || $portalActive;

        return [
            'id'                 => $project->id,
            'name'               => $project->name,
            'reference'          => $project->reference,
            'status'             => $project->status,
            'progress'           => $progress,
            // Whether the final package is ready — surfaced here so the pay
            // page can offer a Download button even for a guest customer
            // with no client portal to log into (see downloadDelivery()
            // below, keyed off this same payment_token).
            'delivery_status'    => $project->delivery_status,
            'delivery_file_name' => $project->delivery_status === 'delivered_to_client' ? $project->delivery_file_name : null,
            // Every file ever sent for this project, not just the latest —
            // projects.delivery_file_name above only ever holds the current
            // one. Each downloads via downloadDeliverySubmission() below.
            'deliveries'         => $project->deliverySubmissions()
                ->orderByDesc('delivered_at')
                ->get(['id', 'file_name', 'delivered_at'])
                ->map(fn ($d) => [
                    'id'           => $d->id,
                    'file_name'    => $d->file_name,
                    'delivered_at' => $d->delivered_at?->toIso8601String(),
                ]),
            'is_main_invoice'    => $isMainInvoice,
            'invoice_count'      => $projectInvoices->count(),
            'portal_active'      => $portalActive,
            'view_mode'          => $showFull ? 'full' : 'progress',
            'start_date'         => $showFull ? $project->start_date?->toDateString() : null,
            'created_at'         => $showFull ? $project->created_at?->toIso8601String() : null,
            'deadline'           => $showFull ? $project->deadline?->toDateString() : null,
            'total_invoiced'     => $showFull ? $totalInvoiced : null,
            'total_paid'         => $showFull ? $totalPaid : null,
            'outstanding'        => $showFull ? max(0, round($totalInvoiced - $totalPaid, 2)) : null,
            'invoices'           => $showFull ? $projectInvoices->map(fn ($i) => [
                'id'             => $i->id,
                'invoice_number' => $i->invoice_number,
                'status'         => $i->status,
                'total_amount'   => (float) $i->total_amount,
                'paid_amount'    => (float) $i->paid_amount,
                'outstanding'    => max(0, round((float) $i->total_amount - (float) $i->paid_amount, 2)),
                'currency'       => $i->currency,
                'due_date'       => $i->due_date?->toDateString(),
                'is_current'     => (int) $i->id === (int) $invoice->id,
            ])->values() : [],
        ];
    }

    // The gateway account(s) this invoice's public/client pay page should
    // offer: the invoice's own explicit selection (filtered to still-active
    // accounts) if one was ever made, else the tenant's per-type defaults
    // (today's effective behavior, preserved for invoices created before
    // this feature existed). Returns [accounts, unavailableMessage] — the
    // message is only set when the invoice HAD a selection but none of it is
    // active anymore (distinct from "company has no gateway configured at
    // all", which the invoice-create flow warns about separately).
    private function resolveInvoiceGateways(Invoice $invoice): array
    {
        $allowed = $invoice->paymentGatewayAccounts;

        if ($allowed->isEmpty()) {
            $rows = CompanyPaymentGateway::resolveActiveGateways($invoice->company)
                ->groupBy('gateway')
                ->map(fn($rows) => $rows->firstWhere('is_default', true) ?? $rows->first());

            return [$this->formatGatewayAccounts($rows, $invoice), null];
        }

        $active = $allowed->where('is_active', true);

        if ($active->isEmpty()) {
            return [[], 'No active payment gateway is available for this invoice. Please contact support.'];
        }

        return [$this->formatGatewayAccounts($active, $invoice), null];
    }

    private function formatGatewayAccounts($rows, Invoice $invoice): array
    {
        $outstanding = round((float) $invoice->total_amount - (float) $invoice->paid_amount, 2);

        return $rows->map(function ($g) use ($invoice, $outstanding) {
            $supportsInvoiceCurrency = GatewayCurrencySupport::supports($g->gateway, $invoice->currency);
            $conversionPreview       = null;

            if (!$supportsInvoiceCurrency && $outstanding > 0) {
                try {
                    $converted = CurrencyConversionService::convert($outstanding, $invoice->currency ?: 'USD', 'USD');
                    $conversionPreview = [
                        'amount'   => $converted['amount'],
                        'currency' => 'USD',
                        'rate'     => $converted['rate'],
                    ];
                } catch (\Throwable $e) {
                    // The pay page falls back to just not showing a preview line
                    // for this gateway — the actual charge attempt will surface
                    // the same failure clearly if the customer picks it anyway.
                    Log::warning('Conversion preview failed for public invoice gateway', [
                        'invoice_id' => $invoice->id, 'gateway' => $g->gateway, 'error' => $e->getMessage(),
                    ]);
                }
            }

            return [
                'id'                        => $g->id,
                'type'                      => $g->gateway,
                'label'                     => $g->label ?: (CompanyPaymentGateway::GATEWAYS[$g->gateway] ?? $g->gateway),
                'supports_invoice_currency' => $supportsInvoiceCurrency,
                'conversion_preview'        => $conversionPreview,
            ];
        })->values()->toArray();
    }

    // POST /public/invoices/{token}/pay
    public function pay(Request $request, string $token): JsonResponse
    {
        $invoice = Invoice::where('payment_token', $token)
            ->with('company')
            ->first();

        if (!$invoice) {
            return ApiResponse::error('Invalid payment link', 404);
        }

        if ($invoice->token_expires_at && $invoice->token_expires_at->isPast()) {
            return ApiResponse::error('Payment link has expired', 410);
        }

        // No NEW payment claim while the company is suspended — an already-
        // pending one (submitted before suspension) is untouched here; it
        // still gets reconciled normally by Admin\PaymentController::confirm()
        // or, for a gateway payment, the webhook/return-callback paths below,
        // neither of which this check touches.
        if ($invoice->company && !$invoice->company->isOperational()) {
            return ApiResponse::error('Payments for this account are temporarily unavailable. Please contact the business directly.', 422);
        }

        if ($invoice->status === 'paid') {
            return ApiResponse::error('Invoice is already paid', 422);
        }

        if (in_array($invoice->status, ['cancelled'])) {
            return ApiResponse::error('This invoice is no longer active', 422);
        }

        // Prevent duplicate submissions — one unresolved claim per invoice at a
        // time; the company admin must confirm or reject it before another
        // attempt can be submitted.
        if ($invoice->payments()->where('status', 'pending')->exists()) {
            return ApiResponse::error('A payment is already awaiting confirmation for this invoice.', 422);
        }

        $onlineGatewayKeys = array_keys(CompanyPaymentGateway::GATEWAYS);
        $allowedGateways   = array_merge(['bank_transfer'], $onlineGatewayKeys);
        $outstanding       = round((float) $invoice->total_amount - (float) $invoice->paid_amount, 2);

        // The tenant's Payment Policy. This is the ONE place a client can ever
        // choose how much to pay — the client portal has always charged the
        // full outstanding balance — so it is the one place the policy has to
        // be enforced. Under "Full Payment Only" a smaller figure is refused
        // outright rather than quietly rounded up, so the client is told why
        // instead of seeing an amount they didn't ask for.
        $requiresFullPayment = CompanyDealSettings::forInvoice($invoice)?->requiresFullPayment() ?? false;

        // min: the full balance under a full-only policy, any penny otherwise.
        // Both bounds stay on the outstanding balance, so an over-payment is
        // impossible either way.
        $minAmount = $requiresFullPayment ? $outstanding : 0.01;

        $data = $request->validate([
            'gateway'     => 'required|in:' . implode(',', $allowedGateways),
            'gateway_ref' => 'nullable|string|max:255',
            'notes'       => 'nullable|string|max:500',
            'amount'      => "nullable|numeric|min:{$minAmount}|max:{$outstanding}",
        ], $requiresFullPayment ? [
            'amount.min' => "{$invoice->currency} " . number_format($outstanding, 2)
                . ' is due and this invoice has to be paid in full — part payments are not accepted.',
        ] : []);

        // Log payment attempt in audit trail before creating (safe even if creation fails)
        InvoicePaymentService::logPayment(
            $invoice,
            new Payment([
                'invoice_id'  => $invoice->id,
                'amount'      => $data['amount'] ?? ((float) $invoice->total_amount - (float) $invoice->paid_amount),
                'method'      => in_array($data['gateway'], $onlineGatewayKeys) ? 'gateway' : 'bank_transfer',
                'gateway'     => in_array($data['gateway'], $onlineGatewayKeys) ? $data['gateway'] : null,
                'status'      => 'pending',
            ]),
            'public_link',
            $request->ip()
        );

        $isOnlineGateway = in_array($data['gateway'], $onlineGatewayKeys);
        $dbMethod        = $isOnlineGateway ? 'gateway' : 'bank_transfer';
        $dbGateway       = $isOnlineGateway ? $data['gateway'] : null;
        $amount          = $data['amount'] ?? $outstanding;

        // No receipt yet — a receipt implies money actually received. It's
        // generated once the company admin confirms this claim (see
        // Admin\PaymentController::confirm()), not at submission time.
        $payment = Payment::create([
            'invoice_id'     => $invoice->id,
            'amount'         => $amount,
            // Always the invoice's own currency — this claim's amount is
            // computed straight from the invoice's own outstanding balance,
            // never a separately-entered figure. Stamping it (not leaving it
            // null) is what lets reporting/aggregation code group payments
            // by currency correctly instead of assuming one.
            'currency'       => $invoice->currency,
            'method'         => $dbMethod,
            'gateway'        => $dbGateway,
            'gateway_ref'    => $data['gateway_ref'] ?? null,
            'notes'          => $data['notes'] ?? null,
            'status'         => 'pending',
            'payment_date'   => now()->toDateString(),
        ]);

        // Invoice status/paid_amount is NOT touched here — it only changes
        // once the company admin verifies and confirms this payment claim
        // (InvoicePaymentService::applyToInvoice runs from confirm() instead).

        // Audit log with actual payment ID
        InvoicePaymentService::logPayment($invoice, $payment, 'public_link', $request->ip());

        // Notify the company admin (email + in-app activity feed) that a
        // payment claim is awaiting their verification.
        InvoicePaymentService::notifyAdmin($invoice, $payment);

        return ApiResponse::success([
            'payment_id'      => $payment->id,
            'amount'          => $amount,
            'invoice_status'  => $invoice->status,
        ], 'Payment request submitted. Awaiting verification.');
    }

    // POST /public/invoices/{token}/gateways/{gateway}/initiate
    // Starts a real hosted checkout (Stripe/PayPal/Authorize.net) — unlike
    // pay() this does not create a "please verify me" claim; the payment is
    // only ever marked confirmed by the gateway's webhook (or, for immediate
    // UX, the customer's return-callback — both paths are idempotent).
    public function initiate(Request $request, string $token, string $gateway): JsonResponse
    {
        if (!array_key_exists($gateway, CompanyPaymentGateway::GATEWAYS)) {
            return ApiResponse::error('Unknown payment gateway', 422);
        }

        $invoice = Invoice::where('payment_token', $token)->with('company')->first();

        if (!$invoice) {
            return ApiResponse::error('Invalid payment link', 404);
        }
        if ($invoice->token_expires_at && $invoice->token_expires_at->isPast()) {
            return ApiResponse::error('Payment link has expired', 410);
        }
        if ($invoice->status === 'paid') {
            return ApiResponse::error('Invoice is already paid', 422);
        }
        if (in_array($invoice->status, ['cancelled'])) {
            return ApiResponse::error('This invoice is no longer active', 422);
        }

        // No NEW checkout while the company is suspended — see pay()'s
        // identical check above; an already-initiated checkout still finishes
        // via returnFromGateway()/the gateway webhook, neither of which is
        // gated by this.
        if ($invoice->company && !$invoice->company->isOperational()) {
            return ApiResponse::error('Payments for this account are temporarily unavailable. Please contact the business directly.', 422);
        }

        $existingPending = $invoice->payments()->where('status', 'pending')->latest('id')->first();
        if ($existingPending && $existingPending->gateway !== $gateway) {
            return ApiResponse::error('A payment is already awaiting confirmation for this invoice.', 422);
        }

        $company = $invoice->company;
        $companyGatewayId = $request->input('company_gateway_id') ? (int) $request->input('company_gateway_id') : null;
        $gatewayRow = $this->resolveGatewayForInvoice($invoice, $gateway, $companyGatewayId);
        if (!$gatewayRow) {
            return ApiResponse::error('This payment gateway is not available for this invoice.', 422);
        }

        $amount = round((float) $invoice->total_amount - (float) $invoice->paid_amount, 2);
        if ($amount <= 0) {
            return ApiResponse::error('Invoice is already paid', 422);
        }

        $frontendUrl = FrontendUrl::base();
        $returnUrl   = "{$frontendUrl}/pay/invoice/{$token}/return/{$gateway}";
        $cancelUrl   = "{$frontendUrl}/pay/invoice/{$token}?cancelled=1";

        try {
            $gatewayMode = $gatewayRow->config['mode'] ?? 'sandbox';

            $payment = $existingPending ?? Payment::create([
                'invoice_id'          => $invoice->id,
                'amount'              => $amount,
                'currency'            => $invoice->currency,
                'method'              => 'gateway',
                'gateway'             => $gateway,
                'company_gateway_id'  => $gatewayRow->id,
                'gateway_mode'        => $gatewayMode,
                'status'              => 'pending',
                'payment_date'        => now()->toDateString(),
            ]);

            $checkout = PaymentGatewayManager::driver($gateway)
                ->createCheckout($invoice, $amount, $gatewayRow->config, $returnUrl, $cancelUrl);

            $payment->update(['gateway_session_id' => $checkout->sessionId, 'gateway_mode' => $gatewayMode]);

            InvoicePaymentService::logPayment($invoice, $payment, 'public_link_gateway_checkout', $request->ip());

            return ApiResponse::success(array_merge($checkout->toArray(), ['payment_id' => $payment->id]), 'Checkout started');
        } catch (\Throwable $e) {
            Log::error('Payment gateway checkout initiation failed', [
                'gateway' => $gateway, 'invoice_id' => $invoice->id, 'error' => $e->getMessage(),
            ]);

            // A pending row that never got a real gateway session represents
            // no actual payment attempt — leaving it "pending" would wrongly
            // block every retry behind the "awaiting confirmation" screen
            // (see show()'s has_pending_payment) and this same 422 check
            // above. Mark it failed instead (not delete, so the attempt and
            // its error are still visible in the audit trail) so the
            // customer can immediately try again.
            if (isset($payment) && $payment->status === 'pending' && !$payment->gateway_session_id) {
                $payment->update(['status' => 'failed', 'notes' => Str::limit($e->getMessage(), 500)]);
            }

            return ApiResponse::error('Could not start payment. Please try again or choose another payment method.', 500);
        }
    }

    // Shared lookup for the inline-payment endpoints below — same
    // token/expiry/status guards initiate() already applies.
    private function invoiceForGatewayAction(string $token): Invoice
    {
        $invoice = Invoice::where('payment_token', $token)->with('company')->first();

        if (!$invoice) {
            throw new \RuntimeException('Invalid payment link');
        }
        if ($invoice->token_expires_at && $invoice->token_expires_at->isPast()) {
            throw new \RuntimeException('Payment link has expired');
        }

        // Shared by initGateway()/createPaypalOrder()/chargeGateway() below —
        // none of them should let a suspended company's public link mount a
        // payment widget or take a new charge. Same message/behaviour as
        // pay()/initiate() above; an in-flight payment reconciles unaffected
        // via returnFromGateway()/the gateway webhook.
        if ($invoice->company && !$invoice->company->isOperational()) {
            throw new \RuntimeException('Payments for this account are temporarily unavailable. Please contact the business directly.');
        }

        return $invoice;
    }

    // GET /public/invoices/{token}/gateways/{gateway}/init — public-safe
    // credentials for mounting Stripe Elements / PayPal Buttons / Authorize.net
    // Accept.js inline on this page (no redirect, unlike initiate() above).
    public function initGateway(Request $request, string $token, string $gateway): JsonResponse
    {
        try {
            $invoice = $this->invoiceForGatewayAction($token);
            $companyGatewayId = $request->query('company_gateway_id') ? (int) $request->query('company_gateway_id') : null;
            return ApiResponse::success(InvoiceGatewayChargeService::publicInit($invoice, $gateway, $companyGatewayId));
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    // POST /public/invoices/{token}/gateways/paypal/create-order
    public function createPaypalOrder(Request $request, string $token): JsonResponse
    {
        try {
            $invoice = $this->invoiceForGatewayAction($token);
            $companyGatewayId = $request->input('company_gateway_id') ? (int) $request->input('company_gateway_id') : null;
            return ApiResponse::success(InvoiceGatewayChargeService::createPaypalOrder($invoice, $companyGatewayId));
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    // POST /public/invoices/{token}/gateways/{gateway}/charge — inline charge,
    // finalized synchronously from this request's own response (no webhook
    // involved), mirroring Api\Admin\SubscriptionPaymentController::process().
    public function chargeGateway(Request $request, string $token, string $gateway): JsonResponse
    {
        $data = $request->validate(match ($gateway) {
            'stripe'        => ['payment_method_id' => 'required|string'],
            'paypal'        => ['paypal_order_id' => 'required|string'],
            'authorize_net' => ['opaque_data_descriptor' => 'required|string', 'opaque_data_value' => 'required|string'],
            default         => [],
        });

        try {
            $invoice = $this->invoiceForGatewayAction($token);
            $companyGatewayId = $request->input('company_gateway_id') ? (int) $request->input('company_gateway_id') : null;
            $result  = InvoiceGatewayChargeService::charge(
                $invoice, $gateway, $data, 'public_link_gateway_inline', null, $request->ip(), $companyGatewayId
            );
            return ApiResponse::success($result, 'Payment successful');
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    // GET /public/invoices/{token}/return/{gateway}
    // Immediate-UX confirmation when the customer's browser lands back from
    // the gateway's hosted page. Not the source of truth — the gateway's
    // webhook (see Api\Webhooks\PaymentWebhookController) finalizes the
    // payment authoritatively and idempotently either way, so a customer who
    // closes the tab before this loads is still confirmed once the webhook
    // arrives.
    public function returnFromGateway(Request $request, string $token, string $gateway): JsonResponse
    {
        if (!array_key_exists($gateway, CompanyPaymentGateway::GATEWAYS)) {
            return ApiResponse::error('Unknown payment gateway', 422);
        }

        $invoice = Invoice::where('payment_token', $token)->with('company')->first();
        if (!$invoice) {
            return ApiResponse::error('Invalid payment link', 404);
        }

        if ($invoice->status === 'paid') {
            return ApiResponse::success(['status' => 'paid'], 'Invoice already paid');
        }

        $fallbackRow = PaymentGatewayManager::activeRowFor($invoice->company, $gateway);
        if (!$fallbackRow) {
            return ApiResponse::error('This payment gateway is not available for this invoice.', 422);
        }

        // PayPal requires an explicit capture step after approval. Stripe
        // Checkout Sessions auto-settle on Stripe's side, but we still check
        // the session's own status directly here as an immediate-UX fallback
        // — the webhook is the authoritative path, but in an environment
        // where it can't reach this app (e.g. local/sandbox dev with no
        // public URL), this is what actually finalizes the payment.
        // Authorize.net's Accept Hosted auto-settles with no equivalent
        // return-time lookup available, so there's nothing further to call
        // for it here — just report current status.
        //
        // The specific account is read off the payment row itself
        // (company_gateway_id, set when the checkout was initiated) rather
        // than re-resolved by type — necessary once a tenant can have
        // multiple accounts of the same gateway type. Falls back to the
        // type's default account for older pending payments predating that
        // column.
        if ($gateway === 'paypal') {
            $orderId = $request->query('token');
            $payment = $invoice->payments()->where('gateway', 'paypal')->where('gateway_session_id', $orderId)->first();

            if ($payment && $payment->status === 'pending') {
                $gatewayRow = $payment->company_gateway_id
                    ? (PaymentGatewayManager::accountById($invoice->company, $payment->company_gateway_id) ?? $fallbackRow)
                    : $fallbackRow;
                $driver = PaymentGatewayManager::driver('paypal');
                $result = $driver->captureOrder($orderId, $gatewayRow->config);
                if ($result['success'] ?? false) {
                    InvoicePaymentService::finalizeGatewaySuccess($payment, $result['transaction_id']);
                }
            }
        }

        if ($gateway === 'stripe') {
            $sessionId = $request->query('session_id');
            $payment   = $invoice->payments()->where('gateway', 'stripe')->where('gateway_session_id', $sessionId)->first();

            if ($payment && $payment->status === 'pending') {
                $gatewayRow = $payment->company_gateway_id
                    ? (PaymentGatewayManager::accountById($invoice->company, $payment->company_gateway_id) ?? $fallbackRow)
                    : $fallbackRow;
                $driver  = PaymentGatewayManager::driver('stripe');
                $session = $driver->getCheckoutSession($sessionId, $gatewayRow->config);
                if (($session['payment_status'] ?? '') === 'paid') {
                    InvoicePaymentService::finalizeGatewaySuccess($payment, $session['payment_intent'] ?? $sessionId);
                }
            }
        }

        $invoice->refresh();

        return ApiResponse::success(['status' => $invoice->status], 'Payment status');
    }

    // Account-aware resolution shared by initiate()/chargeGateway()-adjacent
    // endpoints that don't go through InvoiceGatewayChargeService — same
    // rules as that service's private gatewayRow(): explicit account id must
    // belong to this tenant, be active, match the requested type, and (if
    // the invoice has its own explicit selection) be one of the invoice's
    // allowed accounts. Falls back to the type's default account when no id
    // is given.
    private function resolveGatewayForInvoice(Invoice $invoice, string $gateway, ?int $companyGatewayId): ?CompanyPaymentGateway
    {
        if ($companyGatewayId === null) {
            return PaymentGatewayManager::activeRowFor($invoice->company, $gateway);
        }

        $row = PaymentGatewayManager::accountById($invoice->company, $companyGatewayId);
        if (!$row || $row->gateway !== $gateway) {
            return null;
        }

        $allowed = $invoice->paymentGatewayAccounts;
        if ($allowed->isNotEmpty() && !$allowed->contains('id', $row->id)) {
            return null;
        }

        return $row;
    }
}
