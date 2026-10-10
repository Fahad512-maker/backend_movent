<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Services\PaymentReceiptService;
use Illuminate\Console\Command;

/**
 * Issue receipts for payments that were confirmed before receipts existed.
 *
 * DELIBERATELY OPT-IN, not a migration. Every receipt it writes becomes a new
 * document in that client's portal, so running it against a large history hands
 * every client a pile of files they did not ask for on the day it runs — that is
 * a decision for whoever operates the tenant, not something a deploy should do
 * on its own. New payments need none of this: they are receipted as they settle.
 *
 * Nothing here is destructive. A payment that already has a receipt is skipped
 * (the payment_id unique key would refuse a second anyway), and --dry-run
 * reports what would be issued without writing a file or a row.
 */
class BackfillPaymentReceipts extends Command
{
    protected $signature = 'receipts:backfill
        {--company= : Only this company id}
        {--since= : Only payments created on or after this date (Y-m-d)}
        {--limit=0 : Stop after this many receipts (0 = no limit)}
        {--regenerate : Re-render receipts that already exist, in place, instead of issuing missing ones}
        {--dry-run : Report what would be issued and write nothing}';

    protected $description = 'Generate payment receipts for already-confirmed payments that have none';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        if ($this->option('regenerate')) {
            return $this->regenerate($dry, $limit);
        }

        $query = Payment::where('status', 'confirmed')
            // Only payments whose invoice is linked to a Client: a receipt is
            // filed as that client's document, so an invoice raised against
            // one-off customer details has nowhere to file one.
            ->whereHas('invoice', function ($q) {
                $q->whereNotNull('client_id');

                if ($companyId = $this->option('company')) {
                    $q->where('company_id', (int) $companyId);
                }
            })
            ->whereNotIn('id', PaymentReceipt::query()->select('payment_id'))
            ->with('invoice.company', 'invoice.client', 'invoice.brand')
            ->orderBy('id');

        if ($since = $this->option('since')) {
            $query->whereDate('created_at', '>=', $since);
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('Nothing to backfill — every confirmed payment already has a receipt.');

            return self::SUCCESS;
        }

        $this->info(($dry ? '[dry run] ' : '') . "{$total} confirmed payment(s) without a receipt.");

        $issued = 0;
        $failed = 0;

        $query->chunkById(100, function ($payments) use (&$issued, &$failed, $dry, $limit) {
            foreach ($payments as $payment) {
                if ($limit > 0 && $issued >= $limit) {
                    return false;
                }

                $invoice = $payment->invoice;

                if ($dry) {
                    $this->line("  would issue: payment {$payment->id} · invoice #{$invoice->invoice_number}");
                    $issued++;
                    continue;
                }

                // generateQuietly(), not generateFor(): one unreadable logo or
                // one unwritable path must not abandon the rest of the run.
                if (PaymentReceiptService::generateQuietly($invoice, $payment)) {
                    $issued++;
                } else {
                    $failed++;
                    $this->warn("  skipped: payment {$payment->id} (see the log)");
                }
            }

            return true;
        });

        $this->info(($dry ? '[dry run] ' : '') . "Done — {$issued} issued" . ($failed ? ", {$failed} skipped" : '') . '.');

        return self::SUCCESS;
    }

    /**
     * Re-render existing receipts against the current template.
     *
     * In place: the same file path, the same document, the same row — so a
     * design change reaches receipts already issued without a single new
     * document appearing in anyone's portal.
     */
    private function regenerate(bool $dry, int $limit): int
    {
        $query = PaymentReceipt::with('invoice.company', 'invoice.client', 'invoice.brand', 'payment')
            ->orderBy('id');

        if ($companyId = $this->option('company')) {
            $query->where('company_id', (int) $companyId);
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No receipts to regenerate.');

            return self::SUCCESS;
        }

        $this->info(($dry ? '[dry run] ' : '') . "Re-rendering {$total} receipt(s) in place.");

        $done = 0;
        $failed = 0;

        foreach ($query->cursor() as $receipt) {
            if ($limit > 0 && $done >= $limit) {
                break;
            }

            if ($dry) {
                $this->line("  would re-render: receipt {$receipt->id} · " . ($receipt->invoice->invoice_number ?? '?'));
                $done++;
                continue;
            }

            if (PaymentReceiptService::rerender($receipt)) {
                $done++;
            } else {
                $failed++;
                $this->warn("  skipped: receipt {$receipt->id} (see the log)");
            }
        }

        $this->info(($dry ? '[dry run] ' : '') . "Done — {$done} re-rendered" . ($failed ? ", {$failed} skipped" : '') . '.');

        return self::SUCCESS;
    }
}
