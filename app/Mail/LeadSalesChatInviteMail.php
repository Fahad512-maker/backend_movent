<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The email a Lead receives when they are created: a short welcome and one
 * button that opens their own Sales Chat with no login at all.
 *
 * Takes plain scalars rather than the Lead model on purpose. Two reasons:
 *   • Nothing internal can leak by accident. A Mailable holding the Lead would
 *     give the template reach over estimated_value, source, status, notes,
 *     assigned_to and the whole deal block — none of which a Lead may ever
 *     see. Passing only the four things the template renders makes that
 *     impossible rather than merely unwise.
 *   • $chatUrl is resolved by the caller, during the web request, via
 *     App\Support\FrontendUrl — which needs a request to fall back on when
 *     FRONTEND_URL is unset. Building it inside a (possibly queued) Mailable
 *     would emit a localhost link on exactly the hosts that misconfiguration
 *     is most likely on.
 *
 * $companyName is the SENDING company's own trading name (per-company as of
 * 2026-09-11 — see Company::invoicingProfile()), not the lead's employer.
 */
class LeadSalesChatInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $leadName,
        public string $companyName,
        public string $chatUrl,
        /** The Seller the Lead will actually be talking to, when one is assigned. */
        public ?string $sellerName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Let's talk — {$this->companyName}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.lead-sales-chat-invite');
    }
}
