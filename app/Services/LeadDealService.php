<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\Project;
use Illuminate\Database\QueryException;

/**
 * Auto-closes a Lead's pipeline the moment its invoice is paid in full — the
 * "invoice paid -> lead Won" counterpart to marking a lead Won by hand via
 * Api\*\LeadController::updateStatus() (which stays the only path for typed-in
 * deal fields like required_kickoff_amount/service_category). A no-op for a
 * Lead that's already Won or Lost — an invoice settling after the deal fell
 * through, or after Won was already recorded another way, must not re-fire.
 */
class LeadDealService
{
    public static function markWonFromPayment(Lead $lead, Invoice $invoice): void
    {
        if ($invoice->status !== 'paid') {
            return;
        }
        if (in_array($lead->status, ['won', 'lost'], true)) {
            return;
        }

        $old = $lead->status;

        $data = [
            'status'                 => 'won',
            'won_at'                 => now(),
            'converted_at'           => $lead->converted_at ?? now(),
            'proposed_project_title' => $lead->proposed_project_title ?: "{$lead->name} — Project",
        ];

        // Same not-atomic-read-then-pick-next-free retry as the manual
        // mark-won path in Api\*\LeadController::updateStatus() — two
        // invoices settling in the same instant must not collide on one
        // deal_reference and lose the whole update.
        $attempts = 0;
        while (true) {
            $data['deal_reference'] = self::nextDealReference();
            try {
                $lead->update($data);
                break;
            } catch (QueryException $e) {
                $isDealRefCollision = (int) $e->getCode() === 23000
                    && str_contains($e->getMessage(), 'leads_deal_reference_unique');
                if (!$isDealRefCollision || ++$attempts >= 5) {
                    throw $e;
                }
            }
        }

        // The invoice may have been raised against an EXISTING client (picked
        // in the invoice form) rather than a fresh guest/lead-only entity —
        // in that case the Lead itself never got linked back to it, so
        // $lead->client (the "Convert to Client" button's own !lead.client_id
        // gate) stays null even though a client clearly already exists.
        // Only claims an unclaimed client — never steals one already linked
        // to a different lead. Mirrors LeadController::convert()'s own
        // whereNull()-guarded backfill for the same reason.
        if ($invoice->client_id) {
            Client::where('id', $invoice->client_id)->whereNull('lead_id')->update(['lead_id' => $lead->id]);
        } else {
            // The common case: a lead-only/guest invoice with no client
            // picked at all. Auto-convert the Lead into a Client (or reuse a
            // matching one) the same moment it's marked Won — mirrors the
            // manual "Convert to Client" button (Api\*\LeadController::
            // convert()) exactly, just without a human clicking it.
            self::autoConvertToClient($lead, $invoice);
        }

        // Reflects the invoice/payment state that triggered this, rather than
        // hardcoding 'awaiting_invoice' as the manual mark-won path does for a
        // Deal that has no invoice yet — this one always already has one.
        DealEligibilityService::recomputeFulfillmentStatus($lead);

        $lead->logActivity('deal_created', "Deal {$lead->deal_reference} created — {$lead->proposed_project_title}",
            'System', ['deal_reference' => $lead->deal_reference]);
        $lead->logActivity('won', "Status changed from {$old} to won — invoice {$invoice->invoice_number} paid in full",
            'System', ['from' => $old, 'to' => 'won']);

        $notifyBody = "Lead \"{$lead->name}\" was automatically marked Won — invoice {$invoice->invoice_number} was paid in full.";

        if ($lead->assigned_to) {
            Notification::create([
                'user_id'    => $lead->assigned_to,
                'company_id' => $lead->company_id,
                'type'       => 'lead_won',
                'title'      => 'Lead won!',
                'body'       => $notifyBody,
                'data'       => ['lead_id' => $lead->id, 'link' => "/leads/{$lead->id}"],
            ]);
        }

        // Company Admin isn't a `users` row, so a plain Notification::create()
        // can't reach them — same reason InvoicePaymentService::
        // notifyStakeholders() routes through NotificationService for admins.
        // Previously this event never reached Admin at all.
        try {
            NotificationService::notifyCompanyAdmins($lead->company_id, null, [
                'module'      => 'sales',
                'type'        => 'lead_won',
                'title'       => 'Lead won!',
                'message'     => $notifyBody,
                'entity_type' => 'Lead',
                'entity_id'   => $lead->id,
                'url'         => "/admin/leads/{$lead->id}",
            ]);
        } catch (\Throwable) {
        }
    }

