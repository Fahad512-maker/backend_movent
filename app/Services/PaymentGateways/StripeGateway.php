<?php

namespace App\Services\PaymentGateways;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Stripe Checkout Sessions (hosted page) — https://stripe.com/docs/api/checkout/sessions
//
// Also implements SupportsSavedMethods: Stripe can vault the card through the
// SAME hosted page it already uses, so a client can pay a later invoice with one
// confirmation. No card form is added to this app by that — the card is still
// only ever typed on Stripe's page, and all that comes back is a customer id and
// a payment-method token.
class StripeGateway implements GatewayContract, SupportsSavedMethods, SupportsInlineCardSetup
{
    // Signed webhook events older than this are rejected, even with a valid
    // signature, to block replay of a captured request.
    private const WEBHOOK_TOLERANCE_SECONDS = 300;

    public function createCheckout(Invoice $invoice, float $amount, array $config, string $returnUrl, string $cancelUrl): CheckoutResult
    {
        $secretKey = $config['secret_key'] ?? '';
        $currency  = strtolower($invoice->currency ?: 'usd');

        $response = Http::withToken($secretKey)
            ->asForm()
            ->post('https://api.stripe.com/v1/checkout/sessions', [
                'mode'                                             => 'payment',
                'success_url'                                      => $returnUrl . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'                                       => $cancelUrl,
                'client_reference_id'                              => (string) $invoice->id,
                'metadata'                                         => ['invoice_id' => $invoice->id],
                // Vault the card so a later invoice can be paid with one
                // confirmation instead of another trip through this page.
                //
                // Purely additive — the payment itself behaves exactly as it
                // did before these two lines, which matters because EVERY
                // Stripe payment runs through here, the public invoice link
                // included. Stripe attaches the card to a customer it creates;
                // savedMethodFromCheckout() below reads those back.
                //
                // 'off_session' is what makes the saved card chargeable later
                // without the customer present. It also means Stripe collects
                // the mandate wording on its own page, so consent for the
                // future charge is captured there rather than invented here.
                'customer_creation'                                => 'always',
                'payment_intent_data[setup_future_usage]'          => 'off_session',
                'line_items'                                       => [[
                    'quantity'   => 1,
                    'price_data' => [
                        'currency'     => $currency,
                        'unit_amount'  => (int) round($amount * 100),
                        'product_data' => ['name' => "Invoice {$invoice->invoice_number}"],
                    ],
                ]],
            ]);

        if (!$response->successful()) {
            Log::warning('Stripe checkout session creation failed', ['status' => $response->status(), 'invoice_id' => $invoice->id]);
            throw new \RuntimeException($response->json('error.message') ?? 'Stripe checkout could not be started');
        }

        $session = $response->json();

        return new CheckoutResult(
            sessionId: $session['id'],
            navigation: 'redirect',
            action: $session['url'],
        );
    }

    /**
     * Look up a Checkout Session's authoritative status directly — used as
     * an immediate-UX fallback on the customer's return from Stripe's hosted
     * page (see PublicInvoiceController::returnFromGateway()), for
     * environments where Stripe's webhook can't reach this app (e.g. a local
     * dev/sandbox instance with no public URL). Never trust this over the
     * webhook when both are available — finalizeGatewaySuccess() is
     * idempotent either way, so whichever arrives first wins.
     */
    public function getCheckoutSession(string $sessionId, array $config): array
    {
        $response = Http::withToken($config['secret_key'] ?? '')
            ->get("https://api.stripe.com/v1/checkout/sessions/{$sessionId}");

        return $response->successful() ? $response->json() : [];
    }

