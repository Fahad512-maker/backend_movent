<?php

namespace App\Http\Controllers\Api\Finance;

use App\Helpers\ApiResponse;
use App\Services\Finance\FinanceAudit;
use App\Services\Finance\FinanceCsv;
use App\Services\Finance\FinancePresenter;
use App\Services\Finance\FinanceScope;
use App\Services\Finance\PaymentReconciliationService;
use App\Services\InvoicePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Finance > Payments — view, record, reconcile.
 *
 * Reads the same `payments` rows every other surface does, scoped through
 * FinanceScope. No gateway logic lives here and none was added: an online
 * charge is still created and finalised by the existing gateway stack
 * (App\Services\PaymentGateways, PaymentGatewayCharger, the webhook
 * controller and InvoicePaymentService::finalizeGatewaySuccess()). What this
 * controller adds is the two things a finance person does with a payment
 * AFTER the gateway is done: enter one that arrived off-platform, and sign
 * one off.
 *
 * Recording goes through InvoicePaymentService::record(), the same method
 * Api\Admin\PaymentController::store() now calls — one implementation, one
 * Payment Policy check, one crediting path.
 *
 * CONFIRM/REJECT of a customer-submitted claim is deliberately NOT here. It
 * already exists on both guards (Api\Admin\PaymentController::confirm() and
 * Api\User\PaymentController::confirm()), it moves money rather than
 * bookkeeping, and adding a third entry point to it would be exactly the
 * duplication this module is meant to avoid.
 */
class PaymentController extends FinanceController
{
    private const VIEW = [['finance', 'canViewPayments'], ['invoice', 'canViewPayments']];

    private const RECORD = [['finance', 'canRecordPayments'], ['invoice', 'canRecordPayments']];

