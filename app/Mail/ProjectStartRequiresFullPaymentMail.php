<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Sent to the client when a part payment lands on a tenant whose Deal Workflow
// requires the invoice to be settled in full before work begins — see
// App\Services\PaymentProjectStartService's full-payment mode.
class ProjectStartRequiresFullPaymentMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public string $companyName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Full payment required to start your project — {$this->invoice->invoice_number}",
        );
    }

    public function content(): Content
    {
        $total = (float) $this->invoice->total_amount;
        $paid  = (float) $this->invoice->paid_amount;

        return new Content(
            view: 'emails.project_start_requires_full_payment',
            with: [
                'companyName'   => $this->companyName,
                'invoiceNumber' => $this->invoice->invoice_number,
                'currency'      => $this->invoice->currency ?? 'USD',
                'total'         => $total,
                'paid'          => $paid,
                'remaining'     => max(0, round($total - $paid, 2)),
                'customerName'  => $this->invoice->client?->name
                                    ?? $this->invoice->customer_name
                                    ?? 'Customer',
                'dueDate'       => $this->invoice->due_date?->format('d M Y'),
                'projectName'   => $this->invoice->project_title,
            ],
        );
    }
}
