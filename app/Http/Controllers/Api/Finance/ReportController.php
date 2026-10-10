<?php

namespace App\Http\Controllers\Api\Finance;

use App\Helpers\ApiResponse;
use App\Services\Finance\FinanceAudit;
use App\Services\Finance\FinanceCsv;
use App\Services\Finance\FinanceReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Finance > Reports — Finance, Revenue and Payments.
 *
 * Three reports, three view permissions, three export permissions, all
 * independent: a company can let someone read the revenue summary without
 * letting them read the payment-by-payment breakdown, and let them read
 * either without letting them take a copy out of the building.
 *
 * Every figure is computed by FinanceReportService from live rows reached
 * through FinanceScope. Nothing is cached, seeded or estimated, and a report
 * can never contain a company the caller does not hold — the scope filter is
 * applied before any aggregation, not after.
 */
class ReportController extends FinanceController
{
    // GET /{admin|user}/finance/reports/finance
    public function finance(Request $request): JsonResponse
    {
        if ($fail = $this->guard($request, 'canViewFinanceReports')) {
            return $fail;
        }

        return ApiResponse::success([
            'report'       => FinanceReportService::financeReport($this->ctx, $request),
            'capabilities' => $this->capabilities($this->ctx),
        ]);
    }

    // GET /{admin|user}/finance/reports/revenue
    public function revenue(Request $request): JsonResponse
    {
        if ($fail = $this->guard($request, 'canViewRevenueReports')) {
            return $fail;
        }

        return ApiResponse::success([
            'report'       => FinanceReportService::revenueReport($this->ctx, $request),
            'capabilities' => $this->capabilities($this->ctx),
        ]);
    }

    // GET /{admin|user}/finance/reports/payments
    public function payments(Request $request): JsonResponse
    {
        if ($fail = $this->guard($request, 'canViewPaymentReports')) {
            return $fail;
        }

        return ApiResponse::success([
            'report'       => FinanceReportService::paymentsReport($this->ctx, $request),
            'capabilities' => $this->capabilities($this->ctx),
        ]);
    }

    /**
     * GET /{admin|user}/finance/reports/{report}/export
     *
     * Requires BOTH the report's view permission and its export permission:
     * export is an additional right on top of reading, never a way around
     * it. Same scope and same filters as the on-screen report, so the file
     * is the report.
     */
    public function export(Request $request, string $report): StreamedResponse|JsonResponse
    {
        $keys = match ($report) {
            'finance'  => ['canViewFinanceReports', 'canExportFinanceReports'],
            'revenue'  => ['canViewRevenueReports', 'canExportRevenueReports'],
            'payments' => ['canViewPaymentReports', 'canExportPaymentReports'],
            default    => null,
        };

        if (!$keys) {
            return $this->notFound('Report');
        }

        [$viewKey, $exportKey] = $keys;

        if ($fail = $this->guard($request, $viewKey)) {
            return $fail;
        }

        if (!$this->ctx->can($exportKey)) {
            return ApiResponse::error('Permission denied', 403);
        }

        $data = match ($report) {
            'finance'  => FinanceReportService::financeReport($this->ctx, $request),
            'revenue'  => FinanceReportService::revenueReport($this->ctx, $request),
            'payments' => FinanceReportService::paymentsReport($this->ctx, $request),
        };

        FinanceAudit::log(
            $this->ctx,
            $this->ctx->primaryCompanyId(),
            'finance_report_exported',
            'Report',
            null,
            ucfirst($report) . ' report exported to CSV',
            ['report' => $report, 'filters' => $request->query()],
        );

        return FinanceCsv::stream(
            FinanceCsv::filename("finance-{$report}-report"),
            ['Section', 'Group', 'Currency', 'Metric', 'Value'],
            $this->flatten($report, $data)
        );
    }

