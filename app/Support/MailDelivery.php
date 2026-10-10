<?php

namespace App\Support;

use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * Decides whether a notification email is handed to the queue or sent inline.
 *
 * The three notification Mailables (EventNotificationMail, TaskAssignedMail,
 * ProjectManagerAssignedMail) deliberately do NOT implement ShouldQueue.
 * ShouldQueue is unconditional — it queues whether or not anything will ever
 * drain the queue — and this app has to run on hosting where no shell is
 * available: no `php artisan migrate` to create the `jobs` table, and no
 * `php artisan queue:work` to process it. On such a host ShouldQueue means
 * every notification email is silently written nowhere, or throws on a
 * missing table and is swallowed by the caller's try/catch. Either way, no
 * mail. Sending inline is slower but it always works.
 *
 * So: inline by default, queue only when explicitly switched on AND the
 * queue can actually accept the job.
 *
 *   QUEUE_NOTIFICATION_MAIL=true   in .env, once a worker really runs
 *
 * The invoice/password/welcome mails are not routed through here — they were
 * always synchronous and some callers depend on a failed send throwing (see
 * Api\Admin\InvoiceController::send(), which refuses to mark an invoice
 * 'sent' when the mail did not go out).
 */
class MailDelivery
{
    /** Memoised per process — the table check must not run per recipient. */
    private static ?bool $queueEnabled = null;

    public static function to(string $email, Mailable $mailable): void
    {
        $pending = Mail::to($email);

        if (self::queueEnabled()) {
            $pending->queue($mailable);

            return;
        }

        $pending->send($mailable);
    }

    /**
     * True only when queueing is switched on and the backing store exists.
     *
     * The table check is the safety net that matters: flipping the flag on a
     * host whose `jobs` table was never migrated would otherwise turn every
     * notification email into a swallowed exception. Degrading to an inline
     * send is always better than losing the mail.
     */
    public static function queueEnabled(): bool
    {
        if (self::$queueEnabled !== null) {
            return self::$queueEnabled;
        }

        if (!config('mail.queue_notifications')) {
            return self::$queueEnabled = false;
        }

        $connection = config('queue.default');

        // 'sync' executes the job inline anyway, so there is nothing to gain
        // and a pointless serialize/deserialize round-trip to lose.
        if ($connection === 'sync' || $connection === null) {
            return self::$queueEnabled = false;
        }

        if ($connection === 'database') {
            try {
                $table = config('queue.connections.database.table', 'jobs');

                return self::$queueEnabled = Schema::hasTable($table);
            } catch (\Throwable) {
                return self::$queueEnabled = false;
            }
        }

        return self::$queueEnabled = true;
    }

    /** Why queueing is on or off, for the diagnostics endpoint. */
    public static function describe(): array
    {
        $connection = config('queue.default');
        $flag       = (bool) config('mail.queue_notifications');

        $tableExists = null;
        if ($connection === 'database') {
            try {
                $tableExists = Schema::hasTable(config('queue.connections.database.table', 'jobs'));
            } catch (\Throwable) {
                $tableExists = false;
            }
        }

        return [
            'mode'             => self::queueEnabled() ? 'queued' : 'inline (synchronous)',
            'flag'             => $flag ? 'QUEUE_NOTIFICATION_MAIL=true' : 'QUEUE_NOTIFICATION_MAIL not set (default false)',
            'queue_connection' => $connection,
            'jobs_table'       => $tableExists === null ? 'n/a' : ($tableExists ? 'exists' : 'MISSING'),
            'reason'           => match (true) {
                !$flag                       => 'Sending inline because queueing was never switched on. This is the safe default and needs no worker.',
                $connection === 'sync'       => 'QUEUE_CONNECTION=sync executes jobs inline anyway, so queueing is skipped.',
                $tableExists === false       => 'QUEUE_NOTIFICATION_MAIL is on but the `jobs` table does not exist — falling back to inline so mail is not lost. Run the migration, or leave the flag off.',
                default                      => 'Queued. A worker (php artisan queue:work) must be running or nothing will be delivered.',
            },
        ];
    }

    /** Test seam. */
    public static function flush(): void
    {
        self::$queueEnabled = null;
    }
}
