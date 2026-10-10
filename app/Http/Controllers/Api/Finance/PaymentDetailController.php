<?php

namespace App\Http\Controllers\Api\Finance;

use App\Helpers\ApiResponse;
use App\Services\Finance\FinanceAudit;
use App\Services\Finance\FinanceCsv;
use App\Services\Finance\FinancePresenter;
use App\Services\Finance\FinanceScope;
use App\Services\Finance\PaymentReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Finance > Payment Details — the full record behind each payment, and the
 * screen reconciliation is actually done from.
 *
 * Why it is a separate section from Finance > Payments rather than a drawer
 * on it: they answer different questions and are granted separately.
 * Payments is the operational list (what came in, did it clear, record a new
 * one). Payment Details is the audit surface — every field the payment
 * carries, the invoice it is being checked against, the project/lead/client
 * behind it, and who signed it off when. Someone can be trusted with one and
 * not the other, which is the whole reason canViewPaymentDetails,
 * canReconcilePaymentDetails and canExportPaymentDetails exist alongside the
 * Payments keys.
 *
 * Same rows, same FinanceScope, same FinancePresenter allow-list — so no
 * field reaches this screen that the payments list could not also show, and
 * in particular nothing card- or credential-shaped reaches either. See the
 * note on FinancePresenter for exactly what is excluded and why there is
 * nothing to exclude in the first place (every gateway here is hosted
 * checkout; no PAN, expiry or CVV ever touches this server).
 */
class PaymentDetailController extends FinanceController
{
    // GET /{admin|user}/finance/payment-details
    public function index(Request $request): JsonResponse
    {
        if ($fail = $this->guard($request, 'canViewPaymentDetails')) {
            return $fail;
        }

        $companyNames = $this->ctx->companyNames();

        $query = FinanceScope::applyPaymentFilters(
            FinanceScope::payments($this->ctx),
            $request,
            $this->ctx
        )->with($this->relations())
            ->orderByDesc('payments.payment_date')
            ->orderByDesc('payments.id');

        $perPage = min(max((int) $request->input('per_page', 25), 1), 200);
        $page    = $query->paginate($perPage);

        return ApiResponse::success([
            'payments' => collect($page->items())
                ->map(fn ($p) => FinancePresenter::paymentDetail($p, $companyNames))
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

    // GET /{admin|user}/finance/payment-details/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        if ($fail = $this->guard($request, 'canViewPaymentDetails')) {
            return $fail;
        }

        $payment = FinanceScope::findPayment($this->ctx, $id, $this->relations());
        if (!$payment) {
            return $this->notFound('Payment');
        }

        return ApiResponse::success([
            'payment'      => FinancePresenter::paymentDetail($payment, $this->ctx->companyNames()),
            'capabilities' => $this->capabilities($this->ctx),
        ]);
    }

    /**
     * PATCH /{admin|user}/finance/payment-details/{id}/reconcile
     *
     * The narrower reconcile grant: canReconcilePaymentDetails signs off the
     * record you are looking at. canReconcilePayments — the wider Payments
     * grant — also satisfies it, since anyone trusted to reconcile from the
     * list is certainly trusted to reconcile one open record.
     */
    public function reconcile(Request $request, int $id): JsonResponse
    {
        $required = [
            ['finance', 'canReconcilePaymentDetails'],
            ['finance', 'canReconcilePayments'],
        ];

        if ($fail = $this->guard($request, $required)) {
            return $fail;
        }

        $payment = FinanceScope::findPayment($this->ctx, $id, $this->relations());
        if (!$payment) {
            return $this->notFound('Payment');
        }

        // Re-checked against the record's OWN company — matters for a Company
        // Admin in "All Companies" mode, where more than one is in scope.
        if (!$this->ctx->canAny($required, $payment->invoice?->company_id)) {
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
            ['payment' => FinancePresenter::paymentDetail($payment->fresh($this->relations()), $this->ctx->companyNames())],
            ($data['status'] ?? 'reconciled') === 'disputed' ? 'Payment marked disputed' : 'Payment reconciled'
        );
    }

    /**
     * GET /{admin|user}/finance/payment-details/export
     *
     * The full-detail export. Identical scope and filters to index(), so it
     * can only ever contain rows the caller was already shown, and identical
     * field selection, so it can only ever contain columns they were already
     * shown.
     */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        if ($fail = $this->guard($request, 'canExportPaymentDetails')) {
            return $fail;
        }

        $companyNames = $this->ctx->companyNames();

        $rows = FinanceScope::applyPaymentFilters(
            FinanceScope::payments($this->ctx),
            $request,
            $this->ctx
        )->with($this->relations())
            ->orderByDesc('payments.payment_date')
            ->orderByDesc('payments.id')
            ->cursor();

        FinanceAudit::log(
            $this->ctx,
            $this->ctx->primaryCompanyId(),
            'finance_payment_details_exported',
            'Payment',
            null,
            'Finance payment details exported to CSV',
            ['filters' => $request->query()],
        );

        return FinanceCsv::stream(
            FinanceCsv::filename('finance-payment-details'),
            [
                'Reference', 'Invoice #', 'Client/Customer', 'Company', 'Payment Date',
                'Currency', 'Amount', 'Method', 'Gateway', 'Transaction ID', 'Status',
                'Invoice Total', 'Invoice Paid', 'Invoice Outstanding',
                'Reconciliation', 'Reconciled At', 'Reconciled By', 'Recorded By',
                'Related Project', 'Related Lead',
            ],
            (function () use ($rows, $companyNames) {
                foreach ($rows as $payment) {
                    $r = FinancePresenter::paymentDetail($payment, $companyNames);
                    yield [
                        $r['reference'], $r['invoice_number'], $r['customer_name'], $r['company_name'],
                        $r['payment_date'], $r['currency'],
                        number_format($r['amount'], 2, '.', ''),
                        $r['method'], $r['gateway_name'], $r['transaction_id'], $r['status'],
                        $r['invoice'] ? number_format($r['invoice']['total_amount'], 2, '.', '') : '',
                        $r['invoice'] ? number_format($r['invoice']['paid_amount'], 2, '.', '') : '',
                        $r['invoice'] ? number_format($r['invoice']['outstanding_amount'], 2, '.', '') : '',
                        $r['reconciliation_status'], $r['reconciled_at'], $r['reconciled_by'],
                        $r['recorded_by'],
                        $r['related']['project']['name'] ?? '',
                        $r['related']['lead']['name'] ?? '',
                    ];
                }
            })()
        );
    }

    /** @return array<int, string> */
    private function relations(): array
    {
        return [
            'invoice', 'invoice.client:id,name,company_name,email', 'invoice.lead:id,name',
            'invoice.project:id,name,status',
            'companyGateway:id,label,gateway',
            'recordedBy:id,name', 'reconciledBy:id,name', 'reconciledByAdmin:id,name',
        ];
    }
}
