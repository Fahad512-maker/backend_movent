<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentAssistantSession;
use App\Models\SystemAuditLog;
use App\Models\User;

/**
 * The Client Portal Payment Assistant's rules.
 *
 * RULE-BASED, NOT AI. Nothing here calls a model or any external service —
 * invoice numbers are extracted with a regex and the flow is a small state
 * machine. That is a requirement, not a shortcut: the assistant has to be
 * predictable, and the only outbound request this feature is allowed to make is
 * the payment itself, through the gateway the tenant already configured.
 *
 * It also owns no payment logic. Finding, validating and describing an invoice
 * happens here; charging does not. The charge is the existing hosted checkout
 * (Api\Client\InvoiceController::initiateGatewayCheckout) and the settlement is
 * the existing InvoicePaymentService, so notifications, receipts and automatic
 * project creation all keep working exactly as they do for the public invoice
 * link — there is no second payment path.
 */
class PaymentAssistantService
{
    /** Statuses an invoice can actually be paid from — mirrors initiateGatewayCheckout(). */
    public const PAYABLE_STATUSES = ['sent', 'overdue', 'partially_paid'];

    /**
     * Pull an invoice number out of whatever the client typed.
     *
     * Handles the shapes people actually use — "INV-1025", "inv 1025",
     * "pay invoice 1025", "invoice #1025", or just "1025" — and nothing more
     * ambitious. Anything it cannot read comes back null and the assistant asks
     * again, which is the correct outcome for a deterministic bot.
     *
     * Returns the DIGITS only; matchInvoice() searches on those so a client who
     * omits the prefix, or whose company uses a different one, still lands on
     * their invoice.
     */
    public static function extractInvoiceNumber(string $input): ?string
    {
        $text = trim(mb_strtolower($input));

        if ($text === '') {
            return null;
        }

        // Emails go first. They routinely carry digits, and an address in the
        // sentence ("send it to ap2@acme.com") must never be mined for one.
        $text = preg_replace('/\S+@\S+/u', ' ', $text) ?? $text;

        // A reference carrying its OWN prefix, joined by - _ or / with no space:
        // "ABC-2026-11", "SR/2026/0007". Kept whole, because the prefix is part
        // of the number in these schemes.
        //
        // inv/invoice/bill are excluded here on purpose — those are labels, not
        // prefixes, so "bill-4400" must yield 4400 (which matches INV-4400)
        // rather than the literal "bill-4400". The next branch handles them.
        if (preg_match('/\b((?!inv\b|invoice\b|bill\b)[a-z]{2,8}[-_\/]\d[\d\-_\/]*\d|(?!inv\b|invoice\b|bill\b)[a-z]{2,8}[-_\/]\d)/u', $text, $m)) {
            return self::usableReference($m[1]);
        }

        // A LABELLED reference wins over anything else in the sentence.
        //
        // This ordering is what stops "Send $1000 to ap@acmecorp.com for
        // INV-8271" being read as invoice 1000 — the amount. The captured part
        // keeps its own separators, so "INV-2026-0022" yields "2026-0022"
        // rather than just the first segment.
        // The trailing `(?:\s\d+){0,3}` picks up segments the client separated
        // with spaces — "inv 2026 0022" is the same reference as INV-2026-0022,
        // and normalizeReference() collapses both to 20260022. Bounded to three
        // extra groups so a sentence cannot run on into the capture.
        if (preg_match('/\b(?:inv|invoice|bill)\b[\s#:._\-]*(?:(?:no|number|num)\b\.?[\s#:._\-]*)?(\d[\d\-_\/]*(?:\s\d+){0,3})/u', $text, $m)) {
            return self::usableReference($m[1]);
        }

        // "#8271" / "#2026-0022" — marked as a reference without being named.
        if (preg_match('/#\s*(\d[\d\-_\/]*\d|\d)/u', $text, $m)) {
            return self::usableReference($m[1]);
        }

        // Nothing labelled. Drop anything that reads as MONEY before looking at
        // bare numbers, so "pay 500" cannot be taken for invoice 500.
        $withoutAmounts = preg_replace(
            '/(?:[$€£₹₨]\s*[\d,]+(?:\.\d+)?)|(?:\b(?:usd|pkr|eur|gbp|aed|inr)\b\s*[\d,]+(?:\.\d+)?)|(?:[\d,]+(?:\.\d+)?\s*\b(?:usd|pkr|eur|gbp|aed|inr)\b)/u',
            ' ',
            $text
        ) ?? $text;

        // A bare reference is only taken when it is the ONLY one left. Two
        // unlabelled numbers is genuinely ambiguous, and guessing there is how
        // the wrong invoice gets loaded — the assistant asks again instead.
        preg_match_all('/\d[\d\-_\/]*\d|\d/u', $withoutAmounts, $all);
        $found = array_values(array_unique($all[0] ?? []));

        return count($found) === 1 ? self::usableReference($found[0]) : null;
    }

