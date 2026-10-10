<?php

namespace App\Mail;

use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Sent when a project is activated and the recipient has no Client Portal
// login to notify in-app instead — see
// App\Services\ProjectCompletionService::notifyClientOfActivation(), the
// single choke point that decides portal Notification vs this email.
class ProjectActivatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Project $project,
        public string $companyName,
        public string $recipientName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your project \"{$this->project->name}\" has been activated",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.project_activated',
            with: [
                'companyName'   => $this->companyName,
                'recipientName' => $this->recipientName,
                'projectName'   => $this->project->name,
                'reference'     => $this->project->reference,
                'description'   => $this->project->description,
                'startDate'     => $this->project->start_date?->format('d M Y'),
                'deadline'      => $this->project->deadline?->format('d M Y'),
            ],
        );
    }
}
