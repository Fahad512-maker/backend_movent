<?php

namespace App\Services\Finance;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Every number the Finance Dashboard and the three Finance reports show.
 *
 * All of it is read from `invoices` and `payments` through FinanceScope, so
 * a figure can only ever be built from rows the caller is already allowed to
 * see. There is no seeded, sampled or placeholder value anywhere in this
 * class: an empty company returns zeros and empty lists, which is the honest
 * answer, not a demo dataset.
 *
 * Two conventions inherited from the rest of the app rather than invented
 * here, because a Finance figure that disagreed with the invoice list would
 * be worse than no figure at all:
 *
 *   MONEY IS GROUPED BY CURRENCY, NEVER SUMMED ACROSS IT. A company can hold
 *   invoices in more than one currency (Settings' currency has changed over
 *   time, and older rows keep theirs), and adding 100 USD to 100 PKR
 *   produces a number that means nothing. Same rule
 *   Api\Admin\PaymentController::index() and Api\User\InvoiceController::
 *   report() already follow. Counts, being unitless, are reported flat.
 *
 *   CANCELLED INVOICES ARE VOID. They are excluded from every money rollup
 *   and still counted in the status breakdown — again matching the existing
 *   invoice report exactly.
 *
 * "Revenue" throughout means CONFIRMED payments — money actually received —
 * never invoiced-but-unpaid amounts. A pending claim and a failed charge are
 * reported, but never as revenue.
 */
class FinanceReportService
{
    /** Payment statuses that represent money genuinely in the bank. */
    public const RECEIVED_STATUSES = ['confirmed'];

    // ── Dashboard ───────────────────────────────────────────────────────────

    /**
     * Finance > Dashboard. One call, because the screen is a single view of
     * one company's position and splitting it into six endpoints would just
     * mean six round trips that can disagree with each other.
     */
    public static function dashboard(FinanceContext $ctx, Request $request): array
    {
        $companyNames = $ctx->companyNames();

        $invoices = FinanceScope::applyInvoiceFilters(FinanceScope::invoices($ctx), $request, $ctx);
        $payments = FinanceScope::applyPaymentFilters(FinanceScope::payments($ctx), $request, $ctx);

        // Pulled once and reduced in PHP rather than issued as a dozen
        // aggregate queries: the same rows back every tile, and one pass
        // keeps them consistent with each other even if a payment lands
        // mid-request.
        $invoiceRows = (clone $invoices)->get([
            'id', 'company_id', 'invoice_number', 'status', 'currency',
            'total_amount', 'paid_amount', 'due_date', 'created_at',
        ]);

        return [
            'overview'        => self::invoiceOverview($invoiceRows),
            'payments'        => self::paymentOverview(clone $payments),
            'revenue_trend'   => self::monthlyTrend($ctx, $request),
            'recent_invoices' => self::recentInvoices($ctx, $request, $companyNames),
            'recent_payments' => self::recentPayments($ctx, $request, $companyNames),
            'top_clients'     => self::topClients(clone $invoices),
            'currencies'      => $invoiceRows->pluck('currency')->filter()->unique()->values()->all(),
            'companies'       => collect($companyNames)->map(fn ($name, $id) => [
                'id' => (int) $id, 'name' => $name,
            ])->values()->all(),
            'generated_at'    => now()->toIso8601String(),
        ];
    }

