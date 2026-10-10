<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Models\InvoiceReminder;
use App\Support\PublicFile;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The payment-reminder email Finance > Invoice Reminders sends.
 *
 * Built the same way as InvoiceMail — same brandingProfile() resolution, so a
 * reminder for a Brand Invoice arrives under the brand's name and logo rather
 * than the company's, exactly like the invoice it is chasing. No second email
 * system: this is one more Mailable alongside the dozen already in app/Mail,
 * rendered from resources/views/emails and sent through the app's configured
 * mailer.
 *
 * Not ShouldQueue, for the reason MailDelivery documents at length: this app
 * has to run on hosting with no queue worker. The scheduled-send command
 * routes through MailDelivery so it queues where a worker genuinely exists;
 * the manual "Send now" path sends inline so the user gets a truthful
 * success/failure answer while they are still looking at the screen.
 */
class InvoiceReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public InvoiceReminder $reminder,
        public ?string $paymentUrl,
        public string $issuerName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->reminder->subject
                ?: "Payment reminder: invoice {$this->invoice->invoice_number} from {$this->issuerName}",
        );
    }

    public function content(): Content
    {
        $progress = $this->invoice->paymentProgress();
        $profile  = $this->invoice->brandingProfile();

        $dueDate   = $this->invoice->due_date;
        $daysLate  = $dueDate && $this->invoice->isPastDue()
            ? (int) $dueDate->endOfDay()->diffInDays(now())
            : 0;

        return new Content(
            view: 'emails.invoice_reminder',
            with: [
                'invoiceNumber' => $this->invoice->invoice_number,
                'clientName'    => $this->invoice->client?->name
                    ?? $this->invoice->lead?->name
                    ?? $this->invoice->customer_name,
                'issuerName'    => $this->issuerName,
                'issuerLogoUrl' => PublicFile::url($profile['logo_path'] ?? null),
                'issuerEmail'   => $profile['email'] ?? null,
                'issuerPhone'   => $profile['phone'] ?? null,
                'currency'      => $this->invoice->currency ?? 'USD',
                'totalAmount'   => $progress['total_amount'],
                'paidAmount'    => $progress['paid_amount'],
                'amountDue'     => $progress['remaining_amount'],
                'issueDate'     => $this->invoice->created_at?->format('d M Y'),
                'dueDate'       => $dueDate?->format('d M Y'),
                'daysLate'      => $daysLate,
                'paymentUrl'    => $this->paymentUrl,
                // The Finance user's own words, when they wrote any. The
                // blade escapes it; it is never rendered as HTML.
                'customMessage' => $this->reminder->message,
                'purpose'       => $this->invoice->invoice_purpose,
            ],
        );
    }
}
