<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Notification;
use App\Services\EventMailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MarkOverdueInvoices extends Command
{
    protected $signature = 'invoices:mark-overdue';

    protected $description = 'Flip sent/partially-paid invoices past their due date to overdue, and notify';

    public function handle(): int
    {
        // Collected BEFORE the update, so the notification loop below knows
        // exactly which invoices flipped on this run — a bulk update()
        // returns only a row count, and re-querying for status 'overdue'
        // afterwards would also pick up every invoice flipped on an earlier
        // run and re-notify it.
        // Skips a suspended/deactivated company (Company::isOperational()) —
        // this both flips a status AND sends a reminder email, so it counts
        // as a "scheduled business action" that must stop for a suspended
        // company the same as invoices:send-due-reminders does.
        $invoices = Invoice::whereIn('status', ['sent', 'partially_paid'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->whereHas('company', fn ($q) => $q->operational())
            ->with(['client:id,name,user_id,email', 'project:id,name'])
            ->get();

        $count = Invoice::whereIn('id', $invoices->pluck('id'))->update(['status' => 'overdue']);

        $notified = 0;

        foreach ($invoices as $invoice) {
            try {
                // Client Portal bell — only when there's a portal login to
                // write to; email below is the channel that always works.
                if ($invoice->client?->user_id) {
                    Notification::create([
                        'user_id'     => $invoice->client->user_id,
                        'company_id'  => $invoice->company_id,
                        'type'        => 'invoice_overdue',
                        'title'       => "Invoice {$invoice->invoice_number} is overdue",
                        'body'        => $invoice->currency . ' ' . number_format((float) $invoice->total_amount - (float) $invoice->paid_amount, 2)
                            . ' outstanding, due ' . $invoice->due_date->format('d M Y') . '.',
                        'entity_type' => 'Invoice',
                        'entity_id'   => $invoice->id,
                        'data'        => [
                            'invoice_id' => $invoice->id,
                            'link'       => "/client/invoices/{$invoice->id}",
                        ],
                    ]);
                }

                // The client (or bare customer_email for a guest/lead-only
                // invoice) plus every Company Admin — chasing an overdue
                // invoice is the company's job, not just the client's.
                $recipients = EventMailService::companyAdminRecipients($invoice->company_id);

                $clientEmail = $invoice->client?->email ?? $invoice->customer_email;
                if ($clientEmail) {
                    $recipients[] = [
                        'email' => $clientEmail,
                        'name'  => $invoice->client?->name ?? $invoice->customer_name,
                        'path'  => "/client/invoices/{$invoice->id}",
                    ];
                }

                EventMailService::invoiceOverdue($invoice, $recipients);
                $notified++;
            } catch (\Throwable $e) {
                Log::warning('[invoices:mark-overdue] notify failed for invoice ' . $invoice->id . ': ' . $e->getMessage());
            }
        }

        $this->info("Marked {$count} invoice(s) as overdue, notified {$notified}.");

        return self::SUCCESS;
    }
}
