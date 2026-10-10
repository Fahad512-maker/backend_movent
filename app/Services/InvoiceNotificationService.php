<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientPortalPermission;
use App\Models\CompanyModule;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;

class InvoiceNotificationService
{
    /**
     * Fire the Client Portal notification for an invoice that has just become
     * visible to its client — i.e. the moment its status transitions to 'sent'.
     *
     * Timing matters: Api\Client\InvoiceController::index()/show() both exclude
     * status 'draft', so notifying at creation time would hand the client a link
     * that 404s. Callers therefore only call this on the draft → sent
     * transition; re-sending an already-sent invoice goes out by email alone
     * rather than stacking a second portal row for the same invoice.
     *
     * Does nothing (email stays the only channel) when there is no portal inbox
     * to write to: an invoice raised against a lead only, a guest / external
     * customer with no client record, a client whose portal access is off, or a
     * client whose portal doesn't surface Invoices at all.
     */
    public static function notifyClientInvoiceSent(Invoice $invoice): void
    {
        try {
            // Lead-only or guest/external customer — no client, so no portal.
            if (!$invoice->client_id) {
                return;
            }

            $client = Client::find($invoice->client_id);

            // portal_access + user_id together are what "has a portal login"
            // means — see ClientPortalService::createOrUpdatePortalUser(), which
            // sets both, and Api\Client\AuthController::login(), which rejects a
            // client whose portal_access was later switched off.
            if (!$client || !$client->portal_access || !$client->user_id) {
                return;
            }

            if (!self::clientSeesInvoices($client)) {
                return;
            }

            $amount = $invoice->currency . ' ' . number_format((float) $invoice->total_amount, 2);
            $body   = $invoice->due_date
                ? "{$amount} — due " . $invoice->due_date->format('d M Y')
                : $amount;

            Notification::create([
                'user_id'    => $client->user_id,
                'company_id' => $invoice->company_id,
                'type'       => 'invoice_sent',
                'title'      => "New invoice {$invoice->invoice_number}",
                'body'       => $body,
                'data'       => [
                    'invoice_id' => $invoice->id,
                    // The frontend bell reads data.link, not the url column —
                    // see frontend/components/layout/Navbar.tsx's mapping.
                    'link'       => "/client/invoices/{$invoice->id}",
                ],
            ]);
        } catch (\Throwable $e) {
            // A notification must never break the invoice send it rode in on —
            // same swallow-and-log posture as InvoicePaymentService::notifyStakeholders().
            Log::warning('[invoice-notify] Could not create client portal notification for invoice '
                . $invoice->id . ': ' . $e->getMessage());
        }
    }

    /**
     * Fire the Client Portal notification once an invoice is actually paid
     * (in full or in part) — called from InvoicePaymentService::notifyStakeholders(),
     * the single choke point reached by every payment-confirmation path
     * (gateway webhook, Admin's manual confirm, Seller's manual confirm), so
     * this fires exactly once per confirmed payment regardless of channel.
     * Same gating as notifyClientInvoiceSent() — no portal login, or the
     * portal's Invoices section hidden for this client/company, means email
     * stays the only channel (see InvoicePaymentService::notifyPaymentConfirmedEmails()).
     */
    public static function notifyClientInvoicePaid(Invoice $invoice, Payment $payment): void
    {
        try {
            if (!$invoice->client_id) {
                return;
            }

            $client = Client::find($invoice->client_id);

            if (!$client || !$client->portal_access || !$client->user_id) {
                return;
            }

            if (!self::clientSeesInvoices($client)) {
                return;
            }

            $amount = $invoice->currency . ' ' . number_format((float) $invoice->total_amount, 2);
            $fullyPaid = $invoice->status === 'paid';

            Notification::create([
                'user_id'    => $client->user_id,
                'company_id' => $invoice->company_id,
                'type'       => 'invoice_paid',
                'title'      => $fullyPaid ? "Invoice {$invoice->invoice_number} paid" : "Payment received — {$invoice->invoice_number}",
                'body'       => $fullyPaid
                    ? "Your payment of {$amount} has been received in full."
                    : 'Your payment towards this invoice has been received — ' . $invoice->currency . ' ' . number_format((float) $payment->amount, 2),
                'data'       => [
                    'invoice_id' => $invoice->id,
                    'payment_id' => $payment->id,
                    'link'       => "/client/invoices/{$invoice->id}",
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[invoice-notify] Could not create client portal payment notification for invoice '
                . $invoice->id . ': ' . $e->getMessage());
        }
    }

    /**
     * Whether this client's portal actually shows an Invoices section. Mirrors
     * the 'invoices' entry of Api\Client\AuthController::permissions(): the
     * company must have client_portal OR invoices enabled, and the per-client
     * admin toggle (absent row = enabled) must not be switched off. Without
     * this we would notify a client about a section their portal hides.
     */
    private static function clientSeesInvoices(Client $client): bool
    {
        $companyHasModule = CompanyModule::where('company_id', $client->company_id)
            ->whereIn('module_key', ['client_portal', 'invoices'])
            ->where('is_enabled', true)
            ->exists();

        if (!$companyHasModule) {
            return false;
        }

        $toggle = ClientPortalPermission::where('client_id', $client->id)
            ->where('module_key', 'invoices')
            ->value('is_enabled');

        return $toggle === null ? true : (bool) $toggle;
    }
}
