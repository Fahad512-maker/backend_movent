<?php

namespace App\Services;

use App\Models\ClientPaymentMethod;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\PaymentGateways\PaymentGatewayManager;
use App\Services\PaymentGateways\SupportsInlineCardSetup;
use App\Services\PaymentGateways\SupportsSavedMethods;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Saved cards for Client Portal clients.
 *
 * Two jobs, and nothing else:
 *   1. Remember the card a completed hosted checkout left on file.
 *   2. Charge one of those cards for a later invoice.
 *
 * It owns no accounting. A successful charge is handed straight to
 * InvoicePaymentService::finalizeGatewaySuccess(), the same call the webhook and
 * the public invoice link already make — so the invoice balance, the receipt,
 * the notifications and the automatic project creation are the existing path,
 * not a second one that could drift.
 *
 * Gateways that cannot vault a card are simply not supported here
 * (SupportsSavedMethods is an optional capability). Callers fall back to the
 * ordinary hosted checkout, which is what every company sees today.
 */
class ClientPaymentMethodService
{
    /**
     * The gateway driver + this company's credentials, when that gateway can
     * vault cards. Null when it cannot, which is the signal to fall back to the
     * ordinary hosted checkout.
     *
     * Resolution goes through PaymentGatewayManager::activeRowFor() rather than
     * a query of this service's own: that helper already handles tenant-level
     * accounts, the legacy per-company row, and picking the default account when
     * a company has several of the same type. A local query here would quietly
     * miss the tenant-level case.
     */
    private static function savedMethodGateway(int $companyId, string $gateway): ?array
    {
        $company = \App\Models\Company::find($companyId);

        if (!$company) {
            return null;
        }

        $row = PaymentGatewayManager::activeRowFor($company, $gateway);

        if (!$row) {
            return null;
        }

        $driver = PaymentGatewayManager::driver($gateway);

        if (!$driver instanceof SupportsSavedMethods) {
            return null;
        }

        return ['driver' => $driver, 'config' => $row->config, 'row' => $row];
    }

    /** Does this company's gateway support saving a card at all? */
    public static function supportsSaving(int $companyId, string $gateway): bool
    {
        return self::savedMethodGateway($companyId, $gateway) !== null;
    }

