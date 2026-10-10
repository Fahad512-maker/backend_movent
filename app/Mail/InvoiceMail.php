<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Support\PublicFile;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public string  $paymentUrl,
        public string  $companyName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Invoice {$this->invoice->invoice_number} from {$this->companyName}",
        );
    }

    public function content(): Content
    {
        $amountDue = (float) $this->invoice->total_amount - (float) $this->invoice->paid_amount;

        // Whose letterhead this email carries. $companyName is passed in by
        // the caller and already resolved through brandingProfile(), but the
        // logo and contact block were never sent, so a Brand Invoice arrived
        // with the brand's name and nothing else of the brand. Resolving the
        // rest here keeps the emailed copy identical to the share link, the
        // client portal and the detail screen.
        $profile = $this->invoice->brandingProfile();

        return new Content(
            view: 'emails.invoice',
            with: [
                'invoiceNumber' => $this->invoice->invoice_number,
                'clientName'    => $this->invoice->client?->name ?? $this->invoice->customer_name,
                'companyName'   => $this->companyName,
                // Absolute by construction (see Support\PublicFile) — a
                // relative src would be dead in every mail client.
                'issuerLogoUrl' => PublicFile::url($profile['logo_path'] ?? null),
                'issuerEmail'   => $profile['email'] ?? null,
                'issuerPhone'   => $profile['phone'] ?? null,
                'issuerWebsite' => $profile['website'] ?? null,
                'issuerAddress' => $profile['address'] ?? null,
                'issuerCountry' => $profile['country'] ?? null,
                'isBrand'       => ($profile['invoice_type'] ?? 'company') === 'brand',
                'clientEmail'   => $this->invoice->client?->email ?? $this->invoice->customer_email,
                'clientPhone'   => $this->invoice->client?->phone ?? $this->invoice->customer_phone,
                'clientAddress' => $this->invoice->client?->address ?? $this->invoice->customer_address,
                'issueDate'     => $this->invoice->created_at?->format('d M Y'),
                'dueDate'       => $this->invoice->due_date
                                    ? \Carbon\Carbon::parse($this->invoice->due_date)->format('d M Y')
                                    : null,
                'status'        => $this->invoice->status,
                'currency'      => $this->invoice->currency ?? 'USD',
                'amountDue'     => $amountDue,
                'paymentUrl'    => $this->paymentUrl,
                'notes'         => $this->invoice->notes,
                // What the invoice is FOR — the email used to show nothing but
                // an amount, which for a project/milestone invoice left the
                // client with no idea what they were being billed for.
                'purpose'       => $this->invoice->invoice_purpose,
            ],
        );
    }
}
