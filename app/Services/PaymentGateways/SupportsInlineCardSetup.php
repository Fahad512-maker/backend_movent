<?php

namespace App\Services\PaymentGateways;

/**
 * An OPTIONAL capability: this gateway can put a card on file from a form
 * rendered INSIDE this app, with no redirect to a hosted page.
 *
 * Deliberately separate from SupportsSavedMethods, for the same reason that one
 * is separate from GatewayContract: vaulting a card and vaulting it without
 * leaving the app are different capabilities, and a gateway that can do the
 * first but not the second must keep working untouched. Callers ask
 * `instanceof SupportsInlineCardSetup` and fall back to the hosted page
 * (createSetupCheckout) when the answer is no.
 *
 * WHAT STILL NEVER HAPPENS. "Inside the app" means the app renders the
 * gateway's OWN hosted input fields — Stripe Elements' cross-origin iframe —
 * not a card form of its own. The number, expiry and CVC are entered into the
 * gateway's frame, are submitted straight to the gateway by the gateway's
 * script, and never touch this app's DOM, its JavaScript, its network traffic
 * or its logs. The browser only ever receives a client secret, which is scoped
 * to one setup and cannot be used to charge anything.
 *
 * Implementations must never return, log or store raw card data. As with
 * SupportsSavedMethods, the only things that may cross this boundary are the
 * gateway's own opaque references plus the brand/last-four used to label a
 * saved card on screen.
 */
interface SupportsInlineCardSetup
{
    /**
     * Everything the browser needs to render the gateway's card fields and
     * authorise a card for future use, without navigating away.
     *
     * $customerId lets a client's second and third cards land on the SAME
     * gateway customer as their first, which is what makes them all chargeable
     * later from one place. Null creates one.
     *
     * The returned `client_secret` is single-purpose and scoped to this setup:
     * it can confirm this one card and cannot be used to charge anything, which
     * is why it is safe to hand to the browser. `publishable_key` is public by
     * definition. No secret key is ever part of this response.
     *
     * @param  string      $reference   Opaque correlation id for gateway-side records.
     * @param  array       $config      This company's decrypted gateway credentials.
     * @param  string|null $customerId  Reuse this gateway customer when given.
     * @return array{publishable_key: string, client_secret: string, setup_intent_id: string, customer_id: string}
     */
    public function createInlineCardSetup(string $reference, array $config, ?string $customerId = null): array;

    /**
     * Read back the card an in-page setup left on file, from the gateway.
     *
     * Called with the id the browser reports, but the ANSWER comes from the
     * gateway, not the browser: a setup that did not actually succeed returns
     * null however the request described it.
     *
     * Same return shape as SupportsSavedMethods::savedMethodFromSetup().
     *
     * @return array{customer_id: ?string, token: string, brand: ?string, last4: ?string, exp_month: ?int, exp_year: ?int}|null
     */
    public function savedMethodFromSetupIntent(string $setupIntentId, array $config): ?array;
}