    /**
     * Flatten a report into Section/Group/Currency/Metric/Value rows.
     *
     * A finance report is a tree of currency-grouped figures, and a CSV is a
     * grid — so rather than inventing a different column set per report (and
     * breaking whichever spreadsheet someone built on it the next time a
     * metric is added), every report exports in this one long shape. It
     * pivots cleanly and it never silently drops a figure the screen showed.
     *
     * @return iterable<array<int, mixed>>
     */
    private function flatten(string $report, array $data): iterable
    {
        $rows = [];

        $push = function (string $section, ?string $group, ?string $currency, string $metric, $value) use (&$rows) {
            $rows[] = [$section, $group ?? '', $currency ?? '', $metric, is_float($value) ? number_format($value, 2, '.', '') : $value];
        };

        // Header block: what this file actually covers, so a copy that has
        // been emailed on still states its own filters.
        $push('Meta', null, null, 'Report', ucfirst($report));
        $push('Meta', null, null, 'Generated At', $data['generated_at'] ?? now()->toIso8601String());
        foreach (($data['filters'] ?? []) as $key => $value) {
            $push('Meta', 'Filter', null, (string) $key, is_scalar($value) ? $value : json_encode($value));
        }

        if (isset($data['invoices'])) {
            foreach ($data['invoices']['counts'] ?? [] as $key => $count) {
                $push('Invoices', 'Counts', null, ucfirst(str_replace('_', ' ', $key)), $count);
            }
            foreach ($data['invoices']['by_currency'] ?? [] as $entry) {
                foreach ($entry as $metric => $value) {
                    if ($metric === 'currency') {
                        continue;
                    }
                    $push('Invoices', 'Totals', $entry['currency'], ucfirst(str_replace('_', ' ', $metric)), $value);
                }
            }
            foreach ($data['invoices']['by_status'] ?? [] as $entry) {
                foreach ($entry['by_currency'] ?? [] as $cur) {
                    $push('Invoices', 'Status: ' . $entry['status'], $cur['currency'], 'Amount', $cur['amount']);
                }
                $push('Invoices', 'Status: ' . $entry['status'], null, 'Count', $entry['count']);
            }
        }

        if (isset($data['payments'])) {
            foreach ($data['payments']['counts'] ?? [] as $key => $count) {
                $push('Payments', 'Counts', null, ucfirst(str_replace('_', ' ', $key)), $count);
            }
            foreach ($data['payments']['by_currency'] ?? [] as $entry) {
                $push('Payments', 'Received', $entry['currency'], 'Total Received', $entry['total_received'] ?? 0);
                $push('Payments', 'Received', $entry['currency'], 'Count', $entry['count'] ?? 0);
            }
            $rows = array_merge($rows, $this->breakdownRows('Payments', 'Method', $data['payments']['by_method'] ?? []));
            $rows = array_merge($rows, $this->breakdownRows('Payments', 'Gateway', $data['payments']['by_gateway'] ?? []));
        }

        foreach ($data['summary']['by_status'] ?? [] as $entry) {
            $push('Payment Status', $entry['status'], null, 'Count', $entry['count']);
            foreach ($entry['by_currency'] ?? [] as $cur) {
                $push('Payment Status', $entry['status'], $cur['currency'], 'Amount', $cur['amount']);
            }
        }
        if (isset($data['summary']['total_count'])) {
            $push('Payment Status', null, null, 'Total Attempts', $data['summary']['total_count']);
        }

        // Revenue report's own summary block — same key name, different
        // shape (a list, not a map), so it is matched on structure.
        if (isset($data['summary']) && array_is_list($data['summary'])) {
            foreach ($data['summary'] as $entry) {
                foreach (['revenue', 'invoiced', 'collection_rate', 'payment_count'] as $metric) {
                    if (array_key_exists($metric, $entry)) {
                        $push('Revenue', 'Summary', $entry['currency'], ucfirst(str_replace('_', ' ', $metric)), $entry[$metric]);
                    }
                }
            }
        }

        if (isset($data['reconciliation'])) {
            foreach (['reconciled', 'unreconciled', 'disputed'] as $key) {
                $push('Reconciliation', null, null, ucfirst($key), $data['reconciliation'][$key] ?? 0);
            }
            foreach ($data['reconciliation']['pending_amount_by_currency'] ?? [] as $entry) {
                $push('Reconciliation', 'Awaiting sign-off', $entry['currency'], 'Amount', $entry['amount']);
            }
        }

        if (isset($data['by_method'])) {
            $rows = array_merge($rows, $this->breakdownRows('Breakdown', 'Method', $data['by_method']));
        }
        if (isset($data['by_gateway'])) {
            $rows = array_merge($rows, $this->breakdownRows('Breakdown', 'Gateway', $data['by_gateway']));
        }

        foreach ($data['by_client'] ?? [] as $client) {
            foreach ($client['by_currency'] ?? [] as $cur) {
                $push('Revenue by Client', $client['name'], $cur['currency'], 'Revenue', $cur['revenue'] ?? 0);
            }
            $push('Revenue by Client', $client['name'], null, 'Payments', $client['count']);
        }

        foreach ($data['top_clients'] ?? [] as $client) {
            foreach ($client['by_currency'] ?? [] as $cur) {
                $push('Top Clients', $client['name'], $cur['currency'], 'Invoiced', $cur['invoiced'] ?? 0);
                $push('Top Clients', $client['name'], $cur['currency'], 'Paid', $cur['paid'] ?? 0);
                $push('Top Clients', $client['name'], $cur['currency'], 'Outstanding', $cur['outstanding'] ?? 0);
            }
        }

        foreach ($data['monthly']['months'] ?? [] as $month) {
            foreach ($month['by_currency'] as $cur) {
                $label = sprintf('%s %d', date('M', mktime(0, 0, 0, $month['month'], 1)), $data['monthly']['year'] ?? now()->year);
                $push('Monthly', $label, $cur['currency'], 'Invoiced', $cur['invoiced']);
                $push('Monthly', $label, $cur['currency'], 'Received', $cur['received']);
            }
        }

        return $rows;
    }

    /** @return array<int, array<int, mixed>> */
    private function breakdownRows(string $section, string $group, array $entries): array
    {
        $rows = [];
        foreach ($entries as $entry) {
            $label = $group . ': ' . $entry['key'];
            $rows[] = [$section, $label, '', 'Count', $entry['count']];
            foreach ($entry['by_currency'] ?? [] as $cur) {
                $rows[] = [$section, $label, $cur['currency'], 'Amount', number_format((float) ($cur['amount'] ?? 0), 2, '.', '')];
            }
        }

        return $rows;
    }
}
