<?php

namespace App\Mail;

use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// "You're the Project Manager for X" — sent to the user who becomes a
// project's project_manager_id, from either guard. Built and sent only by
// App\Services\AssignmentMailService::projectManagerAssigned().
class ProjectManagerAssignedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [60, 300, 900];

    public function __construct(
        public Project $project,
        public string $companyName,
        public string $recipientName,
        public string $projectUrl,
        public ?string $assignedByName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You've been assigned as Project Manager: {$this->project->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.project_manager_assigned',
            with: [
                'companyName'    => $this->companyName,
                'recipientName'  => $this->recipientName,
                'assignedByName' => $this->assignedByName,
                'projectUrl'     => $this->projectUrl,
                'projectName'    => $this->project->name,
                'reference'      => $this->project->reference,
                'description'    => $this->project->description,
                'status'         => ucfirst(str_replace('_', ' ', (string) $this->project->status)),
                'priority'       => ucfirst((string) $this->project->priority),
                'startDate'      => $this->project->start_date?->format('d M Y'),
                'deadline'       => $this->project->deadline?->format('d M Y'),
            ],
        );
    }
}
