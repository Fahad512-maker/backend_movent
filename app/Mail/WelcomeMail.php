<?php

namespace App\Mail;

use App\Models\Company;
use App\Models\CompanyAdmin;
use App\Models\Package;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public CompanyAdmin $admin,
        public Company      $company,
        public ?Carbon      $trialEndsAt,
        // Whatever plan/price this signup is on — shown so the amount they'll
        // actually be charged (trial: after it ends: pending_payment: right
        // now) is never a surprise later. discount_percent is already applied.
        public ?Package     $package = null,
        public float        $priceUsd = 0,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Welcome to CRM System!');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.welcome');
    }
}
