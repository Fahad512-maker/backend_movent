<?php

namespace App\Services;

use App\Mail\LeadSalesChatInviteMail;
use App\Models\Company;
use App\Models\Lead;
use App\Support\MailDelivery;
use Illuminate\Support\Facades\Log;

/**
 * Sends a Lead the no-login link to their own Sales Chat.
 *
 * Called from all three places a staff member raises a lead
 * (Api\Admin\LeadController::store(), Api\User\LeadController::store(), and
 * the "Resend chat invite" action on both), so the wording, the token
 * handling and the activity trail can never drift between them.
 *
 * NOT called from Api\Client\ServiceController, the third place a Lead row is
 * created: that lead comes from a client who is already signed in to the
 * Client Portal and has a conversation surface there. Emailing them a
 * password-less guest link would be both redundant and a worse security
 * posture than the login they already have.
 *
 * Delivery failures are swallowed and logged, never thrown. Creating a lead
 * must not fail because an SMTP host was briefly unreachable — the lead is
 * the record that matters, the invite is recoverable at any time from the
 * Lead screen's Resend action.
 */
class LeadChatInviteService
{
    /**
     * @param bool $regenerate Issue a fresh token first, invalidating any link
     *                         already in circulation. Used by the "Revoke &
     *                         resend" path; a plain resend keeps the existing
     *                         token so a Lead who already has the email does
     *                         not end up with two links, one of them dead.
     * @return bool Whether the email was actually handed to the mailer.
     */
    public static function send(Lead $lead, bool $regenerate = false): bool
    {
        if (!self::isSendable($lead)) {
            return false;
        }

        $regenerate ? $lead->regenerateChatToken() : $lead->ensureChatToken();

        $chatUrl = $lead->chatUrl();
        if (!$chatUrl) {
            return false;
        }

        try {
            MailDelivery::to($lead->email, new LeadSalesChatInviteMail(
                leadName:    $lead->name,
                companyName: self::companyName($lead),
                chatUrl:     $chatUrl,
                sellerName:  $lead->assignedTo?->name,
            ));
        } catch (\Throwable $e) {
            Log::warning('[lead-chat-invite] Could not send the Sales Chat invite.', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);

            return false;
        }

        $lead->forceFill(['chat_invited_at' => now()])->save();

        $lead->logActivity(
            'note_added',
            "Sales Chat invite emailed to {$lead->email}",
            'System',
            ['email' => $lead->email, 'regenerated' => $regenerate],
        );

        return true;
    }

    /**
     * A lead with no email address has nothing to send to — the staff-side
     * create form requires one, but leads predating that (and any future
     * import) can carry a null.
     */
    public static function isSendable(Lead $lead): bool
    {
        return filled($lead->email);
    }

    /**
     * The sending company's own trading name, per-company as of 2026-09-11 —
     * so an admin running two companies has each one's leads emailed under
     * that company's identity rather than one shared account-wide name. Falls
     * back to the app name only if the lead somehow has no company row.
     */
    private static function companyName(Lead $lead): string
    {
        $name = Company::find($lead->company_id)?->invoicingProfile()['name'];

        return trim((string) $name) ?: (string) config('app.name');
    }
}
