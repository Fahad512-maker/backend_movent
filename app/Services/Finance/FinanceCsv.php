<?php

namespace App\Services\Finance;

use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The one way Finance writes a CSV.
 *
 * Same mechanism the rest of the app already exports with —
 * Response::streamDownload() + fputcsv, as in Api\User\SalesReportController
 * and Api\Admin\TimesheetController — so nothing new had to be installed and
 * the browser-side download path (blob + anchor click, see
 * lib/services/financeService.ts) is the one the Sales export already uses.
 *
 * The important part is not the format, it is WHAT gets passed in: every
 * Finance export builds its rows from the same FinanceScope query and the
 * same FinancePresenter allow-list the on-screen list used, so an export can
 * never contain a row — or a column — the caller was not already shown. An
 * export URL is not a back door: it goes through the identical permission
 * check and the identical company scope, and holding the export permission
 * never widens which records are exported, only whether a file may be
 * produced at all.
 */
class FinanceCsv
{
    /**
     * @param array<int, string>       $headers
     * @param iterable<array<int, mixed>> $rows
     */
    public static function stream(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return Response::streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');

            // BOM so Excel on Windows reads UTF-8 rather than mangling any
            // non-ASCII client name into mojibake. Harmless everywhere else.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, array_map(self::cell(...), $row));
            }

            fclose($out);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            // Stops a proxy or the browser caching one company's financial
            // export and serving it to the next request.
            'Cache-Control'       => 'no-store, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Render one value, and defuse CSV injection while doing it.
     *
     * A cell beginning =, +, - or @ is executed as a formula when the file is
     * opened in Excel or Sheets. Client names, invoice notes and
     * reconciliation notes are all free text a customer or colleague typed,
     * so any of them could carry one. Prefixing a tab makes the spreadsheet
     * treat it as text without changing what a human reads.
     */
    private static function cell(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }

        if (is_bool($value)) {
            return 'Yes';
        }

        $string = (string) $value;

        return $string !== '' && str_contains("=+-@\t\r", $string[0])
            ? "\t" . $string
            : $string;
    }

    /** A filename that names the export and the day it was taken. */
    public static function filename(string $slug): string
    {
        return sprintf('%s-%s.csv', $slug, now()->format('Y-m-d'));
    }
}
