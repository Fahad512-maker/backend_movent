<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use App\Services\PaymentAssistantService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Client Portal Payment Assistant.
 *
 * Two things carry real risk here and are what this file mostly pins:
 *
 *  1. SCOPE. An invoice lookup must never reach another client's invoice, or
 *     the same person's invoice at a different company. The assistant takes an
 *     invoice NUMBER from free text, so the scoping has to hold against input
 *     the client fully controls.
 *
 *  2. NO ACCIDENTAL CHARGE. Typing a number, or pressing Pay, must not move
 *     money — confirmation only hands back a checkout to complete on the
 *     gateway's own page, and an invoice that was settled meanwhile must be
 *     refused rather than charged twice.
 */
class PaymentAssistantTest extends TestCase
{
    use DatabaseTransactions;

    private int $adminId;
    private int $companyA;
    private int $companyB;
    private User $user;
    private Client $clientA;

    protected function setUp(): void
    {
        parent::setUp();

        $packageId = DB::table('packages')->insertGetId([
            'name' => 'Test Package', 'tier' => 'basic', 'price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->adminId = DB::table('company_admins')->insertGetId([
            'package_id' => $packageId,
            'name' => 'PA Admin', 'email' => 'paadmin+' . uniqid() . '@example.test',
            'password' => Hash::make('password'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->companyA = $this->makeCompany('Company A');
        $this->companyB = $this->makeCompany('Company B');

        $this->user = User::create([
            'company_id' => $this->companyA,
            'name' => 'Portal Client', 'email' => 'pa+' . uniqid() . '@example.test',
            'password' => Hash::make('password'), 'role_type' => 'client', 'is_active' => true,
        ]);

        $this->clientA = $this->makeClient($this->companyA);
    }

    private function makeCompany(string $name): int
    {
        $id = DB::table('companies')->insertGetId([
            'admin_id' => $this->adminId, 'name' => $name,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (['client_portal', 'invoices'] as $module) {
            DB::table('company_modules')->insert([
                'company_id' => $id, 'module_key' => $module, 'is_enabled' => true,
                'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function makeClient(int $companyId): Client
    {
        return Client::create([
            'company_id' => $companyId,
            'user_id' => $this->user->id,
            'name' => 'Portal Client',
            'email' => $this->user->email,
            'portal_access' => true,
        ]);
    }

    private function makeInvoice(Client $client, string $number, float $total, float $paid = 0, string $status = 'sent'): Invoice
    {
        return Invoice::create([
            'company_id' => $client->company_id,
            'client_id' => $client->id,
            'invoice_number' => $number,
            'subtotal' => $total,
            'total_amount' => $total,
            'paid_amount' => $paid,
            'status' => $status,
        ]);
    }

    private function actingAsClient(): self
    {
        Sanctum::actingAs($this->user, ['role:client']);

        return $this;
    }

    // ── Rule-based parsing (no AI) ───────────────────────────────────────────

    /**
     * The shapes people actually type. Pure function, so it is exercised
     * directly rather than through eight HTTP round trips.
     */
    public function test_invoice_numbers_are_extracted_from_natural_phrasings(): void
    {
        foreach ([
            'INV-1025'             => '1025',
            'inv 1025'             => '1025',
            'pay INV-1025'         => '1025',
            'pay invoice 1025'     => '1025',
            'invoice #1025'        => '1025',
            '#1025'                => '1025',
            '1025'                 => '1025',
            '  Invoice No. 1025 '  => '1025',
            // Mid-sentence, which is how people actually type.
            'kindly clear invoice 1025 today'  => '1025',
            'please settle bill-4400 asap'     => '4400',
        ] as $input => $expected) {
            $this->assertSame($expected, PaymentAssistantService::extractInvoiceNumber($input), "Failed on: {$input}");
        }
    }

    /**
     * Multi-segment references like INV-2026-0022.
     *
     * The earlier version took only the first run of digits, so INV-2026-0022
     * came back as "2026" and found nothing — the invoice existed and the
     * assistant said it did not. The whole reference is kept now, however the
     * client punctuated it.
     */
    public function test_multi_segment_invoice_numbers_are_kept_whole(): void
    {
        foreach ([
            'INV-2026-0022'          => '2026-0022',
            'invoice INV-2026-0022'  => '2026-0022',
            'pay INV-2026-0022 now'  => '2026-0022',
            'INV/2026/0022'          => '2026/0022',
            'inv 2026 0022'          => '2026 0022',
            '2026-0022'              => '2026-0022',
            // A scheme with its own prefix keeps that prefix — it is part of
            // the number, unlike the generic inv/invoice/bill labels.
            'ABC-2026-11'            => 'abc-2026-11',
            'SR/2026/0007'           => 'sr/2026/0007',
        ] as $input => $expected) {
            $this->assertSame($expected, PaymentAssistantService::extractInvoiceNumber($input), "Failed on: {$input}");
        }
    }

    /**
     * However the client punctuates it, the same invoice is found — matching
     * compares both sides with separators stripped.
     */
    public function test_an_invoice_is_found_however_its_number_is_punctuated(): void
    {
        $invoice = $this->makeInvoice($this->clientA, 'INV-2026-0022', 300);

        foreach (['INV-2026-0022', 'inv 2026 0022', '2026-0022', '20260022', 'INV/2026/0022'] as $typed) {
            $ref = PaymentAssistantService::extractInvoiceNumber($typed);
            $this->assertNotNull($ref, "Nothing extracted from: {$typed}");

            $found = PaymentAssistantService::matchInvoice($ref, [$this->clientA->id], $this->companyA);

            $this->assertNotNull($found, "No invoice found for: {$typed}");
            $this->assertSame($invoice->id, $found->id, "Wrong invoice for: {$typed}");
        }
    }

    /** A near-miss must still miss — the end-anchored match is not a substring search. */
    public function test_a_different_invoice_number_is_not_matched(): void
    {
        $this->makeInvoice($this->clientA, 'INV-2026-0022', 300);

        $ref = PaymentAssistantService::extractInvoiceNumber('INV-2026-9999');

        $this->assertNull(PaymentAssistantService::matchInvoice($ref, [$this->clientA->id], $this->companyA));
    }

    /**
     * The regression that mattered: a LABELLED invoice number must win over any
     * other number in the sentence.
     *
     * Taking the first number instead read "Send $1000 to ap@acmecorp.com for
     * INV-8271" as invoice 1000 — the amount — and loaded the wrong invoice (or
     * none) while looking like it had understood.
     */
    public function test_a_labelled_invoice_number_beats_other_numbers_in_the_sentence(): void
    {
        foreach ([
            'Send $1000 to ap@acmecorp.com for INV-8271' => '8271',
            'I want to pay 500 for invoice 8271'         => '8271',
            'transfer 2,500.00 USD against invoice 9100' => '9100',
            // An email with digits in it is not a source of invoice numbers.
            'pay ap2024@acme.com for inv 7001'           => '7001',
        ] as $input => $expected) {
            $this->assertSame($expected, PaymentAssistantService::extractInvoiceNumber($input), "Failed on: {$input}");
        }
    }

    public function test_input_with_no_number_is_not_guessed_at(): void
    {
        foreach (['hello', 'pay my invoice please', '', '   '] as $input) {
            $this->assertNull(PaymentAssistantService::extractInvoiceNumber($input));
        }
    }

    /**
     * An amount on its own is money, not an invoice number, and two unlabelled
     * numbers are genuinely ambiguous — the assistant asks again rather than
     * loading whichever it saw first.
     */
    public function test_amounts_and_ambiguous_input_are_not_read_as_invoice_numbers(): void
    {
        foreach (['$1000', 'USD 2,500.00', 'pay 500 and 700'] as $input) {
            $this->assertNull(PaymentAssistantService::extractInvoiceNumber($input), "Failed on: {$input}");
        }
    }

    // ── Opening ──────────────────────────────────────────────────────────────

    public function test_a_single_company_is_selected_automatically(): void
    {
        $this->actingAsClient()->getJson('/api/client/payment-assistant')
            ->assertOk()
            ->assertJsonPath('data.state', 'waiting_for_invoice')
            ->assertJsonPath('data.company.id', $this->companyA);
    }

    public function test_several_companies_are_offered_for_selection(): void
    {
        $this->makeClient($this->companyB);

        $this->actingAsClient()->getJson('/api/client/payment-assistant')
            ->assertOk()
            ->assertJsonPath('data.state', 'company_selection')
            ->assertJsonCount(2, 'data.companies');
    }

    public function test_the_assistant_requires_a_signed_in_client(): void
    {
        $this->getJson('/api/client/payment-assistant')->assertStatus(401);
    }

    // ── Lookup and scope ─────────────────────────────────────────────────────

    public function test_an_invoice_is_found_from_a_typed_number(): void
    {
        $invoice = $this->makeInvoice($this->clientA, 'INV-1025', 500, 100);

        $this->actingAsClient()->getJson('/api/client/payment-assistant');

        $this->actingAsClient()->postJson('/api/client/payment-assistant/message', ['text' => 'pay INV-1025'])
            ->assertOk()
            ->assertJsonPath('data.state', 'invoice_found')
            ->assertJsonPath('data.invoice.id', $invoice->id)
            ->assertJsonPath('data.invoice.amount_due', 400);
    }

    /** The core scoping guarantee: another client's invoice is simply not found. */
    public function test_another_clients_invoice_is_not_reachable(): void
    {
        $otherUser = User::create([
            'company_id' => $this->companyA,
            'name' => 'Other', 'email' => 'other+' . uniqid() . '@example.test',
            'password' => Hash::make('password'), 'role_type' => 'client', 'is_active' => true,
        ]);
        $otherClient = Client::create([
            'company_id' => $this->companyA, 'user_id' => $otherUser->id,
            'name' => 'Other', 'email' => $otherUser->email, 'portal_access' => true,
        ]);
        $theirInvoice = $this->makeInvoice($otherClient, 'INV-9001', 500);

        $this->actingAsClient()->getJson('/api/client/payment-assistant');

        $this->actingAsClient()->postJson('/api/client/payment-assistant/message', ['text' => 'INV-9001'])
            ->assertOk()
            ->assertJsonPath('data.invoice', null);

        // And not by id either.
        $this->actingAsClient()->postJson('/api/client/payment-assistant/invoice', ['invoice_id' => $theirInvoice->id])
            ->assertStatus(404);
    }

    /** Same person, different company — still out of scope for the selected one. */
    public function test_an_invoice_from_another_company_is_not_reachable(): void
    {
        $clientB = $this->makeClient($this->companyB);
        $invoiceB = $this->makeInvoice($clientB, 'INV-7777', 300);

        // Two companies now, so pick A explicitly.
        $this->actingAsClient()->postJson('/api/client/payment-assistant/company', ['company_id' => $this->companyA])
            ->assertOk();

        $this->actingAsClient()->postJson('/api/client/payment-assistant/message', ['text' => 'INV-7777'])
            ->assertOk()
            ->assertJsonPath('data.invoice', null);

        $this->actingAsClient()->postJson('/api/client/payment-assistant/invoice', ['invoice_id' => $invoiceB->id])
            ->assertStatus(404);
    }

    public function test_a_company_the_client_has_no_record_for_is_refused(): void
    {
        $this->actingAsClient()->postJson('/api/client/payment-assistant/company', ['company_id' => $this->companyB])
            ->assertStatus(422);
    }

    public function test_an_unknown_invoice_number_reports_not_found(): void
    {
        $this->actingAsClient()->getJson('/api/client/payment-assistant');

        $this->actingAsClient()->postJson('/api/client/payment-assistant/message', ['text' => 'INV-4040'])
            ->assertOk()
            ->assertJsonPath('data.invoice', null)
            ->assertJsonPath('data.state', 'waiting_for_invoice');
    }

    // ── Already paid ─────────────────────────────────────────────────────────

    public function test_an_already_paid_invoice_is_reported_and_not_payable(): void
    {
        $this->makeInvoice($this->clientA, 'INV-2000', 500, 500, 'paid');

        $this->actingAsClient()->getJson('/api/client/payment-assistant');

        $this->actingAsClient()->postJson('/api/client/payment-assistant/message', ['text' => 'INV-2000'])
            ->assertOk()
            ->assertJsonPath('data.already_paid', true)
            ->assertJsonPath('data.invoice.is_payable', false);
    }

    // ── Confirmation ─────────────────────────────────────────────────────────

    /** Confirming hands back a checkout to complete — it does not charge here. */
    public function test_confirming_returns_a_checkout_handoff_and_creates_no_payment(): void
    {
        $invoice = $this->makeInvoice($this->clientA, 'INV-3000', 400);

        $this->actingAsClient()->getJson('/api/client/payment-assistant');
        $this->actingAsClient()->postJson('/api/client/payment-assistant/message', ['text' => 'INV-3000']);

        $this->actingAsClient()->postJson('/api/client/payment-assistant/confirm')
            ->assertOk()
            ->assertJsonPath('data.checkout_invoice_id', $invoice->id)
            ->assertJsonPath('data.amount_due', 400);

        $this->assertSame(0, $invoice->payments()->count(), 'Confirming must not create a payment by itself.');
        $this->assertSame('sent', $invoice->fresh()->status, 'The invoice status must not move.');
    }

    /**
     * Duplicate protection: an invoice settled while the assistant sat open is
     * refused rather than charged again.
     */
    public function test_an_invoice_paid_meanwhile_is_refused_at_confirmation(): void
    {
        $invoice = $this->makeInvoice($this->clientA, 'INV-3100', 400);

        $this->actingAsClient()->getJson('/api/client/payment-assistant');
        $this->actingAsClient()->postJson('/api/client/payment-assistant/message', ['text' => 'INV-3100']);

        // Settled elsewhere — the public link, or an admin recording a transfer.
        $invoice->update(['paid_amount' => 400, 'status' => 'paid']);

        $this->actingAsClient()->postJson('/api/client/payment-assistant/confirm')
            ->assertOk()
            ->assertJsonPath('data.already_paid', true)
            ->assertJsonMissingPath('data.checkout_invoice_id');
    }

    public function test_confirming_without_an_invoice_asks_for_one(): void
    {
        $this->actingAsClient()->getJson('/api/client/payment-assistant');

        $this->actingAsClient()->postJson('/api/client/payment-assistant/confirm')
            ->assertOk()
            ->assertJsonPath('data.invoice', null);
    }

    // ── Unpaid list ──────────────────────────────────────────────────────────

    public function test_the_unpaid_list_shows_only_this_clients_payable_invoices(): void
    {
        $this->makeInvoice($this->clientA, 'INV-5001', 250);
        $this->makeInvoice($this->clientA, 'INV-5002', 500, 500, 'paid');
        $this->makeInvoice($this->clientA, 'INV-5003', 120, 0, 'draft');

        $this->actingAsClient()->getJson('/api/client/payment-assistant');

        $response = $this->actingAsClient()->getJson('/api/client/payment-assistant/unpaid')->assertOk();

        $numbers = collect($response->json('data.invoices'))->pluck('invoice_number')->all();

        $this->assertSame(['INV-5001'], $numbers);
    }

    // ── Clear History ────────────────────────────────────────────────────────
    //
    // The one thing these pin is that clearing DELETES NOTHING. The visible
    // thread only ever lived in the browser; what is persisted is this session
    // row and the permanent activity trail in system_audit_logs. Clearing
    // rotates the session's conversation id so the old activity stays where it
    // is and stays attributable to the conversation it came from.

    public function test_clearing_history_resets_the_conversation_without_deleting_activity(): void
    {
        $invoice = $this->makeInvoice($this->clientA, 'INV-6001', 400);

        $this->actingAsClient()->getJson('/api/client/payment-assistant');
        $this->actingAsClient()->postJson('/api/client/payment-assistant/message', ['text' => 'INV-6001'])
            ->assertJsonPath('data.state', 'invoice_found');

        $before = DB::table('system_audit_logs')
            ->where('user_id', $this->user->id)
            ->where('action', 'like', 'payment_assistant.%')
            ->count();

        $this->assertGreaterThan(0, $before, 'Expected activity to have been recorded before clearing.');

        $this->actingAsClient()->postJson('/api/client/payment-assistant/clear')
            ->assertOk()
            // A clean conversation: no invoice in view, back to the prompt.
            ->assertJsonPath('data.invoice', null)
            ->assertJsonPath('data.state', 'waiting_for_invoice')
            ->assertJsonPath('data.cleared', true)
            // The chosen company survives — clearing the thread is not the
            // same as changing who you are paying.
            ->assertJsonPath('data.company.id', $this->companyA);

        $after = DB::table('system_audit_logs')
            ->where('user_id', $this->user->id)
            ->where('action', 'like', 'payment_assistant.%')
            ->count();

        // Strictly MORE, never fewer: the clear is itself audited and nothing
        // that was there before was removed.
        $this->assertGreaterThan($before, $after);

        // The invoice, and everything else the assistant touched, is untouched.
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'sent']);
    }

    /**
     * Old activity keeps its old conversation id; new activity gets the new
     * one. That separation IS the feature — it is what lets the client's view
     * start empty while the trail stays whole and readable.
     */
    public function test_clearing_history_starts_a_new_conversation_leaving_old_activity_attributed_to_the_old_one(): void
    {
        $this->makeInvoice($this->clientA, 'INV-6002', 400);

        $this->actingAsClient()->getJson('/api/client/payment-assistant');
        $this->actingAsClient()->postJson('/api/client/payment-assistant/message', ['text' => 'INV-6002']);

        $firstConversation = DB::table('payment_assistant_sessions')
            ->where('user_id', $this->user->id)
            ->value('conversation_id');

        $this->assertNotNull($firstConversation);

        $this->actingAsClient()->postJson('/api/client/payment-assistant/clear')->assertOk();

        $secondConversation = DB::table('payment_assistant_sessions')
            ->where('user_id', $this->user->id)
            ->value('conversation_id');

        $this->assertNotSame($firstConversation, $secondConversation);

        // The earlier rows are still there, still stamped with the conversation
        // they belonged to.
        $this->assertGreaterThan(0, DB::table('system_audit_logs')
            ->where('user_id', $this->user->id)
            ->where('action', 'like', 'payment_assistant.%')
            ->where('new_values', 'like', '%' . $firstConversation . '%')
            ->count());

        // And activity after the clear is attributed to the new conversation.
        $this->actingAsClient()->postJson('/api/client/payment-assistant/message', ['text' => 'INV-6002']);

        $this->assertGreaterThan(0, DB::table('system_audit_logs')
            ->where('user_id', $this->user->id)
            ->where('new_values', 'like', '%' . $secondConversation . '%')
            ->count());
    }

    public function test_clearing_history_requires_a_signed_in_client(): void
    {
        $this->postJson('/api/client/payment-assistant/clear')->assertStatus(401);
    }

    /** A cleared conversation is immediately usable again. */
    public function test_a_new_conversation_can_be_started_after_clearing(): void
    {
        $invoice = $this->makeInvoice($this->clientA, 'INV-6003', 400);

        $this->actingAsClient()->getJson('/api/client/payment-assistant');
        $this->actingAsClient()->postJson('/api/client/payment-assistant/clear')->assertOk();

        $this->actingAsClient()->postJson('/api/client/payment-assistant/message', ['text' => 'INV-6003'])
            ->assertOk()
            ->assertJsonPath('data.state', 'invoice_found')
            ->assertJsonPath('data.invoice.id', $invoice->id);

        // And the unpaid list still works after a clear.
        $this->actingAsClient()->getJson('/api/client/payment-assistant/unpaid')
            ->assertOk()
            ->assertJsonPath('data.invoices.0.invoice_number', 'INV-6003');
    }
}