    /**
     * Remember the card a just-completed checkout left on file.
     *
     * Called after a payment has ALREADY succeeded, so every failure path here
     * is swallowed: not remembering a card is a small inconvenience, while
     * throwing would turn a successful payment into an error on the client's
     * screen.
     *
     * Idempotent — the gateway hands back a stable token, and the unique index
     * on (client, company, gateway, token) means re-running this on the same
     * checkout updates the existing row instead of growing a duplicate.
     */
    public static function rememberFromCheckout(Invoice $invoice, string $gateway, string $sessionId): ?ClientPaymentMethod
    {
        try {
            if (!$invoice->client_id) {
                return null;
            }

            $resolved = self::savedMethodGateway((int) $invoice->company_id, $gateway);
            if (!$resolved) {
                return null;
            }

            $details = $resolved['driver']->savedMethodFromCheckout($sessionId, $resolved['config']);
            if (!$details || empty($details['token'])) {
                return null;
            }

            $isFirst = !ClientPaymentMethod::where('client_id', $invoice->client_id)
                ->where('company_id', $invoice->company_id)
                ->where('gateway', $gateway)
                ->exists();

            return ClientPaymentMethod::updateOrCreate(
                [
                    'client_id'            => $invoice->client_id,
                    'company_id'           => $invoice->company_id,
                    'gateway'              => $gateway,
                    'payment_method_token' => $details['token'],
                ],
                [
                    'gateway_customer_id' => $details['customer_id'] ?? null,
                    'card_brand'          => $details['brand'] ?? null,
                    'card_last4'          => $details['last4'] ?? null,
                    'exp_month'           => $details['exp_month'] ?? null,
                    'exp_year'            => $details['exp_year'] ?? null,
                    // The first card saved for this company becomes the default;
                    // later ones do not silently take over.
                    'is_default'          => $isFirst,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('[saved-method] could not remember card after checkout', [
                'invoice_id' => $invoice->id,
                'gateway'    => $gateway,
                'error'      => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Open the gateway's hosted card-entry page — "Add Payment Method".
     *
     * Nothing is charged. The client enters the card on the gateway's page and
     * comes back to $returnUrl, where rememberFromSetup() stores the resulting
     * token.
     *
     * @return \App\Services\PaymentGateways\CheckoutResult
     */
    public static function startSetup(int $clientId, int $companyId, string $gateway, string $returnUrl, string $cancelUrl)
    {
        $resolved = self::savedMethodGateway($companyId, $gateway);

        if (!$resolved) {
            throw new \RuntimeException('Saving a card is not available for this company.');
        }

        // The reference is only a correlation aid for gateway-side records. The
        // return handler does NOT trust it — it saves against the authenticated
        // client, so a tampered reference cannot attach a card to someone else.
        return $resolved['driver']->createSetupCheckout(
            "client:{$clientId}:company:{$companyId}",
            $resolved['config'],
            $returnUrl,
            $cancelUrl
        );
    }

    /**
     * Store the card a completed setup session left on file.
     *
     * Saved against the client passed in by the authenticated caller — never
     * against anything read out of the gateway session — so the worst a forged
     * session id can do is fail to resolve.
     */
    public static function rememberFromSetup(int $clientId, int $companyId, string $gateway, string $sessionId): ?ClientPaymentMethod
    {
        try {
            $resolved = self::savedMethodGateway($companyId, $gateway);
            if (!$resolved) {
                return null;
            }

            $details = $resolved['driver']->savedMethodFromSetup($sessionId, $resolved['config']);
            if (!$details || empty($details['token'])) {
                return null;
            }

            $isFirst = !ClientPaymentMethod::where('client_id', $clientId)
                ->where('company_id', $companyId)
                ->where('gateway', $gateway)
                ->exists();

            return ClientPaymentMethod::updateOrCreate(
                [
                    'client_id'            => $clientId,
                    'company_id'           => $companyId,
                    'gateway'              => $gateway,
                    'payment_method_token' => $details['token'],
                ],
                [
                    'gateway_customer_id' => $details['customer_id'] ?? null,
                    'card_brand'          => $details['brand'] ?? null,
                    'card_last4'          => $details['last4'] ?? null,
                    'exp_month'           => $details['exp_month'] ?? null,
                    'exp_year'            => $details['exp_year'] ?? null,
                    'is_default'          => $isFirst,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('[saved-method] could not remember card after setup', [
                'client_id' => $clientId,
                'gateway'   => $gateway,
                'error'     => $e->getMessage(),
            ]);

            return null;
        }
    }

    // ── In-portal card entry ─────────────────────────────────────────────────

    /**
     * Can this company's gateway take a card without sending the client away?
     *
     * A separate question from supportsSaving(): a gateway may be able to vault
     * a card only through its own hosted page. Callers that get false here fall
     * back to startSetup() above, which is exactly today's behaviour.
     */
    public static function supportsInlineSetup(int $companyId, string $gateway): bool
    {
        $resolved = self::savedMethodGateway($companyId, $gateway);

        return $resolved !== null && $resolved['driver'] instanceof SupportsInlineCardSetup;
    }

    /**
     * Start "Add a card" inside the portal.
     *
     * Returns only a publishable key and a single-use client secret — enough
     * for the gateway's own iframe to collect and authorise a card, and not
     * enough to charge anything. No secret key, and no card detail in either
     * direction: the number is typed into the gateway's frame and submitted by
     * the gateway's script, never through this app.
     *
     * The client's EXISTING gateway customer is reused when they already have a
     * card with this company, so a second card joins the first on one customer
     * rather than stranding itself on a new one that nothing would charge.
     *
     * @return array{publishable_key: string, client_secret: string, setup_intent_id: string, customer_id: string}
     */
    public static function startInlineSetup(int $clientId, int $companyId, string $gateway): array
    {
        $resolved = self::savedMethodGateway($companyId, $gateway);

        if (!$resolved || !$resolved['driver'] instanceof SupportsInlineCardSetup) {
            throw new \RuntimeException('Adding a card is not available for this company.');
        }

        $existingCustomerId = ClientPaymentMethod::where('client_id', $clientId)
            ->where('company_id', $companyId)
            ->where('gateway', $gateway)
            ->whereNotNull('gateway_customer_id')
            ->orderByDesc('id')
            ->value('gateway_customer_id');

        return $resolved['driver']->createInlineCardSetup(
            // Correlation only. The save below is keyed to the AUTHENTICATED
            // client, never to anything read back out of this string.
            "client:{$clientId}:company:{$companyId}",
            $resolved['config'],
            $existingCustomerId ?: null
        );
    }

    /**
     * Store the card an in-page setup left on file.
     *
     * The browser reports WHICH setup; the gateway is asked whether it actually
     * succeeded, and a setup that did not comes back null. Saved against the
     * client the authenticated caller passed in — never against anything read
     * out of the gateway response — so a forged setup id can only fail to
     * resolve, never attach a card to someone else.
     *
     * Idempotent for the same reason rememberFromSetup() is: the unique index
     * on (client, company, gateway, token) turns a repeat into an update.
     */
    public static function rememberFromSetupIntent(int $clientId, int $companyId, string $gateway, string $setupIntentId): ?ClientPaymentMethod
    {
        try {
            $resolved = self::savedMethodGateway($companyId, $gateway);

            if (!$resolved || !$resolved['driver'] instanceof SupportsInlineCardSetup) {
                return null;
            }

            $details = $resolved['driver']->savedMethodFromSetupIntent($setupIntentId, $resolved['config']);

            if (!$details || empty($details['token'])) {
                return null;
            }

            $isFirst = !ClientPaymentMethod::where('client_id', $clientId)
                ->where('company_id', $companyId)
                ->where('gateway', $gateway)
                ->exists();

            return ClientPaymentMethod::updateOrCreate(
                [
                    'client_id'            => $clientId,
                    'company_id'           => $companyId,
                    'gateway'              => $gateway,
                    'payment_method_token' => $details['token'],
                ],
                [
                    'gateway_customer_id' => $details['customer_id'] ?? null,
                    'card_brand'          => $details['brand'] ?? null,
                    'card_last4'          => $details['last4'] ?? null,
                    'exp_month'           => $details['exp_month'] ?? null,
                    'exp_year'            => $details['exp_year'] ?? null,
                    // The first card for this company becomes the default;
                    // later ones never silently take over.
                    'is_default'          => $isFirst,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('[saved-method] could not remember card after inline setup', [
                'client_id' => $clientId,
                'gateway'   => $gateway,
                'error'     => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** Make one card the default for this client + company. */
    public static function setDefault(ClientPaymentMethod $method): void
    {
        DB::transaction(function () use ($method) {
            ClientPaymentMethod::where('client_id', $method->client_id)
                ->where('company_id', $method->company_id)
                ->update(['is_default' => false]);

            $method->update(['is_default' => true]);
        });
    }

    /**
     * Forget a card.
     *
     * Only the local reference is dropped — detaching it at the gateway is not
     * attempted, because a token may be shared with a subscription or another
     * record there and revoking it blind could break an unrelated charge.
     * Losing our reference is enough: nothing in this app can use it again.
     *
     * If it was the default, the next remaining card takes over so the client
     * is not left with a set of cards and no default.
     */
    public static function remove(ClientPaymentMethod $method): void
    {
        DB::transaction(function () use ($method) {
            $wasDefault = $method->is_default;
            $clientId   = $method->client_id;
            $companyId  = $method->company_id;

            $method->delete();

            if ($wasDefault) {
                $next = ClientPaymentMethod::where('client_id', $clientId)
                    ->where('company_id', $companyId)
                    ->orderByDesc('id')
                    ->first();

                $next?->update(['is_default' => true]);
            }
        });
    }

    /**
     * The cards this client can pay this company with, usable ones first.
     *
     * @return \Illuminate\Support\Collection<int, ClientPaymentMethod>
     */
    public static function forClient(int $clientId, int $companyId)
    {
        return ClientPaymentMethod::where('client_id', $clientId)
            ->where('company_id', $companyId)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get()
            ->reject(fn (ClientPaymentMethod $m) => $m->isExpired())
            ->values();
    }

    /**
     * Charge a saved card for an invoice.
     *
     * The whole thing runs inside a transaction that takes a row lock on the
     * invoice, which is what makes a double-click, a browser retry and a second
     * tab all safe: the second attempt waits, then re-reads the balance and
     * finds nothing outstanding.
     *
     * Returns [success, message]. A gateway failure leaves the invoice exactly
     * as it was and records a failed Payment for support to look at — an
     * invoice is never marked paid on anything but a confirmed success.
     *
     * @return array{0: bool, 1: string}
     */
    public static function charge(Invoice $invoice, ClientPaymentMethod $method): array
    {
        $resolved = self::savedMethodGateway((int) $invoice->company_id, $method->gateway);

        if (!$resolved) {
            return [false, 'This payment method is no longer available. Please pay using the card entry page.'];
        }

        if ($method->isExpired()) {
            return [false, 'That card has expired. Please use another payment method.'];
        }

        // Created inside the lock below, used outside it.
        $payment = null;
        $amount  = 0.0;

        $blocked = DB::transaction(function () use ($invoice, $method, &$payment, &$amount) {
            // lockForUpdate: the authoritative balance, held until this
            // transaction commits. Two concurrent confirmations serialise here
            // rather than both seeing an unpaid invoice.
            $fresh = Invoice::whereKey($invoice->id)->lockForUpdate()->first();

            $amount = round(max((float) $fresh->total_amount - (float) $fresh->paid_amount, 0), 2);

            if ($amount <= 0 || !in_array($fresh->status, PaymentAssistantService::PAYABLE_STATUSES, true)) {
                return 'This invoice has already been paid.';
            }

            // An in-flight attempt from another tab/retry. Refused rather than
            // queued — two charges for one invoice is the failure this exists
            // to prevent.
            if ($fresh->payments()->where('status', 'pending')->exists()) {
                return 'A payment for this invoice is already being processed. Please wait a moment and refresh.';
            }

            $payment = Payment::create([
                'invoice_id'         => $fresh->id,
                'amount'             => $amount,
                'currency'           => $fresh->currency,
                'method'             => 'card',
                'gateway'            => $method->gateway,
                'status'             => 'pending',
                'payment_date'       => now()->toDateString(),
                'notes'              => 'Payment Assistant — saved card',
            ]);

            return null;
        });

        if ($blocked) {
            return [false, $blocked];
        }

        // Belt and braces: the transaction either returns a refusal above or
        // creates the Payment, so this cannot happen — but nothing below may
        // run without a Payment row to settle against.
        if (!$payment) {
            return [false, 'Your payment could not be started. Please try again.'];
        }

        try {
            $reference = $resolved['driver']->chargeSavedMethod($invoice, $amount, $method, $resolved['config']);
        } catch (\Throwable $e) {
            // Kept internally for support (spec: never show a raw gateway error
            // to the client). The driver logs gateway-level detail; this also
            // catches anything that went wrong around it, which is otherwise
            // invisible behind the friendly message below.
            Log::warning('[saved-method] charge failed', [
                'invoice_id'        => $invoice->id,
                'payment_id'        => $payment->id,
                'gateway'           => $method->gateway,
                'payment_method_id' => $method->id,
                'error'             => $e->getMessage(),
            ]);

            InvoicePaymentService::finalizeGatewayFailure($payment, $e->getMessage());

            return [false, 'Your payment could not be completed. Please check your payment method or try another one.'];
        }

        // The existing settlement path — balance, receipt, notifications and the
        // automatic project creation all happen in here, identically to a
        // payment made through the hosted page or the public invoice link.
        InvoicePaymentService::finalizeGatewaySuccess($payment, $reference);

        return [true, 'Payment successful.'];
    }
}