    // Mirrors Api\*\LeadController::convert() (the manual "Convert to
    // Client" button) exactly — same Client field mapping, same Project/
    // Invoice client_id backfill — with two differences: (1) dedup also
    // matches on phone, not just email, and reuses the match instead of
    // rejecting; (2) there's no HTTP actor, so account_manager falls back to
    // the lead's own owner instead of "whoever clicked the button".
    private static function autoConvertToClient(Lead $lead, Invoice $invoice): void
    {
        // Already converted — e.g. a human used "Convert to Client" before
        // this payment landed. Nothing to do; the invoice/project backfill
        // below still needs to run in case THIS invoice/project weren't
        // linked yet (created after the manual conversion).
        $client = $lead->client ?: self::findMatchingClient($lead);

        if (!$client) {
            $client = Client::create([
                'company_id'      => $lead->company_id,
                'name'            => $lead->name,
                'email'           => $lead->email,
                'phone'           => $lead->phone,
                'company_name'    => $lead->company_name,
                'notes'           => $lead->notes,
                'status'          => 'active',
                'lead_id'         => $lead->id,
                'account_manager' => $lead->transferred_to ?? $lead->assigned_to,
                'created_by'      => $lead->transferred_to ?? $lead->assigned_to,
            ]);
        } elseif (!$client->lead_id) {
            // Found by email/phone match, not yet claimed by any lead —
            // attach it to this one, same as the invoice->client_id branch
            // above does for an already-picked client. Never steal a client
            // already linked to a different lead.
            $client->update(['lead_id' => $lead->id]);
        }

        Invoice::where('lead_id', $lead->id)->whereNull('client_id')->update(['client_id' => $client->id]);
        Project::where('lead_id', $lead->id)->whereNull('client_id')->update(['client_id' => $client->id]);

        // Keeps the in-memory $invoice object (passed on to
        // PaymentProjectStartService::handle() right after this returns)
        // consistent with the DB write just above — its own fresh-create
        // branch reads $invoice->client_id directly to stamp a brand-new
        // Project, and would otherwise create it with a stale null.
        $invoice->client_id = $client->id;

        $lead->logActivity('converted', "Lead automatically converted to client \"{$client->name}\" — invoice {$invoice->invoice_number} paid in full",
            'System', ['client_id' => $client->id]);
    }

    // Email OR phone, company-scoped — "use the existing Client instead of
    // creating a duplicate." No existing helper in the codebase does this;
    // every other dedup check in Api\*\LeadController/ClientController is
    // email-only and used to REJECT a write, not to find-and-reuse a match.
    private static function findMatchingClient(Lead $lead): ?Client
    {
        if (empty($lead->email) && empty($lead->phone)) {
            return null;
        }

        return Client::where('company_id', $lead->company_id)
            ->where(function ($q) use ($lead) {
                if (!empty($lead->email)) $q->orWhere('email', $lead->email);
                if (!empty($lead->phone)) $q->orWhere('phone', $lead->phone);
            })
            ->first();
    }

    // Mirrors Api\*\LeadController::nextDealReference() exactly — same
    // DEAL-{year}-{seq} series, same table, so uniqueness holds regardless of
    // which path generated it.
    private static function nextDealReference(): string
    {
        $year = now()->year;
        $maxSeq = Lead::withTrashed()
            ->where('deal_reference', 'like', "DEAL-{$year}-%")
            ->pluck('deal_reference')
            ->map(fn ($reference) => (int) substr($reference, -4))
            ->max();

        $seq = $maxSeq ? $maxSeq + 1 : 1;

        do {
            $reference = sprintf('DEAL-%d-%04d', $year, $seq++);
        } while (Lead::withTrashed()->where('deal_reference', $reference)->exists());

        return $reference;
    }
}