    /**
     * Invoice-side headline figures: how much was billed, how much of it came
     * in, and what is still owed or late.
     *
     * @param Collection $rows invoices, already scoped and filtered
     */
    private static function invoiceOverview(Collection $rows): array
    {
        $live = $rows->whereNotIn('status', FinanceScope::VOID_STATUSES);

        $overdue = $live->filter(fn ($i) => $i->due_date
            && $i->due_date->endOfDay()->isPast()
            && (float) $i->paid_amount < (float) $i->total_amount - 0.005
            && $i->status !== 'draft');

        $paid    = $live->filter(fn ($i) => (float) $i->total_amount > 0
            && (float) $i->paid_amount >= (float) $i->total_amount - 0.005);

        // "Pending" = sent and awaiting money, whole or partial. A draft has
        // not been sent to anyone, so it is counted separately rather than
        // inflating what the company is waiting on.
        $pending = $live->filter(fn ($i) => $i->status !== 'draft'
            && (float) $i->paid_amount < (float) $i->total_amount - 0.005);

        return [
            'counts' => [
                'total'     => $rows->count(),
                'draft'     => $rows->where('status', 'draft')->count(),
                'paid'      => $paid->count(),
                'pending'   => $pending->count(),
                'overdue'   => $overdue->count(),
                'cancelled' => $rows->whereIn('status', FinanceScope::VOID_STATUSES)->count(),
            ],
            'by_currency' => self::byCurrency($live, fn (Collection $g) => [
                'total_invoiced'    => self::sum($g, 'total_amount'),
                'total_paid'        => self::sum($g, 'paid_amount'),
                'total_outstanding' => round($g->sum(fn ($i) => max(0, (float) $i->total_amount - (float) $i->paid_amount)), 2),
                'overdue_amount'    => round(
                    $g->filter(fn ($i) => $i->due_date
                        && $i->due_date->endOfDay()->isPast()
                        && (float) $i->paid_amount < (float) $i->total_amount - 0.005
                        && $i->status !== 'draft')
                        ->sum(fn ($i) => max(0, (float) $i->total_amount - (float) $i->paid_amount)),
                    2
                ),
                'invoice_count'     => $g->count(),
            ]),
            'by_status' => $rows->groupBy('status')->map(fn (Collection $g, $status) => [
                'status' => $status,
                'count'  => $g->count(),
                'by_currency' => self::byCurrency($g, fn (Collection $c) => [
                    'amount' => self::sum($c, 'total_amount'),
                ]),
            ])->values()->all(),
        ];
    }

    /** Payment-side headline figures, split by what each attempt actually did. */
    private static function paymentOverview(Builder $payments): array
    {
        $rows = $payments->with('invoice:id,currency')
            ->get(['id', 'invoice_id', 'amount', 'currency', 'status', 'method', 'gateway', 'reconciliation_status', 'payment_date']);

        $received = $rows->whereIn('status', self::RECEIVED_STATUSES);

        return [
            'counts' => [
                'total'        => $rows->count(),
                'confirmed'    => $rows->where('status', 'confirmed')->count(),
                'pending'      => $rows->where('status', 'pending')->count(),
                'failed'       => $rows->where('status', 'failed')->count(),
                'refunded'     => $rows->where('status', 'refunded')->count(),
                'reconciled'   => $rows->where('reconciliation_status', 'reconciled')->count(),
                'unreconciled' => $rows->filter(fn ($p) => ($p->reconciliation_status ?? 'unreconciled') === 'unreconciled')->count(),
                'disputed'     => $rows->where('reconciliation_status', 'disputed')->count(),
            ],
            // Received money only. A pending claim is not revenue and a
            // failed charge never was.
            'by_currency' => self::byCurrency($received, fn (Collection $g) => [
                'total_received' => self::sum($g, 'amount'),
                'count'          => $g->count(),
            ], 'invoice'),
            'by_method'  => self::breakdown($received, fn ($p) => $p->method ?? 'unspecified'),
            'by_gateway' => self::breakdown(
                $received->where('method', 'gateway'),
                fn ($p) => $p->gateway ?? 'unspecified'
            ),
        ];
    }

    /**
     * Twelve months of invoiced-vs-received, for the dashboard chart and the
     * revenue report.
     *
     * Invoiced is read off the invoice's issue date, received off the
     * payment's own payment_date — they are different events and a payment
     * routinely lands in a later month than the invoice it settles.
     */
    public static function monthlyTrend(FinanceContext $ctx, Request $request, ?int $year = null): array
    {
        $year ??= (int) ($request->year ?: now()->year);

        $invoiced = FinanceScope::applyInvoiceFilters(FinanceScope::invoices($ctx), $request, $ctx)
            ->whereNotIn('invoices.status', FinanceScope::VOID_STATUSES)
            ->whereYear('invoices.created_at', $year)
            ->get(['currency', 'total_amount', 'created_at']);

        $received = FinanceScope::applyPaymentFilters(FinanceScope::payments($ctx), $request, $ctx)
            ->whereIn('payments.status', self::RECEIVED_STATUSES)
            ->whereYear('payments.payment_date', $year)
            ->with('invoice:id,currency')
            ->get(['id', 'invoice_id', 'amount', 'currency', 'payment_date']);

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = [];
        }

