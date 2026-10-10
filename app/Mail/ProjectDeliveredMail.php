<?php

namespace App\Mail;

use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Sent when a client-less (guest) project's final package is delivered —
// the admin-entered email on the Delivery page is the only way that
// customer hears about it, since a guest has no portal login to notify
// in-app. See Api\Admin\ProjectController::uploadAndDeliver()/deliverToClient().
class ProjectDeliveredMail extends Mailable
{
    use Queueable, SerializesModels;

    // downloadUrl is null when the project has no invoice to link to — the
    // caller attaches the file to this mailable directly instead (see
    // emailGuestDelivery()), and the view falls back to "see the attached file".
    public function __construct(
        public Project $project,
        public ?string $downloadUrl,
        public string $companyName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your project \"{$this->project->name}\" has been delivered",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.project_delivered',
            with: [
                'companyName' => $this->companyName,
                'projectName' => $this->project->name,
                'reference'   => $this->project->reference,
                'fileName'    => $this->project->delivery_file_name,
                'downloadUrl' => $this->downloadUrl,
            ],
        );
    }
}