    // GET /{admin|user}/finance/payments
    public function index(Request $request): JsonResponse
    {
        if ($fail = $this->guard($request, self::VIEW)) {
            return $fail;
        }

        $companyNames = $this->ctx->companyNames();

        $query = FinanceScope::applyPaymentFilters(
            FinanceScope::payments($this->ctx),
            $request,
            $this->ctx
        )->with([
            'invoice:id,company_id,invoice_number,client_id,lead_id,currency,customer_name,total_amount,paid_amount,status',
            'invoice.client:id,name,company_name',
            'invoice.lead:id,name',
            'companyGateway:id,label,gateway',
            'recordedBy:id,name',
            'reconciledBy:id,name',
            'reconciledByAdmin:id,name',
        ])->orderByDesc('payments.payment_date')->orderByDesc('payments.id');

        $perPage = min(max((int) $request->input('per_page', 25), 1), 200);
        $page    = $query->paginate($perPage);

        return ApiResponse::success([
            'payments' => collect($page->items())
                ->map(fn ($p) => FinancePresenter::paymentRow($p, $companyNames))
                ->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
            ],
            'filters' => [
                'companies'  => collect($companyNames)->map(fn ($n, $id) => ['id' => (int) $id, 'name' => $n])->values()->all(),
                'statuses'   => ['pending', 'confirmed', 'failed', 'refunded'],
                'reconciliation_statuses' => PaymentReconciliationService::STATUSES,
                'methods'    => ['bank_transfer', 'cash', 'card', 'cheque', 'gateway'],
                'gateways'   => array_keys(\App\Models\CompanyPaymentGateway::GATEWAYS),
            ],
            'capabilities' => $this->capabilities($this->ctx),
        ]);
    }

    // GET /{admin|user}/finance/payments/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        if ($fail = $this->guard($request, self::VIEW)) {
            return $fail;
        }

        $payment = $this->loadPayment($id);
        if (!$payment) {
            return $this->notFound('Payment');
        }

        return ApiResponse::success([
            'payment'      => FinancePresenter::paymentDetail($payment, $this->ctx->companyNames()),
            'capabilities' => $this->capabilities($this->ctx),
        ]);
    }

    /**
     * POST /{admin|user}/finance/invoices/{invoice}/payments
     *
     * Record a payment that arrived off-platform. Never a card capture —
     * nothing here talks to a gateway, and no card data is accepted or
     * stored (see the gateway note on the class).
     */
    public function record(Request $request, int $invoiceId): JsonResponse
    {
        if ($fail = $this->guard($request, self::RECORD)) {
            return $fail;
        }

        $invoice = FinanceScope::findInvoice($this->ctx, $invoiceId);
        if (!$invoice) {
            return $this->notFound('Invoice');
        }

        // Re-checked against THIS invoice's company rather than the request's
        // default — a Company Admin working in "All Companies" mode has
        // several in scope at once, and the permission is granted per company.
        if (!$this->ctx->canAny(self::RECORD, $invoice->company_id)) {
            return ApiResponse::error('Permission denied', 403);
        }

        if (in_array($invoice->status, ['paid', 'cancelled'], true)) {
            return ApiResponse::error("Invoice is already {$invoice->status}", 422);
        }

        ['min' => $min, 'max' => $max, 'requires_full' => $requiresFull]
            = InvoicePaymentService::recordableRange($invoice);

        if ($max <= 0) {
            return ApiResponse::error('This invoice has nothing outstanding.', 422);
        }

        $data = $request->validate([
            'amount'       => "required|numeric|min:{$min}|max:{$max}",
            'method'       => 'required|in:bank_transfer,cash,card,cheque,gateway',
            'payment_date' => 'nullable|date|before_or_equal:today',
            'notes'        => 'nullable|string|max:500',
            'gateway'      => 'nullable|string|max:50',
            // The gateway's own transaction reference, typed in by the person
            // reconciling a charge they can see on a statement. A reference,
            // never a credential — there is no field here, and no column on
            // `payments`, that could hold card data.
            'gateway_ref'  => 'nullable|string|max:255',
        ], $requiresFull ? [
            'amount.min' => "{$invoice->currency} " . number_format($max, 2)
                . ' is due and this invoice has to be paid in full — part payments are not accepted under the current Payment Policy.',
        ] : []);

        $payment = InvoicePaymentService::record($invoice, $data, [
            'user_id'  => $this->ctx->actorUserId(),
            'admin_id' => $this->ctx->actorAdminId(),
        ]);

        InvoicePaymentService::logPayment($invoice, $payment, $this->ctx->isAdmin() ? 'admin' : 'finance');

        FinanceAudit::log(
            $this->ctx,
            (int) $invoice->company_id,
            'finance_payment_recorded',
            'Payment',
            $payment->id,
            "Payment of {$invoice->currency} " . number_format((float) $payment->amount, 2)
                . " recorded against invoice {$invoice->invoice_number}",
            [
                'invoice_id'     => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'amount'         => (float) $payment->amount,
                'method'         => $payment->method,
                'receipt_number' => $payment->receipt_number,
            ],
        );

        return ApiResponse::success([
            'payment' => FinancePresenter::paymentRow(
                $payment->fresh(['invoice.client', 'invoice.lead', 'companyGateway', 'recordedBy']),
                $this->ctx->companyNames()
            ),
            'invoice' => [
                'status'       => $invoice->fresh()->status,
                'paid_amount'  => (float) $invoice->fresh()->paid_amount,
                'total_amount' => (float) $invoice->total_amount,
            ],
        ], 'Payment recorded', 201);
    }

    /**
     * PATCH /{admin|user}/finance/payments/{id}/reconcile
     *
     * Sign a payment off (or flag it disputed). Bookkeeping only — it never
     * touches payments.status or the invoice's balance; see
     * PaymentReconciliationService.
     */
    public function reconcile(Request $request, int $id): JsonResponse
    {
        if ($fail = $this->guard($request, 'canReconcilePayments')) {
            return $fail;
        }

        $payment = $this->loadPayment($id);
        if (!$payment) {
            return $this->notFound('Payment');
        }

        if (!$this->ctx->can('canReconcilePayments', 'finance', $payment->invoice?->company_id)) {
            return ApiResponse::error('Permission denied', 403);
        }

        $data = $request->validate([
            'status' => 'nullable|in:reconciled,disputed',
            'note'   => 'nullable|string|max:500',
        ]);

        $payment = PaymentReconciliationService::reconcile(
            $this->ctx,
            $payment,
            $data['status'] ?? 'reconciled',
            $data['note'] ?? null,
        );

        return ApiResponse::success(
            ['payment' => FinancePresenter::paymentDetail($payment->fresh($this->detailRelations()), $this->ctx->companyNames())],
            ($data['status'] ?? 'reconciled') === 'disputed' ? 'Payment marked disputed' : 'Payment reconciled'
        );
    }

    // DELETE /{admin|user}/finance/payments/{id}/reconcile — undo a sign-off.
    public function unreconcile(Request $request, int $id): JsonResponse
    {
        if ($fail = $this->guard($request, 'canReconcilePayments')) {
            return $fail;
        }

        $payment = $this->loadPayment($id);
        if (!$payment) {
            return $this->notFound('Payment');
        }

        if (!$this->ctx->can('canReconcilePayments', 'finance', $payment->invoice?->company_id)) {
            return ApiResponse::error('Permission denied', 403);
        }

        $data = $request->validate(['note' => 'nullable|string|max:500']);

        $payment = PaymentReconciliationService::unreconcile($this->ctx, $payment, $data['note'] ?? null);

        return ApiResponse::success(
            ['payment' => FinancePresenter::paymentDetail($payment->fresh($this->detailRelations()), $this->ctx->companyNames())],
            'Reconciliation removed'
        );
    }

    /**
     * GET /{admin|user}/finance/payments/export
     *
     * Same query, same filters and same presenter as index() — see the note
     * on Api\Finance\InvoiceController::export().
     */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        if ($fail = $this->guard($request, [['finance', 'canExportPaymentDetails'], ['finance', 'canExportFinanceReports']])) {
            return $fail;
        }

        $companyNames = $this->ctx->companyNames();

        $rows = FinanceScope::applyPaymentFilters(
            FinanceScope::payments($this->ctx),
            $request,
            $this->ctx
        )->with($this->detailRelations())
            ->orderByDesc('payments.payment_date')
            ->orderByDesc('payments.id')
            ->cursor();

        FinanceAudit::log(
            $this->ctx,
            $this->ctx->primaryCompanyId(),
            'finance_payments_exported',
            'Payment',
            null,
            'Finance payments exported to CSV',
            ['filters' => $request->query()],
        );

        return FinanceCsv::stream(
            FinanceCsv::filename('finance-payments'),
            [
                'Reference', 'Invoice #', 'Company', 'Client/Customer', 'Payment Date',
                'Currency', 'Amount', 'Method', 'Gateway', 'Transaction ID', 'Status',
                'Reconciliation', 'Reconciled At', 'Reconciled By', 'Recorded By',
            ],
            (function () use ($rows, $companyNames) {
                foreach ($rows as $payment) {
                    $r = FinancePresenter::paymentRow($payment, $companyNames);
                    yield [
                        $r['reference'], $r['invoice_number'], $r['company_name'], $r['customer_name'],
                        $r['payment_date'], $r['currency'],
                        number_format($r['amount'], 2, '.', ''),
                        $r['method'], $r['gateway_name'], $r['transaction_id'], $r['status'],
                        $r['reconciliation_status'], $r['reconciled_at'], $r['reconciled_by'],
                        $r['recorded_by'],
                    ];
                }
            })()
        );
    }

    private function loadPayment(int $id)
    {
        return FinanceScope::findPayment($this->ctx, $id, $this->detailRelations());
    }

    /** @return array<int, string> */
    private function detailRelations(): array
    {
        return [
            'invoice', 'invoice.client:id,name,company_name,email', 'invoice.lead:id,name',
            'invoice.project:id,name,status',
            'companyGateway:id,label,gateway',
            'recordedBy:id,name', 'reconciledBy:id,name', 'reconciledByAdmin:id,name',
        ];
    }
}
