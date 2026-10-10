<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Shared by both the staff/User and Company Admin forgot-password flows —
// the template is generic (not guard-specific business logic), only the
// reset URL differs, built by the calling controller.
class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $resetUrl,
        public string $name,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your password');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password_reset',
            with: [
                'name'     => $this->name,
                'resetUrl' => $this->resetUrl,
            ],
        );
    }
}
