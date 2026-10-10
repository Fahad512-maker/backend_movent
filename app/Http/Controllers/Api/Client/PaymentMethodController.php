<?php

namespace App\Http\Controllers\Api\Client;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Client\Concerns\ScopesToPermittedClient;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientPaymentMethod;
use App\Services\ClientPaymentMethodService;
use App\Support\FrontendUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The client's saved cards — the "Payment Methods" screen and the Add Payment
 * Method flow behind it.
 *
 * No card detail ever passes through here. Adding a card opens the gateway's
 * own hosted page; all that comes back is a token this app stores and can only
 * hand to that same gateway. The list endpoint returns a label ("Visa ••••
 * 4242") and nothing else — the token and customer id are `$hidden` on the
 * model, so no response here can leak them even by accident.
 *
 * Everything is scoped to the authenticated client's OWN Client records, per
 * company. A method id from the request is always re-resolved through that
 * scope rather than looked up directly.
 */
class PaymentMethodController extends Controller
{
    use ScopesToPermittedClient;

    /** @return array<int, int> */
    private function clientIds(Request $request): array
    {
        return $this->permittedClientIds($request, 'invoices');
    }

    /** The Client record this login has at $companyId, or null. */
    private function clientForCompany(Request $request, int $companyId): ?Client
    {
        return Client::whereIn('id', $this->clientIds($request))
            ->where('company_id', $companyId)
            ->first();
    }

    /** One of this login's own saved methods, or null. Never looked up by id alone. */
    private function ownMethod(Request $request, int $id): ?ClientPaymentMethod
    {
        return ClientPaymentMethod::where('id', $id)
            ->whereIn('client_id', $this->clientIds($request))
            ->first();
    }

    private function present(ClientPaymentMethod $m): array
    {
        return [
            'id'         => $m->id,
            'company_id' => $m->company_id,
            'gateway'    => $m->gateway,
            'label'      => $m->label(),
            'brand'      => $m->card_brand,
            'last4'      => $m->card_last4,
            'exp_month'  => $m->exp_month,
            'exp_year'   => $m->exp_year,
            'is_default' => $m->is_default,
            'is_expired' => $m->isExpired(),
        ];
    }

    // ── GET /client/payment-methods ──────────────────────────────────────────

    /**
     * Every card this login has, grouped by the company it belongs to, plus
     * whether that company's gateway can save one at all — which is what tells
     * the screen whether to offer an Add button.
     */
    public function index(Request $request): JsonResponse
    {
        $clients = Client::whereIn('id', $this->clientIds($request))
            ->with('company:id,name')
            ->get();

        $methods = ClientPaymentMethod::whereIn('client_id', $clients->pluck('id'))
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success([
            'companies' => $clients->map(fn (Client $c) => [
                'company_id'      => (int) $c->company_id,
                'company_name'    => $c->company?->name,
                'can_add'         => ClientPaymentMethodService::supportsSaving((int) $c->company_id, 'stripe'),
                // Whether the card can be added WITHOUT leaving the portal.
                // False falls the screen back to the hosted page, which is
                // exactly the behaviour every company had before.
                'can_add_inline'  => ClientPaymentMethodService::supportsInlineSetup((int) $c->company_id, 'stripe'),
                'payment_methods' => $methods
                    ->where('client_id', $c->id)
                    ->map(fn (ClientPaymentMethod $m) => $this->present($m))
                    ->values()
                    ->all(),
            ])->values()->all(),
        ]);
    }

    // ── POST /client/payment-methods/setup ───────────────────────────────────

    /**
     * Open the gateway's hosted card-entry page. Charges nothing.
     *
     * Returns the same {navigation, action, fields} shape the invoice checkout
     * uses, so the frontend navigates identically.
     */
    public function setup(Request $request): JsonResponse
    {
        $validated = $request->validate(['company_id' => ['required', 'integer']]);

        $client = $this->clientForCompany($request, (int) $validated['company_id']);

        if (!$client) {
            return ApiResponse::error('That company is not available for your account.', 422);
        }

        try {
            $result = ClientPaymentMethodService::startSetup(
                (int) $client->id,
                (int) $client->company_id,
                'stripe',
                FrontendUrl::to('client/payment-methods/added'),
                FrontendUrl::to('client/payment-methods'),
            );
        } catch (\Throwable $e) {
            // Never surface the gateway's own wording.
            return ApiResponse::error('Could not open the card entry page. Please try again.', 422);
        }

        return ApiResponse::success($result->toArray());
    }

    // ── POST /client/payment-methods/inline-setup ────────────────────────────

    /**
     * Start "Add a card" INSIDE the portal — no redirect.
     *
     * Returns the gateway's publishable key and a single-use client secret, and
     * nothing else. Those two are what the gateway's own iframe needs to
     * collect and authorise a card; neither can charge anything, and the secret
     * key never leaves the server.
     *
     * The card itself is still never seen by this app: the fields belong to the
     * gateway's cross-origin frame and the number goes straight from there to
     * the gateway. "Inline" describes where the form is drawn, not who handles
     * the card.
     */
    public function inlineSetup(Request $request): JsonResponse
    {
        $validated = $request->validate(['company_id' => ['required', 'integer']]);

        $client = $this->clientForCompany($request, (int) $validated['company_id']);

        if (!$client) {
            return ApiResponse::error('That company is not available for your account.', 422);
        }

        try {
            $setup = ClientPaymentMethodService::startInlineSetup(
                (int) $client->id,
                (int) $client->company_id,
                'stripe'
            );
        } catch (\Throwable) {
            // Never surface the gateway's own wording.
            return ApiResponse::error('Could not start secure card setup. Please try again.', 422);
        }

        return ApiResponse::success($setup);
    }

    // ── POST /client/payment-methods/inline-complete ─────────────────────────

    /**
     * Save the card an in-page setup left on file.
     *
     * The setup id comes from the browser, but whether it SUCCEEDED is asked of
     * the gateway, and the card is saved against the AUTHENTICATED client — so
     * a forged id can only fail to resolve, never attach a card to someone
     * else. Same guarantee, and same shape, as complete() above.
     */
    public function inlineComplete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id'      => ['required', 'integer'],
            'setup_intent_id' => ['required', 'string', 'max:255'],
        ]);

        $client = $this->clientForCompany($request, (int) $validated['company_id']);

        if (!$client) {
            return ApiResponse::error('That company is not available for your account.', 422);
        }

        $method = ClientPaymentMethodService::rememberFromSetupIntent(
            (int) $client->id,
            (int) $client->company_id,
            'stripe',
            $validated['setup_intent_id']
        );

        if (!$method) {
            return ApiResponse::error('We could not save that card. Please try again.', 422);
        }

        return ApiResponse::success(
            ['payment_method' => $this->present($method)],
            'Your payment method has been added successfully.'
        );
    }

    // ── POST /client/payment-methods/complete ────────────────────────────────

    /**
     * Called when the client returns from the hosted card page.
     *
     * The session id comes from the return URL, but the card is saved against
     * the AUTHENTICATED client — a forged session id can therefore only fail to
     * resolve, never attach a card to someone else.
     */
    public function complete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer'],
            'session_id' => ['required', 'string', 'max:255'],
        ]);

        $client = $this->clientForCompany($request, (int) $validated['company_id']);

        if (!$client) {
            return ApiResponse::error('That company is not available for your account.', 422);
        }

        $method = ClientPaymentMethodService::rememberFromSetup(
            (int) $client->id,
            (int) $client->company_id,
            'stripe',
            $validated['session_id']
        );

        if (!$method) {
            return ApiResponse::error('We could not save that card. Please try again.', 422);
        }

        return ApiResponse::success(
            ['payment_method' => $this->present($method)],
            'Your payment method has been added successfully.'
        );
    }

    // ── POST /client/payment-methods/{id}/default ────────────────────────────

    public function setDefault(Request $request, int $id): JsonResponse
    {
        $method = $this->ownMethod($request, $id);

        if (!$method) {
            return ApiResponse::error('That payment method is not available for your account.', 404);
        }

        ClientPaymentMethodService::setDefault($method);

        return ApiResponse::success(['payment_method' => $this->present($method->fresh())], 'Default payment method updated.');
    }

    // ── DELETE /client/payment-methods/{id} ──────────────────────────────────

    public function destroy(Request $request, int $id): JsonResponse
    {
        $method = $this->ownMethod($request, $id);

        if (!$method) {
            return ApiResponse::error('That payment method is not available for your account.', 404);
        }

        ClientPaymentMethodService::remove($method);

        return ApiResponse::success(null, 'Payment method removed.');
    }
}