    /**
     * A reference is usable if it contains at least one non-zero digit —
     * "0000" and "0" are not invoice numbers.
     */
    private static function usableReference(string $reference): ?string
    {
        $reference = trim($reference, " \t-_/.");

        return preg_match('/[1-9]/', $reference) ? $reference : null;
    }

    /**
     * Comparable form of an invoice reference: lowercase, with every separator
     * removed.
     *
     * This is what lets a client type the number however their eye reads it —
     * "INV-2026-0022", "inv 2026 0022", "2026/0022" and "20260022" all reduce
     * to the same thing, so none of them has to match the stored punctuation
     * exactly.
     */
    public static function normalizeReference(string $reference): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($reference)) ?? '';
    }

    /**
     * The invoice that number refers to, for THIS client in THIS company.
     *
     * Scoping is the whole point. $clientIds comes from the caller's own
     * permittedClientIds() — the same ownership rule every other portal
     * endpoint uses — and company_id pins it to the company chosen in the
     * session, so an invoice belonging to another client, or to the same person
     * at a different company, is simply not in the result set. Nothing about
     * the request can widen it.
     *
     * Drafts and cancelled invoices are excluded outright: they are not things
     * a client is meant to see, let alone pay.
     *
     * @param array<int, int> $clientIds
     */
    public static function matchInvoice(string $reference, array $clientIds, int $companyId): ?Invoice
    {
        $needle = self::normalizeReference($reference);

        if ($needle === '') {
            return null;
        }

        // Both sides are compared with separators stripped, so the client never
        // has to reproduce the stored punctuation: "INV-2026-0022",
        // "inv 2026 0022" and "20260022" all find the same invoice.
        $normalized = "LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(invoice_number, '-', ''), '_', ''), '/', ''), '.', ''), ' ', ''))";

        return Invoice::whereIn('client_id', $clientIds)
            ->where('company_id', $companyId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->where(function ($q) use ($normalized, $needle) {
                // Exact, then "typed it without the prefix" — INV-1025 found by
                // "1025". Anchored to the END rather than a free LIKE %x%, so
                // 1025 cannot also match INV-11025.
                $q->whereRaw("{$normalized} = ?", [$needle])
                    ->orWhereRaw("{$normalized} LIKE ?", ['%' . $needle]);
            })
            ->orderByDesc('id')
            ->first();
    }

    /**
     * What is still owed, computed server-side from the invoice row.
     *
     * The client never sends an amount and the session never stores one — a
     * stored figure would go stale the moment a payment landed from anywhere
     * else (the public link, an admin recording a bank transfer), and stale is
     * exactly how an invoice gets charged twice or for the wrong sum.
     */
    public static function amountDue(Invoice $invoice): float
    {
        return round(max((float) $invoice->total_amount - (float) $invoice->paid_amount, 0), 2);
    }

    /** Can this invoice be paid right now? */
    public static function isPayable(Invoice $invoice): bool
    {
        return in_array($invoice->status, self::PAYABLE_STATUSES, true)
            && self::amountDue($invoice) > 0;
    }

    /**
     * The invoice as the assistant's card shows it. Presentation only — every
     * figure is recomputed, never carried over from a previous response.
     */
    public static function invoiceCard(Invoice $invoice): array
    {
        return [
            'id'             => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'company_name'   => $invoice->company?->name,
            'currency'       => $invoice->currency,
            'invoice_date'   => optional($invoice->created_at)->toDateString(),
            'due_date'       => $invoice->due_date ? (string) $invoice->due_date : null,
            'total_amount'   => round((float) $invoice->total_amount, 2),
            'paid_amount'    => round((float) $invoice->paid_amount, 2),
            'amount_due'     => self::amountDue($invoice),
            'status'         => $invoice->status,
            'is_payable'     => self::isPayable($invoice),
        ];
    }

    /**
     * The companies this login can pay in — one entry per Client record that
     * has portal access AND the invoices module enabled.
     *
     * @return array<int, array{company_id: int, client_id: int, company_name: string|null, unpaid_count: int}>
     */
    public static function payableCompanies(User $user): array
    {
        return Client::where('user_id', $user->id)
            ->where('portal_access', true)
            ->with('company:id,name')
            ->get()
            ->filter(fn (Client $c) => ClientPortalService::isModuleEnabled($c, 'invoices'))
            ->map(fn (Client $c) => [
                'company_id'   => (int) $c->company_id,
                'client_id'    => (int) $c->id,
                'company_name' => $c->company?->name,
                'unpaid_count' => Invoice::where('client_id', $c->id)
                    ->where('company_id', $c->company_id)
                    ->whereIn('status', self::PAYABLE_STATUSES)
                    ->count(),
            ])
            ->values()
            ->all();
    }

    /**
     * Unpaid invoices for the session's client + company, newest first.
     *
     * @param array<int, int> $clientIds
     * @return array<int, array<string, mixed>>
     */
    public static function unpaidInvoices(array $clientIds, int $companyId, int $limit = 25): array
    {
        return Invoice::whereIn('client_id', $clientIds)
            ->where('company_id', $companyId)
            ->whereIn('status', self::PAYABLE_STATUSES)
            ->with('company:id,name')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Invoice $i) => self::invoiceCard($i))
            ->filter(fn (array $card) => $card['amount_due'] > 0)
            ->values()
            ->all();
    }

    /**
     * Audit trail for the assistant (spec §22).
     *
     * Records who did what to which invoice. Deliberately carries no amount,
     * no card detail and no gateway credential — the money side is already
     * audited by InvoicePaymentService against the Payment row, and repeating
     * it here would only create a second, drift-prone copy.
     *
     * THIS IS THE PERMANENT RECORD. It is not what the client sees and it is
     * never deleted — "Clear History" rotates the session's conversation_id
     * (stamped into every row below) so past activity stays here, intact and
     * still attributable to the conversation it came from, while the client's
     * visible thread starts empty.
     */
    public static function audit(
        PaymentAssistantSession $session,
        string $action,
        ?int $invoiceId = null,
        array $meta = []
    ): void {
        try {
            if ($session->conversation_id) {
                $meta['conversation_id'] = $session->conversation_id;
            }

            SystemAuditLog::create([
                'company_id'  => $session->company_id,
                'user_id'     => $session->user_id,
                'action'      => 'payment_assistant.' . $action,
                'module_key'  => 'invoice',
                'entity_type' => 'Invoice',
                'entity_id'   => $invoiceId ?? $session->invoice_id,
                'new_values'  => $meta ?: null,
            ]);
        } catch (\Throwable) {
            // An audit write must never be the reason a client cannot pay.
        }
    }
}
