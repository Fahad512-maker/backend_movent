<?php

namespace App\Mail;

use App\Models\Company;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User    $user,
        public Company $company,
        // Plain-text password — only ever available here, right after the
        // caller hashes it into the User row; never re-derivable afterwards.
        public string  $password,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your {$this->company->name} account on Movent");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.user-welcome');
    }
}
