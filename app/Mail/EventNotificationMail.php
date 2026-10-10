<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One Mailable for every system event that mirrors an in-app notification by
 * email (new lead, project completed, invoice overdue, ticket resolved, …).
 *
 * Deliberately generic: these emails all say the same shape of thing —
 * "<event happened>, here are the details, here's the button" — so 18 nearly
 * identical Mailable/Blade pairs would be 18 places to keep in sync. The
 * per-event wording, detail rows and action button all come from
 * App\Services\EventMailService, which is the only thing that builds this.
 *
 * The two dedicated Mailables that predate it (TaskAssignedMail,
 * ProjectManagerAssignedMail) are left as-is — they're already wired and
 * tested, and re-routing them through here would buy nothing.
 */
class EventNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    // Deliberately NOT ShouldQueue — App\Support\MailDelivery decides per
    // send whether this goes on the queue or straight out, because the app
    // has to work on hosting with no shell to run a worker. These two
    // properties only take effect on the queued path.
    public $tries = 3;

    // 1 min, then 5, then 15 — a transient SMTP refusal or rate-limit clears
    // on its own; anything still failing after that belongs in failed_jobs.
    public $backoff = [60, 300, 900];

    /**
     * @param array<string, string|null> $rows Detail table, label => value.
     *                                         Null/'' values are dropped by the view.
     */
    public function __construct(
        public string $subjectLine,
        public string $badge,
        public string $heading,
        public string $intro,
        public array $rows,
        public string $companyName,
        public string $recipientName = 'there',
        public ?string $actionLabel = null,
        public ?string $actionUrl = null,
        public ?string $footNote = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.event_notification',
            with: [
                'badge'       => $this->badge,
                'heading'     => $this->heading,
                'intro'       => $this->intro,
                'rows'          => array_filter($this->rows, fn ($v) => $v !== null && $v !== ''),
                'companyName'   => $this->companyName,
                'recipientName' => $this->recipientName,
                'actionLabel' => $this->actionLabel,
                'actionUrl'   => $this->actionUrl,
                'footNote'    => $this->footNote,
            ],
        );
    }
}