    /**
     * The card Stripe kept on file from a completed Checkout Session.
     *
     * Expands the PaymentIntent's payment method so the brand, last four and
     * expiry come back in one call — those are only ever used to label the card
     * on screen. The card number itself is never part of this response and
     * never reaches this app.
     *
     * Returns null for anything unexpected rather than throwing: this runs
     * AFTER a payment that already succeeded, and failing to remember a card
     * must never look like a failed payment.
     */
    public function savedMethodFromCheckout(string $sessionId, array $config): ?array
    {
        $response = Http::withToken($config['secret_key'] ?? '')
            ->get("https://api.stripe.com/v1/checkout/sessions/{$sessionId}", [
                'expand' => ['payment_intent.payment_method'],
            ]);

        if (!$response->successful()) {
            return null;
        }

        $session = $response->json();
        $method  = $session['payment_intent']['payment_method'] ?? null;

        // Cards only. A bank-debit or wallet source has no brand/last4 to show
        // and its reuse rules differ, so it is simply not offered as a saved
        // method rather than half-supported.
        if (!is_array($method) || empty($method['id']) || ($method['type'] ?? null) !== 'card') {
            return null;
        }

        $customerId = is_array($session['customer'] ?? null)
            ? ($session['customer']['id'] ?? null)
            : ($session['customer'] ?? null);

        return [
            'customer_id' => $customerId,
            'token'       => $method['id'],
            'brand'       => $method['card']['brand'] ?? null,
            'last4'       => $method['card']['last4'] ?? null,
            'exp_month'   => isset($method['card']['exp_month']) ? (int) $method['card']['exp_month'] : null,
            'exp_year'    => isset($method['card']['exp_year']) ? (int) $method['card']['exp_year'] : null,
        ];
    }

    /**
     * A Checkout Session in `setup` mode — Stripe's hosted card-entry page with
     * nothing to pay. This is what "Add Payment Method" opens.
     *
     * `mode: 'setup'` creates a SetupIntent rather than a PaymentIntent, so the
     * customer authorises the card for future use and is charged nothing now.
     * The card still only ever exists on Stripe's page.
     */
    public function createSetupCheckout(string $reference, array $config, string $returnUrl, string $cancelUrl): CheckoutResult
    {
        $response = Http::withToken($config['secret_key'] ?? '')
            ->asForm()
            ->post('https://api.stripe.com/v1/checkout/sessions', [
                'mode'                  => 'setup',
                'success_url'           => $returnUrl . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'            => $cancelUrl,
                'client_reference_id'   => $reference,
                'currency'              => 'usd',
                // A customer is required in setup mode for the card to be
                // attachable and therefore reusable later.
                'customer_creation'     => 'always',
                'metadata[reference]'   => $reference,
            ]);

        if (!$response->successful()) {
            Log::warning('Stripe setup session creation failed', [
                'status'  => $response->status(),
                'message' => $response->json('error.message'),
            ]);

            throw new \RuntimeException($response->json('error.message') ?? 'Could not open the card entry page.');
        }

        $session = $response->json();

        return new CheckoutResult(
            sessionId: $session['id'],
            navigation: 'redirect',
            action: $session['url'],
        );
    }

    /**
     * The card a completed setup session put on file.
     *
     * Reads through the SetupIntent rather than a PaymentIntent — that is the
     * only structural difference from savedMethodFromCheckout().
     */
    public function savedMethodFromSetup(string $sessionId, array $config): ?array
    {
        $response = Http::withToken($config['secret_key'] ?? '')
            ->get("https://api.stripe.com/v1/checkout/sessions/{$sessionId}", [
                'expand' => ['setup_intent.payment_method'],
            ]);

        if (!$response->successful()) {
            return null;
        }

        $session = $response->json();
        $method  = $session['setup_intent']['payment_method'] ?? null;

        if (!is_array($method) || empty($method['id']) || ($method['type'] ?? null) !== 'card') {
            return null;
        }

        $customerId = is_array($session['customer'] ?? null)
            ? ($session['customer']['id'] ?? null)
            : ($session['customer'] ?? null);

        return [
            'customer_id' => $customerId,
            'token'       => $method['id'],
            'brand'       => $method['card']['brand'] ?? null,
            'last4'       => $method['card']['last4'] ?? null,
            'exp_month'   => isset($method['card']['exp_month']) ? (int) $method['card']['exp_month'] : null,
            'exp_year'    => isset($method['card']['exp_year']) ? (int) $method['card']['exp_year'] : null,
        ];
    }

    // ── SupportsInlineCardSetup ──────────────────────────────────────────────

