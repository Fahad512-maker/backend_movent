<?php

namespace App\Services\Finance;

use App\Mail\InvoiceReminderMail;
use App\Models\Invoice;
use App\Models\InvoiceReminder;
use App\Models\Notification;
use App\Support\FrontendUrl;
use App\Support\MailDelivery;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Create → send → track, for Finance > Invoice Reminders.
 *
 * Delivery rides the app's existing mail stack — an App\Mail Mailable
 * rendered from resources/views/emails and handed to the configured mailer,
 * exactly like InvoiceMail. No separate email system, no separate template
 * engine, no separate transport.
 *
 * Two send paths, on purpose:
 *   • send()          a human pressed Send. Inline, so a failure is reported
 *                     to their face and the reminder is marked 'failed' with
 *                     the reason, rather than disappearing into a queue that
 *                     may have no worker.
 *   • sendScheduled() the daily `invoices:send-due-reminders` command. Goes
 *                     through MailDelivery, which queues only where a worker
 *                     genuinely exists and otherwise sends inline — the same
 *                     decision every other scheduled notification in this app
 *                     already makes.
 *
 * Both converge on deliver(), so a scheduled reminder and a manual one
 * produce the identical email and the identical tracking row.
 */
class InvoiceReminderService
{
    /**
     * Where a reminder for this invoice would actually go.
     *
     * Three sources in falling order of authority, matching how the rest of
     * the app names an invoice's customer: the linked Client, the free-text
     * customer_email captured for a guest invoice, then the originating Lead.
     * Null means this invoice has nobody to remind — the caller refuses the
     * send rather than recording a delivery that never happened.
     */
    public static function recipientFor(Invoice $invoice): ?string
    {
        $email = $invoice->client?->email
            ?: $invoice->customer_email
            ?: $invoice->lead?->email;

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * Raise a reminder. Nothing is sent here — that is send()'s job, and
     * keeping them separate is what makes "create now, send when authorized"
     * (and the permission split behind it) possible.
     */
    public static function create(FinanceContext $ctx, Invoice $invoice, array $data): InvoiceReminder
    {
        $reminder = InvoiceReminder::create([
            'invoice_id'          => $invoice->id,
            // Denormalised from the invoice, never from the request — a
            // client-supplied company_id must not be able to file a reminder
            // under a company the invoice does not belong to.
            'company_id'          => $invoice->company_id,
            'created_by'          => $ctx->actorUserId(),
            'created_by_admin_id' => $ctx->actorAdminId(),
            'type'                => $data['type'] ?? 'email',
            'reminder_date'       => $data['reminder_date'] ?? null,
            'status'              => 'scheduled',
            'subject'             => $data['subject'] ?? null,
            'message'             => $data['message'] ?? null,
        ]);

        FinanceAudit::log(
            $ctx,
            (int) $invoice->company_id,
            'finance_reminder_created',
            'InvoiceReminder',
            $reminder->id,
            "Reminder created for invoice {$invoice->invoice_number}"
                . ($reminder->reminder_date ? " (due {$reminder->reminder_date->toDateString()})" : ''),
            [
                'invoice_id'     => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'reminder_date'  => $reminder->reminder_date?->toDateString(),
                'type'           => $reminder->type,
            ],
        );

        return $reminder;
    }

    /**
     * Send a reminder now, on someone's explicit instruction.
     *
     * Returns the refreshed reminder; read its `status` for the outcome. A
     * failure is a recorded fact ('failed' + failure_reason), not an
     * exception — Track is meant to show what went wrong, and a reminder that
     * vanished on error would make the history a lie.
     */
    public static function send(FinanceContext $ctx, InvoiceReminder $reminder): InvoiceReminder
    {
        return self::deliver($ctx, $reminder, queued: false);
    }

    /** The unattended path used by the daily scheduler. */
    public static function sendScheduled(FinanceContext $ctx, InvoiceReminder $reminder): InvoiceReminder
    {
        return self::deliver($ctx, $reminder, queued: true);
    }

    private static function deliver(FinanceContext $ctx, InvoiceReminder $reminder, bool $queued): InvoiceReminder
    {
        $invoice = $reminder->invoice;

        if (!$invoice) {
            return self::fail($reminder, 'The invoice this reminder belongs to no longer exists.');
        }

        // Nothing to chase. Checked at send time rather than create time
        // because an invoice can be settled between the two, and sending a
        // client a demand for money they have already paid is worse than
        // sending nothing.
        if ((float) $invoice->paid_amount >= (float) $invoice->total_amount - 0.005) {
            return self::fail($reminder, 'Invoice is already settled — reminder not sent.');
        }

        if (in_array($invoice->status, ['cancelled', 'draft'], true)) {
            return self::fail($reminder, "Invoice is {$invoice->status} — reminder not sent.");
        }

        if (!in_array($reminder->type, InvoiceReminder::DELIVERABLE_TYPES, true)) {
            return self::fail($reminder, "'{$reminder->type}' reminders cannot be delivered yet — only email is supported.");
        }

        $recipient = self::recipientFor($invoice);
        if (!$recipient) {
            return self::fail($reminder, 'This invoice has no customer email address on file.');
        }

        // A reminder is useless without somewhere to pay. Mint or refresh the
        // same public token the invoice's own "Send" flow uses — no second
        // link scheme, and the token keeps working for the client afterwards.
        $paymentUrl = self::paymentUrl($invoice);

        try {
            $mailable = new InvoiceReminderMail(
                $invoice,
                $reminder,
                $paymentUrl,
                $invoice->brandingProfile()['name'] ?? 'Your supplier',
            );

            if ($queued) {
                MailDelivery::to($recipient, $mailable);
            } else {
                Mail::to($recipient)->send($mailable);
            }
        } catch (\Throwable $e) {
            Log::error('[finance-reminder] send failed', [
                'reminder_id' => $reminder->id,
                'invoice_id'  => $invoice->id,
                'error'       => $e->getMessage(),
            ]);

            return self::fail($reminder, 'Email could not be sent: ' . $e->getMessage());
        }

        $reminder->forceFill([
            'status'           => 'sent',
            'sent_at'          => now(),
            'sent_to'          => $recipient,
            'sent_by'          => $ctx->actorUserId(),
            'sent_by_admin_id' => $ctx->actorAdminId(),
            'failure_reason'   => null,
        ])->save();

        self::notifyInvoiceOwner($invoice, $reminder, $ctx);

        FinanceAudit::log(
            $ctx,
            (int) $invoice->company_id,
            'finance_reminder_sent',
            'InvoiceReminder',
            $reminder->id,
            "Reminder for invoice {$invoice->invoice_number} sent to {$recipient}",
            [
                'invoice_id'     => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'sent_to'        => $recipient,
                'channel'        => $queued ? 'scheduled' : 'manual',
            ],
        );

        return $reminder->refresh();
    }

    private static function fail(InvoiceReminder $reminder, string $reason): InvoiceReminder
    {
        $reminder->forceFill([
            'status'         => 'failed',
            'failure_reason' => substr($reason, 0, 500),
        ])->save();

        return $reminder->refresh();
    }

    /**
     * A live payment link for this invoice.
     *
     * Reuses Invoice::generatePublicToken() — the same token the invoice
     * email and the Share Link action produce — rather than inventing a
     * reminder-only link, so a client who follows either lands on the same
     * public payment page. A still-valid existing token is left alone so a
     * link the client already has does not stop working.
     */
    private static function paymentUrl(Invoice $invoice): ?string
    {
        if (!$invoice->isTokenValid()) {
            $invoice->generatePublicToken(30);
            $invoice->refresh();
        }

        return $invoice->payment_token
            ? FrontendUrl::to('pay/invoice/' . $invoice->payment_token)
            : null;
    }

    /**
     * Tell whoever raised the invoice that it has been chased.
     *
     * Uses the existing in-app Notification model — the same bell every other
     * module writes to. Skipped when the sender IS the invoice's creator
     * (nobody needs telling what they just did), and swallowed on error: a
     * notification must never turn a delivered reminder into a failed one.
     */
    private static function notifyInvoiceOwner(Invoice $invoice, InvoiceReminder $reminder, FinanceContext $ctx): void
    {
        if (!$invoice->created_by || $invoice->created_by === $ctx->actorUserId()) {
            return;
        }

        try {
            Notification::create([
                'user_id'    => $invoice->created_by,
                'company_id' => $invoice->company_id,
                'type'       => 'invoice_reminder_sent',
                'title'      => 'Payment reminder sent',
                'body'       => "A payment reminder for invoice {$invoice->invoice_number} was sent to {$reminder->sent_to}.",
                'data'       => [
                    'invoice_id'  => $invoice->id,
                    'reminder_id' => $reminder->id,
                    'link'        => "/invoices/{$invoice->id}",
                ],
            ]);
        } catch (\Throwable) {
        }
    }
}
