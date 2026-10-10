<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientPaymentMethod;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\ClientPaymentMethodService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Saved cards for Client Portal clients, and paying an invoice with one.
 *
 * Stripe is faked at the HTTP boundary (Http::fake) — these tests are about OUR
 * rules, not Stripe's. What they pin:
 *
 *  - A card is never charged without an explicit request naming it.
 *  - A card belonging to another client, or to the same client at another
 *    company, is not usable.
 *  - A declined charge leaves the invoice exactly as it was.
 *  - An invoice settled meanwhile is not charged again.
 *  - Nothing resembling a card number is ever stored.
 */
class SavedPaymentMethodTest extends TestCase
{
    use DatabaseTransactions;

    private int $adminId;
    private int $companyId;
    private User $user;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $packageId = DB::table('packages')->insertGetId([
            'name' => 'Test Package', 'tier' => 'basic', 'price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->adminId = DB::table('company_admins')->insertGetId([
            'package_id' => $packageId,
            'name' => 'SM Admin', 'email' => 'sm+' . uniqid() . '@example.test',
            'password' => Hash::make('password'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->companyId = $this->makeCompany();

        $this->user = User::create([
            'company_id' => $this->companyId,
            'name' => 'Portal Client', 'email' => 'sc+' . uniqid() . '@example.test',
            'password' => Hash::make('password'), 'role_type' => 'client', 'is_active' => true,
        ]);

        $this->client = Client::create([
            'company_id' => $this->companyId, 'user_id' => $this->user->id,
            'name' => 'Portal Client', 'email' => $this->user->email, 'portal_access' => true,
        ]);
    }

    private function makeCompany(): int
    {
        $id = DB::table('companies')->insertGetId([
            'admin_id' => $this->adminId, 'name' => 'SM Co ' . uniqid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (['client_portal', 'invoices'] as $module) {
            DB::table('company_modules')->insert([
                'company_id' => $id, 'module_key' => $module, 'is_enabled' => true, 'updated_at' => now(),
            ]);
        }

        // Through the model, not a raw insert: `config` is cast
        // `encrypted:array`, and hand-encrypting with encrypt() writes a
        // PHP-serialised payload the cast cannot read back — it decodes to
        // null, and the gateway then charges with no credentials.
        \App\Models\CompanyPaymentGateway::create([
            'company_admin_id' => $this->adminId,
            'company_id' => $id,
            'gateway' => 'stripe',
            'label' => 'Stripe',
            'is_active' => true,
            'is_default' => true,
            // publishable_key is what the in-portal card form is handed; it is
            // public by design, unlike secret_key, which must never leave the
            // server — the inline tests below assert exactly that.
            'config' => ['secret_key' => 'sk_test_x', 'publishable_key' => 'pk_test_x', 'mode' => 'test'],
        ]);

        return $id;
    }

    private function makeInvoice(float $total = 400, float $paid = 0, string $status = 'sent'): Invoice
    {
        return Invoice::create([
            'company_id' => $this->companyId, 'client_id' => $this->client->id,
            'invoice_number' => 'INV-' . random_int(10000, 99999),
            'subtotal' => $total, 'total_amount' => $total, 'paid_amount' => $paid,
            'currency' => 'USD', 'status' => $status,
        ]);
    }

    private function makeCard(?int $clientId = null, ?int $companyId = null): ClientPaymentMethod
    {
        return ClientPaymentMethod::create([
            'client_id' => $clientId ?? $this->client->id,
            'company_id' => $companyId ?? $this->companyId,
            'gateway' => 'stripe',
            'gateway_customer_id' => 'cus_' . uniqid(),
            'payment_method_token' => 'pm_' . uniqid(),
            'card_brand' => 'visa', 'card_last4' => '4242',
            'exp_month' => 12, 'exp_year' => (int) now()->addYear()->format('Y'),
            'is_default' => true,
        ]);
    }

    private function actingAsClient(): self
    {
        Sanctum::actingAs($this->user, ['role:client']);

        return $this;
    }

    /** Put an invoice in the assistant's view. */
    private function openOn(Invoice $invoice): void
    {
        $this->actingAsClient()->getJson('/api/client/payment-assistant');
        $this->actingAsClient()->postJson('/api/client/payment-assistant/invoice', ['invoice_id' => $invoice->id])
            ->assertOk();
    }

    // ── Storage hygiene ──────────────────────────────────────────────────────

    /** Nothing that could reconstruct a card may be stored, or serialised out. */
    public function test_a_saved_card_stores_only_gateway_references(): void
    {
        $card = $this->makeCard();

        $columns = array_keys(DB::table('client_payment_methods')->first((array) '*') ? (array) DB::table('client_payment_methods')->first() : []);

        foreach (['card_number', 'cvv', 'cvc', 'pan', 'pin'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns, "client_payment_methods must not have a {$forbidden} column.");
        }

        // The gateway handles are hidden from any JSON this model appears in.
        $json = $card->toArray();
        $this->assertArrayNotHasKey('payment_method_token', $json);
        $this->assertArrayNotHasKey('gateway_customer_id', $json);
    }

    public function test_a_card_is_labelled_without_revealing_anything(): void
    {
        $this->assertSame('Visa •••• 4242', $this->makeCard()->label());
    }

    // ── Offering the card ────────────────────────────────────────────────────

    public function test_confirming_offers_a_saved_card_instead_of_hosted_checkout(): void
    {
        $card = $this->makeCard();
        $invoice = $this->makeInvoice();
        $this->openOn($invoice);

        $this->actingAsClient()->postJson('/api/client/payment-assistant/confirm')
            ->assertOk()
            ->assertJsonPath('data.saved_methods.0.id', $card->id)
            ->assertJsonPath('data.saved_methods.0.label', 'Visa •••• 4242');
    }

    /** Confirming still must not charge — that is a separate, explicit request. */
    public function test_confirming_does_not_charge_the_saved_card(): void
    {
        Http::fake();
        $this->makeCard();
        $invoice = $this->makeInvoice();
        $this->openOn($invoice);

        $this->actingAsClient()->postJson('/api/client/payment-assistant/confirm')->assertOk();

        Http::assertNothingSent();
        $this->assertSame(0, $invoice->payments()->count());
        $this->assertSame('sent', $invoice->fresh()->status);
    }

    // ── Charging ─────────────────────────────────────────────────────────────

    public function test_paying_with_a_saved_card_settles_the_invoice(): void
    {
        Http::fake([
            '*api.stripe.com/v1/payment_intents*' => Http::response(['id' => 'pi_ok', 'status' => 'succeeded'], 200),
        ]);

        $card = $this->makeCard();
        $invoice = $this->makeInvoice(400);
        $this->openOn($invoice);
        $this->actingAsClient()->postJson('/api/client/payment-assistant/confirm');

        $response = $this->actingAsClient()
            ->postJson('/api/client/payment-assistant/pay-saved', ['payment_method_id' => $card->id])
            ->assertOk();

        $this->assertTrue(
            $response->json('data.paid'),
            'Charge did not settle. Assistant said: ' . json_encode($response->json('data.message'))
        );

        $fresh = $invoice->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertEquals(400, (float) $fresh->paid_amount);
        $this->assertSame('pi_ok', $fresh->payments()->latest('id')->first()->gateway_ref);
    }

    /** A decline must leave the invoice untouched and say nothing technical. */
    public function test_a_declined_card_leaves_the_invoice_unpaid(): void
    {
        Http::fake([
            '*api.stripe.com/v1/payment_intents*' => Http::response([
                'error' => ['code' => 'card_declined', 'message' => 'Your card was declined.'],
            ], 402),
        ]);

        $card = $this->makeCard();
        $invoice = $this->makeInvoice(400);
        $this->openOn($invoice);
        $this->actingAsClient()->postJson('/api/client/payment-assistant/confirm');

        $response = $this->actingAsClient()
            ->postJson('/api/client/payment-assistant/pay-saved', ['payment_method_id' => $card->id])
            ->assertOk()
            ->assertJsonPath('data.paid', false);

        $fresh = $invoice->fresh();
        $this->assertSame('sent', $fresh->status);
        $this->assertEquals(0, (float) $fresh->paid_amount);
        $this->assertSame('failed', $fresh->payments()->latest('id')->first()->status);

        // The raw gateway wording must not reach the client.
        $this->assertStringNotContainsString('card_declined', (string) $response->json('data.message'));
    }

    /** Duplicate protection: an invoice settled meanwhile is not charged again. */
    public function test_an_invoice_paid_meanwhile_is_not_charged(): void
    {
        Http::fake();

        $card = $this->makeCard();
        $invoice = $this->makeInvoice(400);
        $this->openOn($invoice);
        $this->actingAsClient()->postJson('/api/client/payment-assistant/confirm');

        $invoice->update(['paid_amount' => 400, 'status' => 'paid']);

        $this->actingAsClient()
            ->postJson('/api/client/payment-assistant/pay-saved', ['payment_method_id' => $card->id])
            ->assertOk()
            ->assertJsonPath('data.paid', false);

        Http::assertNothingSent();
    }

    /** An in-flight attempt blocks a second one rather than charging twice. */
    public function test_a_pending_payment_blocks_a_second_charge(): void
    {
        Http::fake();

        $card = $this->makeCard();
        $invoice = $this->makeInvoice(400);

        Payment::create([
            'invoice_id' => $invoice->id, 'amount' => 400, 'currency' => 'USD',
            'method' => 'card', 'gateway' => 'stripe', 'status' => 'pending',
            'payment_date' => now()->toDateString(),
        ]);

        [$ok, $message] = ClientPaymentMethodService::charge($invoice, $card);

        $this->assertFalse($ok);
        $this->assertStringContainsString('already being processed', $message);
        Http::assertNothingSent();
    }

    // ── Ownership ────────────────────────────────────────────────────────────

    public function test_another_clients_card_cannot_be_used(): void
    {
        Http::fake();

        $otherUser = User::create([
            'company_id' => $this->companyId,
            'name' => 'Other', 'email' => 'o+' . uniqid() . '@example.test',
            'password' => Hash::make('password'), 'role_type' => 'client', 'is_active' => true,
        ]);
        $otherClient = Client::create([
            'company_id' => $this->companyId, 'user_id' => $otherUser->id,
            'name' => 'Other', 'email' => $otherUser->email, 'portal_access' => true,
        ]);
        $theirCard = $this->makeCard($otherClient->id);

        $invoice = $this->makeInvoice();
        $this->openOn($invoice);
        $this->actingAsClient()->postJson('/api/client/payment-assistant/confirm');

        $this->actingAsClient()
            ->postJson('/api/client/payment-assistant/pay-saved', ['payment_method_id' => $theirCard->id])
            ->assertStatus(404);

        Http::assertNothingSent();
        $this->assertSame('sent', $invoice->fresh()->status);
    }

    public function test_a_card_saved_for_another_company_is_not_offered(): void
    {
        $otherCompany = $this->makeCompany();
        $this->makeCard($this->client->id, $otherCompany);

        $invoice = $this->makeInvoice();
        $this->openOn($invoice);

        $this->actingAsClient()->postJson('/api/client/payment-assistant/confirm')
            ->assertOk()
            ->assertJsonMissingPath('data.saved_methods');
    }

    // ── Adding a card without paying (§10 / §17) ─────────────────────────────

    public function test_the_payment_methods_list_shows_only_this_clients_cards(): void
    {
        $mine = $this->makeCard();

        $otherUser = User::create([
            'company_id' => $this->companyId,
            'name' => 'Other', 'email' => 'pmo+' . uniqid() . '@example.test',
            'password' => Hash::make('password'), 'role_type' => 'client', 'is_active' => true,
        ]);
        $otherClient = Client::create([
            'company_id' => $this->companyId, 'user_id' => $otherUser->id,
            'name' => 'Other', 'email' => $otherUser->email, 'portal_access' => true,
        ]);
        $this->makeCard($otherClient->id);

        $response = $this->actingAsClient()->getJson('/api/client/payment-methods')->assertOk();

        $ids = collect($response->json('data.companies'))
            ->flatMap(fn ($c) => collect($c['payment_methods'])->pluck('id'))
            ->all();

        $this->assertSame([$mine->id], $ids);
    }

    /** The stored token must never appear in a response. */
    public function test_the_list_never_exposes_the_gateway_token(): void
    {
        $this->makeCard();

        $body = $this->actingAsClient()->getJson('/api/client/payment-methods')->assertOk()->content();

        $this->assertStringNotContainsString('payment_method_token', $body);
        $this->assertStringNotContainsString('gateway_customer_id', $body);
        $this->assertStringNotContainsString('pm_', $body);
    }

    /** Add Payment Method opens a hosted page and charges nothing. */
    public function test_starting_setup_returns_a_hosted_page_and_charges_nothing(): void
    {
        Http::fake([
            '*api.stripe.com/v1/checkout/sessions*' => Http::response([
                'id' => 'cs_setup_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_setup_1',
            ], 200),
        ]);

        $this->actingAsClient()
            ->postJson('/api/client/payment-methods/setup', ['company_id' => $this->companyId])
            ->assertOk()
            ->assertJsonPath('data.navigation', 'redirect')
            ->assertJsonPath('data.action', 'https://checkout.stripe.com/c/pay/cs_setup_1');

        $this->assertSame(0, DB::table('payments')->count(), 'Adding a card must never create a payment.');
    }

    public function test_setup_for_a_company_the_client_has_no_record_for_is_refused(): void
    {
        $otherCompany = $this->makeCompany();

        $this->actingAsClient()
            ->postJson('/api/client/payment-methods/setup', ['company_id' => $otherCompany])
            ->assertStatus(422);
    }

    /** Completing setup stores the card against the authenticated client. */
    public function test_completing_setup_saves_the_card(): void
    {
        Http::fake([
            '*api.stripe.com/v1/checkout/sessions/cs_setup_1*' => Http::response([
                'id'       => 'cs_setup_1',
                'customer' => 'cus_new',
                'setup_intent' => [
                    'payment_method' => [
                        'id'   => 'pm_new_1',
                        'type' => 'card',
                        'card' => ['brand' => 'visa', 'last4' => '1881', 'exp_month' => 4, 'exp_year' => (int) now()->addYears(2)->format('Y')],
                    ],
                ],
            ], 200),
        ]);

        $this->actingAsClient()
            ->postJson('/api/client/payment-methods/complete', [
                'company_id' => $this->companyId,
                'session_id' => 'cs_setup_1',
            ])
            ->assertOk()
            ->assertJsonPath('data.payment_method.label', 'Visa •••• 1881');

        $this->assertDatabaseHas('client_payment_methods', [
            'client_id'            => $this->client->id,
            'company_id'           => $this->companyId,
            'payment_method_token' => 'pm_new_1',
            'is_default'           => 1,
        ]);
    }

    // ── Adding a card INSIDE the portal (no redirect) ────────────────────────

    /**
     * Fakes for the in-page flow.
     *
     * POST and GET on /v1/setup_intents are different calls with the same URL
     * prefix, so they are separated by method rather than by two patterns that
     * would shadow each other.
     */
    private function fakeInlineStripe(array $getIntent = []): void
    {
        Http::fake([
            '*api.stripe.com/v1/customers*' => Http::response(['id' => 'cus_inline'], 200),
            '*api.stripe.com/v1/setup_intents*' => function ($request) use ($getIntent) {
                if ($request->method() === 'POST') {
                    return Http::response([
                        'id'            => 'seti_inline_1',
                        'client_secret' => 'seti_inline_1_secret_abc',
                        'status'        => 'requires_payment_method',
                    ], 200);
                }

                return Http::response($getIntent ?: [
                    'id'       => 'seti_inline_1',
                    'status'   => 'succeeded',
                    'customer' => 'cus_inline',
                    'payment_method' => [
                        'id'   => 'pm_inline_1',
                        'type' => 'card',
                        'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 9, 'exp_year' => (int) now()->addYears(3)->format('Y')],
                    ],
                ], 200);
            },
        ]);
    }

    /**
     * The client stays in the portal, and gets only what a card form needs:
     * a publishable key and a one-card client secret.
     */
    public function test_a_card_can_be_started_from_inside_the_portal_with_no_redirect(): void
    {
        $this->fakeInlineStripe();

        $response = $this->actingAsClient()
            ->postJson('/api/client/payment-methods/inline-setup', ['company_id' => $this->companyId])
            ->assertOk()
            ->assertJsonPath('data.publishable_key', 'pk_test_x')
            ->assertJsonPath('data.setup_intent_id', 'seti_inline_1');

        $this->assertSame('seti_inline_1_secret_abc', $response->json('data.client_secret'));

        // No hosted page to send them to, and nothing charged.
        $this->assertNull($response->json('data.action'));
        $this->assertNull($response->json('data.navigation'));
        $this->assertSame(0, DB::table('payments')->count());

        // The secret key must never reach the browser.
        $this->assertStringNotContainsString('sk_test_x', $response->getContent());
    }

    /**
     * A second card joins the client's existing gateway customer.
     *
     * Otherwise each card would sit on its own customer and the off-session
     * charge, which names both, could not find them.
     */
    public function test_a_second_card_reuses_the_clients_existing_gateway_customer(): void
    {
        $existing = $this->makeCard();
        $this->fakeInlineStripe();

        $this->actingAsClient()
            ->postJson('/api/client/payment-methods/inline-setup', ['company_id' => $this->companyId])
            ->assertOk();

        // No new customer was created …
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/customers'));

        // … and the setup was opened against the one they already have.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/setup_intents')
            && $request->method() === 'POST'
            && ($request['customer'] ?? null) === $existing->gateway_customer_id);
    }

    /** What gets stored, and what is shown back, is brand + last four only. */
    public function test_an_inline_card_is_saved_with_only_safe_details(): void
    {
        $this->fakeInlineStripe();

        $response = $this->actingAsClient()
            ->postJson('/api/client/payment-methods/inline-complete', [
                'company_id'      => $this->companyId,
                'setup_intent_id' => 'seti_inline_1',
            ])
            ->assertOk()
            ->assertJsonPath('data.payment_method.label', 'Visa •••• 4242');

        $this->assertDatabaseHas('client_payment_methods', [
            'client_id'            => $this->client->id,
            'company_id'           => $this->companyId,
            'payment_method_token' => 'pm_inline_1',
            'card_brand'           => 'visa',
            'card_last4'           => '4242',
        ]);

        // The gateway handles the number; this app never has one to leak.
        $body = $response->getContent();
        $this->assertStringNotContainsString('pm_inline_1', $body, 'The gateway token must stay server-side.');
        $this->assertStringNotContainsString('cus_inline', $body, 'The gateway customer id must stay server-side.');
        $this->assertStringNotContainsString('sk_test_x', $body);

        // Nothing resembling a full card number is anywhere in the row.
        $row = (array) DB::table('client_payment_methods')->where('payment_method_token', 'pm_inline_1')->first();
        foreach ($row as $value) {
            $this->assertDoesNotMatchRegularExpression('/\b\d{13,19}\b/', (string) $value);
        }
    }

    /** A setup the client never finished must not become a saved card. */
    public function test_an_unfinished_setup_is_not_saved(): void
    {
        $this->fakeInlineStripe([
            'id'     => 'seti_inline_1',
            'status' => 'requires_payment_method',
        ]);

        $this->actingAsClient()
            ->postJson('/api/client/payment-methods/inline-complete', [
                'company_id'      => $this->companyId,
                'setup_intent_id' => 'seti_inline_1',
            ])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('client_payment_methods')->count());
    }

    public function test_inline_setup_for_a_company_the_client_has_no_record_for_is_refused(): void
    {
        $this->fakeInlineStripe();
        $otherCompany = $this->makeCompany();

        $this->actingAsClient()
            ->postJson('/api/client/payment-methods/inline-setup', ['company_id' => $otherCompany])
            ->assertStatus(422);

        $this->actingAsClient()
            ->postJson('/api/client/payment-methods/inline-complete', [
                'company_id'      => $otherCompany,
                'setup_intent_id' => 'seti_inline_1',
            ])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    /** Several cards, all listed, all labelled safely. */
    public function test_several_saved_cards_are_all_listed(): void
    {
        $first  = $this->makeCard();
        $second = $this->makeCard();
        $second->update(['is_default' => false, 'card_brand' => 'mastercard', 'card_last4' => '5100']);

        $response = $this->actingAsClient()->getJson('/api/client/payment-methods')->assertOk();

        $labels = collect($response->json('data.companies.0.payment_methods'))->pluck('label')->all();

        $this->assertContains('Visa •••• 4242', $labels);
        $this->assertContains('Mastercard •••• 5100', $labels);
        $this->assertTrue($response->json('data.companies.0.can_add_inline'));
        $this->assertStringNotContainsString($first->payment_method_token, $response->getContent());
        $this->assertStringNotContainsString($second->payment_method_token, $response->getContent());
    }

    /** With no card on file the assistant opens the form in the chat, not a redirect. */
    public function test_the_assistant_opens_the_card_form_in_place(): void
    {
        Http::fake();
        $invoice = $this->makeInvoice(250);

        $payNow = $this->actingAsClient()->postJson('/api/client/assistant/action', [
            'action'  => 'pay_invoice',
            'payload' => ['invoice_id' => $invoice->id],
        ])->assertOk();

        $this->assertContains('start_card_setup', array_column($payNow->json('data.actions'), 'action'));

        $form = $this->actingAsClient()->postJson('/api/client/assistant/action', [
            'action'  => 'start_card_setup',
            'payload' => ['company_id' => $this->companyId],
        ])->assertOk();

        $form->assertJsonPath('data.open_card_form.company_id', $this->companyId);
        // The hosted-page fallback must NOT also fire.
        $this->assertNull($form->json('data.open_card_setup'));
        $this->assertSame(0, DB::table('payments')->count());
    }

    /** Cards saved from the portal show up in the assistant's payment list. */
    public function test_saved_cards_appear_in_the_assistant_payment_flow(): void
    {
        Http::fake();
        $visa = $this->makeCard();
        $mc   = $this->makeCard();
        $mc->update(['is_default' => false, 'card_brand' => 'mastercard', 'card_last4' => '5100']);

        $invoice = $this->makeInvoice(250);

        $response = $this->actingAsClient()->postJson('/api/client/assistant/action', [
            'action'  => 'pay_invoice',
            'payload' => ['invoice_id' => $invoice->id],
        ])->assertOk();

        $labels = array_column($response->json('data.actions'), 'label');

        $this->assertContains('Visa •••• 4242', $labels);
        $this->assertContains('Mastercard •••• 5100', $labels);
        $this->assertContains('Add a new card', $labels);

        // Offering a card is not charging one.
        Http::assertNothingSent();
        $this->assertSame(0, $invoice->fresh()->payments()->count());

        // And neither card's token travelled.
        $this->assertStringNotContainsString($visa->payment_method_token, $response->getContent());
        $this->assertStringNotContainsString($mc->payment_method_token, $response->getContent());
    }

    /** Another client's card is not reachable through the inline endpoints either. */
    public function test_another_clients_card_is_not_reachable_inline(): void
    {
        $otherUser = User::create([
            'company_id' => $this->companyId,
            'name' => 'Other', 'email' => 'other+' . uniqid() . '@example.test',
            'password' => Hash::make('password'), 'role_type' => 'client', 'is_active' => true,
        ]);
        $otherClient = Client::create([
            'company_id' => $this->companyId, 'user_id' => $otherUser->id,
            'name' => 'Other', 'email' => $otherUser->email, 'portal_access' => true,
        ]);
        $theirCard = $this->makeCard($otherClient->id);

        $listed = $this->actingAsClient()->getJson('/api/client/payment-methods')->assertOk();

        $this->assertStringNotContainsString($theirCard->payment_method_token, $listed->getContent());
        $this->assertSame([], $listed->json('data.companies.0.payment_methods'));

        $this->actingAsClient()
            ->deleteJson("/api/client/payment-methods/{$theirCard->id}")
            ->assertNotFound();
    }

    public function test_removing_a_card_promotes_the_next_one_to_default(): void
    {
        $first = $this->makeCard();
        $second = $this->makeCard();
        $second->update(['is_default' => false]);

        $this->actingAsClient()->deleteJson("/api/client/payment-methods/{$first->id}")->assertOk();

        $this->assertDatabaseMissing('client_payment_methods', ['id' => $first->id]);
        $this->assertTrue($second->fresh()->is_default, 'The remaining card should have become the default.');
    }

    public function test_another_clients_card_cannot_be_removed(): void
    {
        $otherUser = User::create([
            'company_id' => $this->companyId,
            'name' => 'Other', 'email' => 'rm+' . uniqid() . '@example.test',
            'password' => Hash::make('password'), 'role_type' => 'client', 'is_active' => true,
        ]);
        $otherClient = Client::create([
            'company_id' => $this->companyId, 'user_id' => $otherUser->id,
            'name' => 'Other', 'email' => $otherUser->email, 'portal_access' => true,
        ]);
        $theirCard = $this->makeCard($otherClient->id);

        $this->actingAsClient()->deleteJson("/api/client/payment-methods/{$theirCard->id}")->assertStatus(404);

        $this->assertDatabaseHas('client_payment_methods', ['id' => $theirCard->id]);
    }

    /** An expired card is not offered, however valid it looks otherwise. */
    public function test_an_expired_card_is_not_offered(): void
    {
        $card = $this->makeCard();
        $card->update(['exp_month' => 1, 'exp_year' => 2020]);

        $invoice = $this->makeInvoice();
        $this->openOn($invoice);

        $this->actingAsClient()->postJson('/api/client/payment-assistant/confirm')
            ->assertOk()
            ->assertJsonMissingPath('data.saved_methods');
    }
}
