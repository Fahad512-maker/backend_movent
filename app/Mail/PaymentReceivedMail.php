<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentReceivedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public Payment $payment,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Payment Received — {$this->invoice->invoice_number}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment_received',
            with: [
                'companyName'   => $this->invoice->brandingProfile()['name'] ?? 'Your Company',
                'invoiceNumber' => $this->invoice->invoice_number,
                'amount'        => (float) $this->payment->amount,
                'currency'      => $this->invoice->currency ?? 'USD',
                'method'        => ucfirst(str_replace('_', ' ', $this->payment->method ?? 'N/A')),
                'receiptNumber' => $this->payment->receipt_number,
                'customerName'  => $this->invoice->client?->name
                                    ?? $this->invoice->customer_name
                                    ?? 'Customer',
                'paymentDate'   => $this->payment->payment_date?->format('d M Y')
                                    ?? now()->format('d M Y'),
                'invoiceStatus' => $this->invoice->status,
            ],
        );
    }
}