        foreach ($invoiced as $inv) {
            $m   = (int) $inv->created_at->format('n');
            $cur = $inv->currency ?: 'USD';
            $months[$m][$cur] ??= ['currency' => $cur, 'invoiced' => 0.0, 'received' => 0.0];
            $months[$m][$cur]['invoiced'] += (float) $inv->total_amount;
        }

        foreach ($received as $pay) {
            if (!$pay->payment_date) {
                continue;
            }
            $m   = (int) $pay->payment_date->format('n');
            $cur = $pay->currency ?: ($pay->invoice?->currency ?: 'USD');
            $months[$m][$cur] ??= ['currency' => $cur, 'invoiced' => 0.0, 'received' => 0.0];
            $months[$m][$cur]['received'] += (float) $pay->amount;
        }

        return [
            'year'   => $year,
            'months' => collect($months)->map(fn ($byCurrency, $month) => [
                'month'       => (int) $month,
                'by_currency' => collect($byCurrency)->map(fn ($v) => [
                    'currency' => $v['currency'],
                    'invoiced' => round($v['invoiced'], 2),
                    'received' => round($v['received'], 2),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    private static function recentInvoices(FinanceContext $ctx, Request $request, array $companyNames): array
    {
        return FinanceScope::applyInvoiceFilters(FinanceScope::invoices($ctx), $request, $ctx)
            ->with(['client:id,name,company_name', 'lead:id,name', 'createdBy:id,name', 'payments:id,invoice_id,status,created_at'])
            ->latest('invoices.created_at')
            ->limit(8)
            ->get()
            ->map(fn ($i) => FinancePresenter::invoiceRow($i, $companyNames))
            ->all();
    }

    private static function recentPayments(FinanceContext $ctx, Request $request, array $companyNames): array
    {
        return FinanceScope::applyPaymentFilters(FinanceScope::payments($ctx), $request, $ctx)
            ->with([
                'invoice:id,company_id,invoice_number,client_id,lead_id,currency,customer_name',
                'invoice.client:id,name,company_name',
                'invoice.lead:id,name',
                'companyGateway:id,label,gateway',
                'recordedBy:id,name',
                'reconciledBy:id,name',
                'reconciledByAdmin:id,name',
            ])
            ->orderByDesc('payments.payment_date')
            ->orderByDesc('payments.id')
            ->limit(8)
            ->get()
            ->map(fn ($p) => FinancePresenter::paymentRow($p, $companyNames))
            ->all();
    }

    /**
     * Who owes the most, ranked by outstanding balance within each currency.
     *
     * Only invoices with a real client_id — a guest/lead-only invoice has no
     * client record to group under, and inventing one from customer_name
     * would merge unrelated customers who happen to share a name.
     */
    private static function topClients(Builder $invoices): array
    {
        return $invoices
            ->whereNotNull('invoices.client_id')
            ->whereNotIn('invoices.status', FinanceScope::VOID_STATUSES)
            ->with('client:id,name,company_name')
            ->get(['id', 'client_id', 'currency', 'total_amount', 'paid_amount'])
            ->groupBy('client_id')
            ->map(fn (Collection $g) => [
                'client_id'   => (int) $g->first()->client_id,
                'name'        => $g->first()->client?->name ?? 'Unknown',
                'company'     => $g->first()->client?->company_name,
                'count'       => $g->count(),
                'by_currency' => self::byCurrency($g, fn (Collection $c) => [
                    'invoiced'    => self::sum($c, 'total_amount'),
                    'paid'        => self::sum($c, 'paid_amount'),
                    'outstanding' => round($c->sum(fn ($i) => max(0, (float) $i->total_amount - (float) $i->paid_amount)), 2),
                ]),
            ])
            // Ranked by invoice count, not amount: a client's invoices can
            // span currencies and there is no single total to sort by
            // without blending units. Same choice the existing invoice
            // report already made.
            ->sortByDesc('count')
            ->values()
            ->take(10)
            ->all();
    }

    // ── Reports ─────────────────────────────────────────────────────────────

    /** Finance report — the whole position: billed, collected, owed, late. */
    public static function financeReport(FinanceContext $ctx, Request $request): array
    {
        $invoices = FinanceScope::applyInvoiceFilters(FinanceScope::invoices($ctx), $request, $ctx);

        $rows = (clone $invoices)->get([
            'id', 'company_id', 'status', 'currency', 'total_amount', 'paid_amount', 'due_date', 'created_at',
        ]);

        return [
            'invoices'      => self::invoiceOverview($rows),
            'payments'      => self::paymentOverview(
                FinanceScope::applyPaymentFilters(FinanceScope::payments($ctx), $request, $ctx)
            ),
            'top_clients'   => self::topClients(clone $invoices),
            'monthly'       => self::monthlyTrend($ctx, $request),
            'filters'       => self::echoFilters($request),
            'generated_at'  => now()->toIso8601String(),
        ];
    }

    /**
     * Revenue report — money actually received, by month, client and
     * method, plus a collection rate against what was billed.
     */
    public static function revenueReport(FinanceContext $ctx, Request $request): array
    {
        $received = FinanceScope::applyPaymentFilters(FinanceScope::payments($ctx), $request, $ctx)
            ->whereIn('payments.status', self::RECEIVED_STATUSES)
            ->with([
                'invoice:id,company_id,currency,client_id,total_amount,paid_amount,status',
                'invoice.client:id,name,company_name',
            ])
            ->get(['id', 'invoice_id', 'amount', 'currency', 'method', 'gateway', 'payment_date']);

        $invoiced = FinanceScope::applyInvoiceFilters(FinanceScope::invoices($ctx), $request, $ctx)
            ->whereNotIn('invoices.status', FinanceScope::VOID_STATUSES)
            ->get(['currency', 'total_amount']);

        $invoicedByCurrency = $invoiced->groupBy(fn ($i) => $i->currency ?: 'USD')
            ->map(fn (Collection $g) => self::sum($g, 'total_amount'));

        return [
            'summary' => self::byCurrency($received, function (Collection $g) use ($invoicedByCurrency) {
                $currency = $g->first()->currency ?: ($g->first()->invoice?->currency ?: 'USD');
                $billed   = (float) ($invoicedByCurrency[$currency] ?? 0);
                $revenue  = self::sum($g, 'amount');

                return [
                    'revenue'         => $revenue,
                    'invoiced'        => $billed,
                    // What share of everything billed has actually come in.
                    // 0 rather than a division by zero when nothing was billed.
                    'collection_rate' => $billed > 0 ? round($revenue / $billed * 100, 1) : 0.0,
                    'payment_count'   => $g->count(),
                ];
            }, 'invoice'),
            'monthly'     => self::monthlyTrend($ctx, $request),
            'by_method'   => self::breakdown($received, fn ($p) => $p->method ?? 'unspecified'),
            'by_gateway'  => self::breakdown($received->where('method', 'gateway'), fn ($p) => $p->gateway ?? 'unspecified'),
            'by_client'   => $received
                ->filter(fn ($p) => $p->invoice?->client_id)
                ->groupBy(fn ($p) => $p->invoice->client_id)
                ->map(fn (Collection $g) => [
                    'client_id'   => (int) $g->first()->invoice->client_id,
                    'name'        => $g->first()->invoice->client?->name ?? 'Unknown',
                    'company'     => $g->first()->invoice->client?->company_name,
                    'count'       => $g->count(),
                    'by_currency' => self::byCurrency($g, fn (Collection $c) => [
                        'revenue' => self::sum($c, 'amount'),
                    ], 'invoice'),
                ])
                ->sortByDesc('count')
                ->values()
                ->take(15)
                ->all(),
            'filters'      => self::echoFilters($request),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Payments report — every attempt, however it ended, plus the
     * gateway/method breakdown and the reconciliation backlog.
     */
    public static function paymentsReport(FinanceContext $ctx, Request $request): array
    {
        $rows = FinanceScope::applyPaymentFilters(FinanceScope::payments($ctx), $request, $ctx)
            ->with('invoice:id,company_id,currency')
            ->get(['id', 'invoice_id', 'amount', 'currency', 'status', 'method', 'gateway', 'reconciliation_status', 'payment_date']);

        $statuses = ['confirmed', 'pending', 'failed', 'refunded'];

        return [
            'summary' => [
                'total_count' => $rows->count(),
                'by_status'   => collect($statuses)->map(fn ($status) => [
                    'status'      => $status,
                    'count'       => $rows->where('status', $status)->count(),
                    'by_currency' => self::byCurrency($rows->where('status', $status), fn (Collection $g) => [
                        'amount' => self::sum($g, 'amount'),
                    ], 'invoice'),
                ])->all(),
            ],
            'reconciliation' => [
                'reconciled'   => $rows->where('reconciliation_status', 'reconciled')->count(),
                'unreconciled' => $rows->filter(fn ($p) => ($p->reconciliation_status ?? 'unreconciled') === 'unreconciled')->count(),
                'disputed'     => $rows->where('reconciliation_status', 'disputed')->count(),
                // The backlog that matters: money received but not yet
                // signed off.
                'pending_amount_by_currency' => self::byCurrency(
                    $rows->whereIn('status', self::RECEIVED_STATUSES)
                        ->filter(fn ($p) => ($p->reconciliation_status ?? 'unreconciled') === 'unreconciled'),
                    fn (Collection $g) => ['amount' => self::sum($g, 'amount')],
                    'invoice'
                ),
            ],
            'by_method'  => self::breakdown($rows->whereIn('status', self::RECEIVED_STATUSES), fn ($p) => $p->method ?? 'unspecified'),
            'by_gateway' => self::breakdown(
                $rows->whereIn('status', self::RECEIVED_STATUSES)->where('method', 'gateway'),
                fn ($p) => $p->gateway ?? 'unspecified'
            ),
            'monthly'      => self::monthlyTrend($ctx, $request),
            'filters'      => self::echoFilters($request),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    // ── Shared reducers ─────────────────────────────────────────────────────

    /**
     * Group a collection by currency and reduce each group.
     *
     * $fallbackRelation names a relation to read the currency off when the
     * row's own currency column is null — 'invoice' for payments recorded
     * before payments.currency existed. Without it those rows would all land
     * in one bogus 'USD' bucket.
     *
     * @param callable(Collection): array $reduce
     */
    private static function byCurrency(Collection $rows, callable $reduce, ?string $fallbackRelation = null): array
    {
        return $rows
            ->groupBy(fn ($row) => $row->currency
                ?: ($fallbackRelation ? ($row->{$fallbackRelation}?->currency ?: 'USD') : 'USD'))
            ->map(fn (Collection $g, $currency) => array_merge(['currency' => (string) $currency], $reduce($g)))
            ->values()
            ->all();
    }

    /**
     * Count + amount per key (payment method, gateway), grouped by currency
     * inside each key so the same no-blending rule holds one level down.
     *
     * @param callable($row): string $keyBy
     */
    private static function breakdown(Collection $rows, callable $keyBy): array
    {
        return $rows->groupBy($keyBy)
            ->map(fn (Collection $g, $key) => [
                'key'         => (string) $key,
                'count'       => $g->count(),
                'by_currency' => self::byCurrency($g, fn (Collection $c) => [
                    'amount' => self::sum($c, 'amount'),
                ], 'invoice'),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    private static function sum(Collection $rows, string $column): float
    {
        return round($rows->sum(fn ($row) => (float) $row->{$column}), 2);
    }

    /**
     * The filters that produced this report, echoed back so the UI header
     * and the exported file can both state what the reader is looking at.
     */
    private static function echoFilters(Request $request): array
    {
        return array_filter([
            'company_id'  => $request->filled('company_id') ? (int) $request->company_id : null,
            'from'        => $request->from,
            'to'          => $request->to,
            'status'      => $request->status,
            'currency'    => $request->currency,
            'client_id'   => $request->filled('client_id') ? (int) $request->client_id : null,
            'year'        => $request->filled('year') ? (int) $request->year : null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
