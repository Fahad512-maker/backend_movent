<?php

namespace App\Http\Controllers\Api\Finance;

use App\Helpers\ApiResponse;
use App\Services\Finance\FinanceCsv;
use App\Services\Finance\FinancePresenter;
use App\Services\Finance\FinanceScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Finance > Invoices — the finance-eye view of the SAME invoices the Invoice
 * module manages. No second invoice system, no second table, no parallel
 * numbering: these read `invoices` through FinanceScope and present the
 * columns a finance person needs (outstanding, payment status, created by,
 * company) rather than the ones a salesperson does.
 *
 * WRITES ARE NOT HERE, ON PURPOSE. Creating and updating an invoice is
 * already implemented — validation, numbering, brand resolution, gateway
 * selection, deal-eligibility recomputation, audit — in
 * Api\User\InvoiceController::store()/update() and its Admin counterpart.
 * Re-implementing any of that behind a /finance/invoices POST would be the
 * "second invoice system" the spec forbids, and the two copies would drift
 * the first time either changed. So Finance reuses those endpoints as-is;
 * what changed is only their permission gate, which now also accepts the
 * Finance keys (finance.canCreateFinanceInvoices /
 * finance.canUpdateFinanceInvoices) alongside the Invoice module's own. A
 * Finance user therefore creates an ordinary invoice through the ordinary
 * code path — see `create_endpoint` / `update_endpoint` in index()'s
 * response, which tells the UI exactly where to post.
 */
class InvoiceController extends FinanceController
{
    /** canViewFinanceInvoices, or the Invoice module's own view right. */
    private const VIEW = [['finance', 'canViewFinanceInvoices'], ['invoice', 'canViewInvoices']];

    private const EXPORT = [['finance', 'canExportFinanceInvoices'], ['invoice', 'canDownloadOrExportInvoices']];