    /**
     * A SetupIntent the browser confirms in place — "Add a card" without
     * leaving the portal.
     *
     * Same end state as createSetupCheckout() above (a card attached to a
     * Stripe customer, authorised for later off-session use); the difference is
     * only WHERE the fields are drawn. Stripe Elements renders them in Stripe's
     * own cross-origin iframe, so the number still never reaches this app —
     * this method hands the browser a publishable key and a client secret, and
     * nothing else.
     *
     * `usage: off_session` is the same consent createSetupCheckout() relies on:
     * Stripe collects the mandate during confirmation, so a later
     * chargeSavedMethod() has the authorisation it needs rather than one
     * assumed here.
     *
     * A customer is created only when the client does not already have one for
     * this company, so their second card joins the first instead of stranding
     * itself on a separate customer that nothing would charge.
     */
    public function createInlineCardSetup(string $reference, array $config, ?string $customerId = null): array
    {
        $secretKey = $config['secret_key'] ?? '';

        if (!$customerId) {
            $customer = Http::withToken($secretKey)
                ->asForm()
                ->post('https://api.stripe.com/v1/customers', [
                    'metadata[reference]' => $reference,
                ]);

            if (!$customer->successful()) {
                Log::warning('Stripe customer creation failed', [
                    'status'  => $customer->status(),
                    'message' => $customer->json('error.message'),
                ]);

                throw new \RuntimeException('Could not start secure card setup.');
            }

            $customerId = $customer->json('id');
        }

        $intent = Http::withToken($secretKey)
            ->asForm()
            ->post('https://api.stripe.com/v1/setup_intents', [
                'customer'                => $customerId,
                'usage'                   => 'off_session',
                'payment_method_types[0]' => 'card',
                'metadata[reference]'     => $reference,
            ]);

        if (!$intent->successful()) {
            Log::warning('Stripe setup intent creation failed', [
                'status'  => $intent->status(),
                'message' => $intent->json('error.message'),
            ]);

            throw new \RuntimeException('Could not start secure card setup.');
        }

        return [
            // Public by definition — it is in every Stripe.js page on the web.
            'publishable_key' => (string) ($config['publishable_key'] ?? ''),
            // Scoped to this one setup. It can confirm this card and cannot be
            // used to charge anything, which is what makes it safe to send.
            'client_secret'   => (string) $intent->json('client_secret'),
            'setup_intent_id' => (string) $intent->json('id'),
            'customer_id'     => (string) $customerId,
        ];
    }

    /**
     * The card an in-page SetupIntent left on file.
     *
     * The browser tells us WHICH setup to look at; Stripe tells us whether it
     * actually succeeded. A forged or unfinished id fails the status check and
     * returns null, so nothing is ever saved on the browser's say-so.
     */
    public function savedMethodFromSetupIntent(string $setupIntentId, array $config): ?array
    {
        $response = Http::withToken($config['secret_key'] ?? '')
            ->get("https://api.stripe.com/v1/setup_intents/{$setupIntentId}", [
                'expand' => ['payment_method'],
            ]);

        if (!$response->successful()) {
            return null;
        }

        $intent = $response->json();

        if (($intent['status'] ?? null) !== 'succeeded') {
            return null;
        }

        $method = $intent['payment_method'] ?? null;

        // Cards only — same rule and same reasoning as the two readers above.
        if (!is_array($method) || empty($method['id']) || ($method['type'] ?? null) !== 'card') {
            return null;
        }

        $customerId = is_array($intent['customer'] ?? null)
            ? ($intent['customer']['id'] ?? null)
            : ($intent['customer'] ?? null);

        return [
            'customer_id' => $customerId,
            'token'       => $method['id'],
            'brand'       => $method['card']['brand'] ?? null,
            'last4'       => $method['card']['last4'] ?? null,
            'exp_month'   => isset($method['card']['exp_month']) ? (int) $method['card']['exp_month'] : null,
            'exp_year'    => isset($method['card']['exp_year']) ? (int) $method['card']['exp_year'] : null,
        ];
    }

