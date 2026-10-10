<?php

namespace App\Services\PaymentGateways;

use App\Models\ClientPaymentMethod;
use App\Models\Invoice;

/**
 * An OPTIONAL capability: this gateway can keep a card on file and charge it
 * again later without the customer returning to a hosted page.
 *
 * Deliberately a separate interface rather than extra methods on
 * GatewayContract. Every gateway must be able to start a checkout; not every
 * gateway can vault a card, and the ones that cannot must keep working
 * untouched. Callers ask `instanceof SupportsSavedMethods` and fall back to the
 * ordinary hosted checkout when the answer is no — so a company on PayPal or
 * Authorize.net sees exactly the behaviour it sees today.
 *
 * Implementations must never return, log or store raw card data. The only
 * things that may cross this boundary are the gateway's own opaque references
 * plus the brand/last-four used to label a saved card on screen.
 */
interface SupportsSavedMethods
{
    /**
     * Read the card a completed hosted checkout left on file.
     *
     * Called after a successful payment, against the session that payment ran
     * through. Returns null when the checkout saved nothing — which is normal
     * and must never be treated as a failure, since the payment itself already
     * succeeded.
     *
     * @param  array  $config  This company's decrypted gateway credentials.
     * @return array{customer_id: ?string, token: string, brand: ?string, last4: ?string, exp_month: ?int, exp_year: ?int}|null
     */
    public function savedMethodFromCheckout(string $sessionId, array $config): ?array;

    /**
     * Start a hosted page whose only purpose is to put a card on file — no
     * invoice, no charge.
     *
     * This is what an "Add Payment Method" button opens. It has to be a hosted
     * page for the same reason a payment is: card details must never reach this
     * app. The customer enters them on the gateway, and the caller later reads
     * the result back with savedMethodFromSetup().
     *
     * @param  string $reference  An opaque id the gateway will hand back, so
     *                            the return can be matched to the client that
     *                            started it without trusting the browser.
     */
    public function createSetupCheckout(string $reference, array $config, string $returnUrl, string $cancelUrl): CheckoutResult;

    /**
     * Read the card a completed SETUP session left on file.
     *
     * Same shape as savedMethodFromCheckout(), but for a session that never
     * charged anything.
     *
     * @return array{customer_id: ?string, token: string, brand: ?string, last4: ?string, exp_month: ?int, exp_year: ?int}|null
     */
    public function savedMethodFromSetup(string $sessionId, array $config): ?array;

    /**
     * Charge a saved card for this invoice, with the customer not present.
     *
     * Returns the gateway's transaction reference on success. MUST throw on
     * anything else — a declined card, an expired one, a gateway outage. The
     * caller turns that into a friendly message and leaves the invoice
     * untouched; nothing may mark an invoice paid off the back of a guess.
     */
    public function chargeSavedMethod(
        Invoice $invoice,
        float $amount,
        ClientPaymentMethod $method,
        array $config
    ): string;
}