    // GET /{admin|user}/finance/invoices
    public function index(Request $request): JsonResponse
    {
        if ($fail = $this->guard($request, self::VIEW)) {
            return $fail;
        }

        $companyNames = $this->ctx->companyNames();

        $query = FinanceScope::applyInvoiceFilters(
            FinanceScope::invoices($this->ctx),
            $request,
            $this->ctx
        )->with([
            'client:id,name,company_name,email',
            'lead:id,name',
            'project:id,name',
            'createdBy:id,name',
            // Eager-loaded so Invoice::paymentStatus() answers from memory
            // instead of firing a query per row — see the note on the model's
            // $appends.
            'payments:id,invoice_id,status,created_at',
        ])->latest('invoices.created_at');

        $perPage = min(max((int) $request->input('per_page', 25), 1), 200);
        $page    = $query->paginate($perPage);

        return ApiResponse::success([
            'invoices' => collect($page->items())
                ->map(fn ($i) => FinancePresenter::invoiceRow($i, $companyNames))
                ->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
            ],
            'filters' => [
                'companies'  => collect($companyNames)->map(fn ($n, $id) => ['id' => (int) $id, 'name' => $n])->values()->all(),
                'statuses'   => ['draft', 'sent', 'partially_paid', 'paid', 'overdue', 'cancelled'],
                'currencies' => FinanceScope::invoices($this->ctx)
                    ->distinct()
                    ->orderBy('invoices.currency')
                    ->pluck('invoices.currency')
                    ->filter()
                    ->values()
                    ->all(),
            ],
            'capabilities' => $this->capabilities($this->ctx),
            // Where Create/Update actually go. The Finance Area owns no
            // invoice-writing endpoint of its own (see the class note), so
            // the UI is told the real one rather than guessing a prefix.
            'create_endpoint' => $this->ctx->isAdmin() ? '/admin/invoices' : '/user/invoices',
            'update_endpoint' => $this->ctx->isAdmin() ? '/admin/invoices/{id}' : '/user/invoices/{id}',
        ]);
    }

    // GET /{admin|user}/finance/invoices/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        if ($fail = $this->guard($request, self::VIEW)) {
            return $fail;
        }

        $invoice = FinanceScope::findInvoice($this->ctx, $id, [
            'client:id,name,company_name,email,phone,address',
            'lead:id,name,email',
            'project:id,name,status',
            'createdBy:id,name',
            'items',
            'payments' => fn ($q) => $q->with(['companyGateway:id,label,gateway', 'recordedBy:id,name', 'reconciledBy:id,name', 'reconciledByAdmin:id,name'])
                ->orderByDesc('payment_date')->orderByDesc('id'),
            'reminders' => fn ($q) => $q->with(['createdBy:id,name', 'createdByAdmin:id,name', 'sentBy:id,name', 'sentByAdmin:id,name'])
                ->orderByDesc('id'),
        ]);

        if (!$invoice) {
            return $this->notFound('Invoice');
        }

        $companyNames = $this->ctx->companyNames();

        return ApiResponse::success([
            'invoice' => FinancePresenter::invoiceRow($invoice, $companyNames) + [
                'subtotal'        => (float) $invoice->subtotal,
                'tax_rate'        => (float) $invoice->tax_rate,
                'tax_amount'      => (float) $invoice->tax_amount,
                'discount_amount' => (float) $invoice->discount_amount,
                'notes'           => $invoice->notes,
                'purpose'         => $invoice->invoice_purpose,
                'items'           => $invoice->items->map(fn ($it) => [
                    'description' => $it->description,
                    'quantity'    => (float) $it->quantity,
                    'unit_price'  => (float) $it->unit_price,
                    'total'       => (float) $it->total,
                ])->all(),
            ],
            'payments'  => $invoice->payments->map(fn ($p) => FinancePresenter::paymentRow($p, $companyNames))->all(),
            'reminders' => $invoice->reminders->map(fn ($r) => FinancePresenter::reminderRow($r, $companyNames))->all(),
            'capabilities' => $this->capabilities($this->ctx),
        ]);
    }

    /**
     * GET /{admin|user}/finance/invoices/export
     *
     * Exports exactly what index() would have listed under the same filters
     * — same FinanceScope query, same FinancePresenter fields — so the file
     * can never hold a row or a column the caller was not already shown. The
     * export permission decides whether a file may be produced at all; it
     * never widens which records go into it.
     */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        if ($fail = $this->guard($request, self::EXPORT)) {
            return $fail;
        }

        $companyNames = $this->ctx->companyNames();

        $rows = FinanceScope::applyInvoiceFilters(
            FinanceScope::invoices($this->ctx),
            $request,
            $this->ctx
        )->with([
            'client:id,name,company_name',
            'lead:id,name',
            'project:id,name',
            'createdBy:id,name',
            'payments:id,invoice_id,status,created_at',
        ])->latest('invoices.created_at')->cursor();

        \App\Services\Finance\FinanceAudit::log(
            $this->ctx,
            $this->ctx->primaryCompanyId(),
            'finance_invoices_exported',
            'Invoice',
            null,
            'Finance invoices exported to CSV',
            ['filters' => $request->query()],
        );

        return FinanceCsv::stream(
            FinanceCsv::filename('finance-invoices'),
            [
                'Invoice #', 'Company', 'Client/Customer', 'Invoice Date', 'Due Date',
                'Currency', 'Amount', 'Paid', 'Outstanding', 'Status', 'Payment Status',
                'Created By',
            ],
            (function () use ($rows, $companyNames) {
                foreach ($rows as $invoice) {
                    $r = FinancePresenter::invoiceRow($invoice, $companyNames);
                    yield [
                        $r['invoice_number'], $r['company_name'], $r['customer_name'],
                        $r['invoice_date'], $r['due_date'], $r['currency'],
                        number_format($r['total_amount'], 2, '.', ''),
                        number_format($r['paid_amount'], 2, '.', ''),
                        number_format($r['outstanding_amount'], 2, '.', ''),
                        $r['status'], $r['payment_status'], $r['created_by'],
                    ];
                }
            })()
        );
    }
}