    /**
     * Charge a vaulted card with the customer not present.
     *
     * Deliberately NOT routed through PaymentGatewayCharger::chargeStripe():
     * that one serves subscriptions and module purchases against the PLATFORM's
     * own Stripe account and has no customer/off-session handling. This charges
     * a TENANT's account with their own credentials, so the two stay separate
     * rather than one growing flags for the other.
     *
     * Throws on anything that is not a completed charge — including the
     * requires_action case, where the bank wants the cardholder present. The
     * caller turns that into "try another method"; nothing here may leave an
     * invoice looking paid when it is not.
     */
    public function chargeSavedMethod(
        Invoice $invoice,
        float $amount,
        \App\Models\ClientPaymentMethod $method,
        array $config
    ): string {
        $currency = strtolower($invoice->currency ?: 'usd');

        // Zero-decimal currencies are sent as whole units — same list and same
        // reasoning as PaymentGatewayCharger::chargeStripe().
        $zeroDecimal = ['bif','clp','djf','gnf','jpy','kmf','krw','mga','pyg','rwf','ugx','vnd','vuv','xaf','xof','xpf','pkr'];
        $stripeAmount = in_array($currency, $zeroDecimal, true)
            ? (int) round($amount)
            : (int) round($amount * 100);

        $response = Http::withToken($config['secret_key'] ?? '')
            ->asForm()
            ->post('https://api.stripe.com/v1/payment_intents', [
                'amount'         => $stripeAmount,
                'currency'       => $currency,
                'customer'       => $method->gateway_customer_id,
                'payment_method' => $method->payment_method_token,
                'off_session'    => 'true',
                'confirm'        => 'true',
                'metadata'       => ['invoice_id' => $invoice->id],
            ]);

        if (!$response->successful()) {
            // Logged in full for support; the caller shows the client something
            // friendly instead (spec: never expose raw gateway errors).
            Log::warning('Stripe off-session charge failed', [
                'invoice_id' => $invoice->id,
                'code'       => $response->json('error.code'),
                'decline'    => $response->json('error.decline_code'),
                'message'    => $response->json('error.message'),
            ]);

            throw new \RuntimeException($response->json('error.message') ?? 'The card was declined.');
        }

        $status = $response->json('status');

        if (!in_array($status, ['succeeded', 'processing'], true)) {
            Log::warning('Stripe off-session charge not completed', [
                'invoice_id' => $invoice->id,
                'status'     => $status,
            ]);

            throw new \RuntimeException(
                $status === 'requires_action'
                    ? 'This card needs to be confirmed with your bank. Please pay this invoice using the card entry page.'
                    : 'The payment could not be completed.'
            );
        }

        return (string) $response->json('id');
    }

    public function parseWebhook(Request $request, array $config): WebhookEvent
    {
        $secret    = $config['webhook_secret'] ?? '';
        $signature = $request->header('Stripe-Signature', '');
        $payload   = $request->getContent();

        if (!$secret || !$this->verifySignature($payload, $signature, $secret)) {
            return new WebhookEvent(verified: false);
        }

        $event = json_decode($payload, true);
        $type  = $event['type'] ?? '';
        $obj   = $event['data']['object'] ?? [];

        if ($type === 'checkout.session.completed' && ($obj['payment_status'] ?? '') === 'paid') {
            return new WebhookEvent(
                verified: true,
                eventId: $event['id'] ?? null,
                outcome: 'succeeded',
                gatewaySessionId: $obj['id'] ?? null,
                transactionId: $obj['payment_intent'] ?? ($obj['id'] ?? null),
            );
        }

        if (in_array($type, ['checkout.session.expired', 'checkout.session.async_payment_failed'])) {
            return new WebhookEvent(
                verified: true,
                eventId: $event['id'] ?? null,
                outcome: 'failed',
                gatewaySessionId: $obj['id'] ?? null,
                failureReason: $type,
            );
        }

        // Signature was valid but this isn't an event type we act on (e.g.
        // charge.refunded) — acknowledge without treating it as success/failure.
        return new WebhookEvent(verified: true, eventId: $event['id'] ?? null, outcome: 'ignored');
    }

    private function verifySignature(string $payload, string $header, string $secret): bool
    {
        $parts = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', $part, 2), 2, null);
            $parts[$k] = $v;
        }

        $timestamp = $parts['t'] ?? null;
        $v1        = $parts['v1'] ?? null;
        if (!$timestamp || !$v1) return false;

        if (abs(time() - (int) $timestamp) > self::WEBHOOK_TOLERANCE_SECONDS) return false;

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return hash_equals($expected, $v1);
    }
}
