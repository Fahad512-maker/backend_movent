<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Sent once a real gateway payment (Stripe/PayPal/Authorize.net) is
// confirmed via webhook — distinct from PaymentReceivedMail, which is for
// the manual submit-and-verify claim flow (bank transfer / unconfirmed).
class PaymentConfirmedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public Payment $payment,
        public bool $forAdmin = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->forAdmin
                ? "Payment Confirmed — {$this->invoice->invoice_number}"
                : "Payment Confirmation — {$this->invoice->invoice_number}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment_confirmed',
            with: [
                'forAdmin'      => $this->forAdmin,
                'companyName'   => $this->invoice->brandingProfile()['name'] ?? 'Your Company',
                'invoiceNumber' => $this->invoice->invoice_number,
                'amount'        => (float) $this->payment->amount,
                'currency'      => $this->invoice->currency ?? 'USD',
                'gateway'       => ucfirst(str_replace('_', ' ', $this->payment->gateway ?? 'gateway')),
                'transactionId' => $this->payment->gateway_ref,
                'receiptNumber' => $this->payment->receipt_number,
                'customerName'  => $this->invoice->client?->name
                                    ?? $this->invoice->customer_name
                                    ?? 'Customer',
                'paymentDate'   => $this->payment->payment_date?->format('d M Y')
                                    ?? now()->format('d M Y'),
            ],
        );
    }
}
