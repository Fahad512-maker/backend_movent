<?php

namespace App\Mail;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// "A task was assigned to you" — sent to tasks.assigned_to whenever a task is
// created with an assignee, or reassigned to somebody new, from either guard.
// App\Services\AssignmentMailService::taskAssigned() is the single choke point
// that decides who gets this and builds it; nothing else constructs it.
class TaskAssignedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [60, 300, 900];

    // SerializesModels stores $task/$project by id and re-fetches them in the
    // worker, so content() below always renders the CURRENT row — and a task
    // deleted between assignment and delivery makes the job fail (landing in
    // failed_jobs) rather than emailing a stale snapshot.
    public function __construct(
        public Task $task,
        public Project $project,
        public string $companyName,
        public string $recipientName,
        public string $taskUrl,
        public ?string $assignedByName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            // ASCII-only separator on purpose: an em dash here forces the
            // whole subject into MIME encoded-word form for no benefit.
            subject: "New task assigned: {$this->task->task_number} - {$this->task->title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.task_assigned',
            with: [
                'companyName'    => $this->companyName,
                'recipientName'  => $this->recipientName,
                'assignedByName' => $this->assignedByName,
                'taskUrl'        => $this->taskUrl,
                'taskNumber'     => $this->task->task_number,
                'taskTitle'      => $this->task->title,
                'description'    => $this->task->description,
                'projectName'    => $this->project->name,
                'priority'       => ucfirst((string) $this->task->priority),
                'status'         => Task::STATUS_LABELS[$this->task->status] ?? $this->task->status,
                'startDate'      => $this->task->start_date?->format('d M Y'),
                'dueDate'        => $this->task->due_date?->format('d M Y'),
                'estimatedHours' => $this->task->estimated_hours,
            ],
        );
    }
}
