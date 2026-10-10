<?php

namespace App\Console\Commands;

use App\Models\InvoiceReminder;
use App\Services\Finance\FinanceContext;
use App\Services\Finance\InvoiceReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sends the Finance reminders whose date has arrived.
 *
 * Scheduled daily in routes/console.php alongside invoices:mark-overdue,
 * which is the natural neighbour: that command decides an invoice is late,
 * this one chases the ones somebody scheduled a chase for.
 *
 * It only ever sends what a human already created and dated. A reminder with
 * no reminder_date is manual-send only and is never picked up (see
 * InvoiceReminder::scopeDue()), so leaving the date blank is how a user says
 * "I will send this myself". Nothing here creates reminders on its own.
 *
 * Runs under FinanceContext::system(), which holds no permissions at all —
 * the authorization happened when the reminder was created and released.
 */
class SendDueInvoiceReminders extends Command
{
    protected $signature = 'invoices:send-due-reminders {--dry-run : List what would be sent without sending anything}';

    protected $description = 'Send scheduled Finance invoice reminders whose reminder date has arrived';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $due = InvoiceReminder::due()
            ->with(['invoice.client:id,name,email', 'invoice.lead:id,name,email'])
            ->orderBy('reminder_date')
            ->get();

        if ($due->isEmpty()) {
            $this->info('No reminders due.');

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        foreach ($due as $reminder) {
            $label = "reminder #{$reminder->id} → invoice " . ($reminder->invoice?->invoice_number ?? '?');

            if ($dryRun) {
                $this->line("  WOULD SEND  {$label}");
                continue;
            }

            try {
                $result = InvoiceReminderService::sendScheduled(
                    FinanceContext::system((int) $reminder->company_id),
                    $reminder
                );

                if ($result->status === 'sent') {
                    $sent++;
                    $this->line("  SENT        {$label} → {$result->sent_to}");
                } else {
                    $failed++;
                    $this->warn("  FAILED      {$label}: {$result->failure_reason}");
                }
            } catch (\Throwable $e) {
                // The service already records a failure on the row for every
                // outcome it knows about; this catches anything it did not,
                // so one bad reminder can never stop the rest of the batch.
                $failed++;
                Log::warning('[invoices:send-due-reminders] ' . $label . ': ' . $e->getMessage());
                $this->warn("  ERROR       {$label}: {$e->getMessage()}");
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn("Dry run — {$due->count()} reminder(s) are due. Nothing was sent.");

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("Sent {$sent} reminder(s), {$failed} failed.");

        return self::SUCCESS;
    }
}
