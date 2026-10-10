<?php

namespace App\Mail;

use App\Models\Client;
use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClientPortalWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Client  $client,
        public Company $company,
        public string  $portalEmail,
        // Plain-text password — only ever available here, right after the
        // caller hashes it into the User row; never re-derivable afterwards.
        public string  $portalPassword,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your {$this->company->name} client portal access");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.client-portal-welcome');
    }
}
