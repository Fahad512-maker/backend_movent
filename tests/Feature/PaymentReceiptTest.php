<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CompanyAdmin;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Project;
use App\Models\User;
use App\Services\InvoicePaymentService;
use App\Services\PaymentReceiptService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Receipts for confirmed payments.
 *
 * Three things carry the risk here, and are what this file pins:
 *
 *  1. ISOLATION. A receipt names a client's money. It must not be reachable by
 *     another client, by another company's admin, or by anyone holding only the
 *     id — so the scoping is tested from every one of those angles, not just
 *     the happy path.
 *
 *  2. IT MUST NOT BREAK A PAYMENT. Receipt generation hangs off the single
 *     point every payment path converges on. If it can throw, or can double up
 *     when that path is reached twice (return callback then webhook), it breaks
 *     the one thing in this app that must not break.
 *
 *  3. IT REUSES THE DOCUMENTS MODULE. The receipt is an ordinary `documents`
 *     row, which is what makes it appear in Client Portal → Documents with no
 *     change to that module's own query. If that link is wrong the client never
 *     sees it.
 */
class PaymentReceiptTest extends TestCase
{
    use DatabaseTransactions;

    private ?int $packageId = null;
    private CompanyAdmin $admin;
    private CompanyAdmin $otherAdmin;
    private int $companyA;
    private int $companyB;
    private User $portalUser;
    private Client $clientA;

    protected function setUp(): void
    {
        parent::setUp();

        // DatabaseTransactions rolls the rows back; nothing rolls a written
        // file back. Faking the default disk keeps these tests from leaving
        // receipts behind in the real storage directory.
        Storage::fake();

        $this->admin      = $this->makeAdmin('receipts');
        $this->otherAdmin = $this->makeAdmin('rival');

        $this->companyA = $this->makeCompany($this->admin->id, 'Receipt Co');
        // Owned by a DIFFERENT tenant, which is what makes the isolation tests
        // below mean something.
        $this->companyB = $this->makeCompany($this->otherAdmin->id, 'Rival Co');

        $this->portalUser = $this->makePortalUser($this->companyA);
        $this->clientA    = $this->makeClient($this->companyA, $this->portalUser);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /** packages.name is unique, so the two tenants share one package row. */
    private function packageId(): int
    {
        return $this->packageId ??= DB::table('packages')->insertGetId([
            'name' => 'Receipt Test Package ' . uniqid(), 'tier' => 'basic', 'price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeAdmin(string $tag): CompanyAdmin
    {
        return CompanyAdmin::findOrFail(DB::table('company_admins')->insertGetId([
            'package_id' => $this->packageId(),
            'name'       => ucfirst($tag) . ' Admin',
            'email'      => $tag . '+' . uniqid() . '@example.test',
            'password'   => Hash::make('password'),
            'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    private function makeCompany(int $adminId, string $name): int
    {
        $id = DB::table('companies')->insertGetId([
            'admin_id' => $adminId, 'name' => $name, 'currency' => 'USD',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // 'payments' gates the admin's delete-payment route and 'compliance'
        // the Client Files routes — both companies need them so the isolation
        // tests exercise THIS feature's scoping rather than stopping at a
        // module gate (which would pass for the wrong reason).
        foreach (['client_portal', 'invoices', 'documents', 'payments', 'compliance'] as $module) {
            DB::table('company_modules')->insert([
                'company_id' => $id, 'module_key' => $module, 'is_enabled' => true,
                'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function makePortalUser(int $companyId): User
    {
        return User::create([
            'company_id' => $companyId,
            'name'       => 'Portal Client',
            'email'      => 'rcpt+' . uniqid() . '@example.test',
            'password'   => Hash::make('password'),
            'role_type'  => 'client',
            'is_active'  => true,
        ]);
    }

    private function makeClient(int $companyId, User $user): Client
    {
        return Client::create([
            'company_id'    => $companyId,
            'user_id'       => $user->id,
            'name'          => 'Portal Client',
            'email'         => $user->email,
            'company_name'  => 'Portal Client Ltd',
            'portal_access' => true,
        ]);
    }

    private function makeProject(): Project
    {
        return Project::create([
            'company_id' => $this->companyA,
            'client_id'  => $this->clientA->id,
            'name'       => 'Receipt Project ' . uniqid(),
            'status'     => 'active',
        ]);
    }

    private function makeInvoice(Client $client, string $number, float $total): Invoice
    {
        return Invoice::create([
            'company_id'     => $client->company_id,
            'client_id'      => $client->id,
            'invoice_number' => $number,
            'subtotal'       => $total,
            'total_amount'   => $total,
            'paid_amount'    => 0,
            'currency'       => 'USD',
            'status'         => 'sent',
        ]);
    }

    /**
     * Settle an invoice the way the app does — through the single funnel every
     * payment path uses, so what is tested is the real hook and not a call to
     * the generator made up for the test.
     */
    private function pay(Invoice $invoice, float $amount, array $overrides = []): Payment
    {
        $payment = Payment::create(array_merge([
            'invoice_id'     => $invoice->id,
            'amount'         => $amount,
            'currency'       => $invoice->currency,
            'method'         => 'card',
            'gateway'        => 'stripe',
            'gateway_ref'    => 'pi_' . uniqid(),
            'receipt_number' => InvoicePaymentService::nextReceiptNumber($invoice->company_id),
            'status'         => 'confirmed',
            'payment_date'   => now()->toDateString(),
        ], $overrides));

        InvoicePaymentService::applyToInvoice($invoice, $payment);

        return $payment;
    }

    private function actingAsClient(?User $user = null): self
    {
        Sanctum::actingAs($user ?? $this->portalUser, ['role:client']);

        return $this;
    }

    // ── Generation ───────────────────────────────────────────────────────────

    public function test_a_confirmed_payment_produces_a_receipt_linked_to_client_company_invoice_and_payment(): void
    {
        $invoice = $this->makeInvoice($this->clientA, 'INV-RCPT-1', 500);
        $payment = $this->pay($invoice, 500);

        $receipt = PaymentReceipt::where('payment_id', $payment->id)->first();

        $this->assertNotNull($receipt, 'No receipt was generated for a confirmed payment.');
        $this->assertSame($this->companyA, $receipt->company_id);
        $this->assertSame($this->clientA->id, $receipt->client_id);
        $this->assertSame($invoice->id, $receipt->invoice_id);
        $this->assertSame($payment->receipt_number, $receipt->receipt_number);

        // The file is real, and it is a well-formed SVG image.
        $this->assertTrue(Storage::exists($receipt->file_path));
        $svg = Storage::get($receipt->file_path);
        $this->assertNotFalse(simplexml_load_string($svg), 'The receipt is not well-formed SVG.');

        // The receipt states the facts the invoice and payment actually hold —
        // nothing is invented.
        $this->assertStringContainsString('INV-RCPT-1', $svg);
        $this->assertStringContainsString((string) $payment->receipt_number, $svg);
        $this->assertStringContainsString($payment->gateway_ref, $svg);
        $this->assertStringContainsString('500.00', $svg);
    }

    /** It reuses the documents module rather than adding a second one. */
    public function test_the_receipt_is_filed_as_a_client_visible_document(): void
    {
        $invoice = $this->makeInvoice($this->clientA, 'INV-RCPT-2', 250);
        $payment = $this->pay($invoice, 250);

        $document = Document::findOrFail(
            PaymentReceipt::where('payment_id', $payment->id)->value('document_id')
        );

        $this->assertSame($this->companyA, $document->company_id);
        $this->assertSame('client', $document->linked_to_type);
        $this->assertSame($this->clientA->id, (int) $document->linked_to_id);
        $this->assertTrue($document->is_visible_to_client);
        $this->assertSame('image', $document->type);
        $this->assertStringContainsString('INV-RCPT-2', $document->title);
    }

    /**
     * applyToInvoice() is documented as safe to reach more than once for the
     * same payment (return callback, then the authoritative webhook). The
     * receipt must be too — one payment, one receipt, one document.
     */
    public function test_generating_twice_for_the_same_payment_produces_one_receipt(): void
    {
        $invoice = $this->makeInvoice($this->clientA, 'INV-RCPT-3', 120);
        $payment = $this->pay($invoice, 120);

        InvoicePaymentService::applyToInvoice($invoice->fresh(), $payment);
        PaymentReceiptService::generateFor($invoice->fresh(), $payment);

        $this->assertSame(1, PaymentReceipt::where('payment_id', $payment->id)->count());
        $this->assertSame(1, Document::where('linked_to_type', 'client')
            ->where('linked_to_id', $this->clientA->id)
            ->where('title', 'like', '%INV-RCPT-3%')
            ->count());
    }

    /** A partial payment is still a payment, and still gets its own receipt. */
    public function test_a_partial_payment_gets_a_receipt_showing_what_was_paid_and_the_invoice_status(): void
    {
        $invoice = $this->makeInvoice($this->clientA, 'INV-RCPT-4', 400);
        $payment = $this->pay($invoice, 150);

        $receipt = PaymentReceipt::where('payment_id', $payment->id)->firstOrFail();
        $svg = Storage::get($receipt->file_path);

        $this->assertSame('partially_paid', $invoice->fresh()->status);
        $this->assertStringContainsString('PARTIALLY PAID', $svg);
        // What THIS payment settled. The invoice total and the running balance
        // are deliberately not on the receipt — they live on the invoice, and
        // a copy here would be a second version of the same numbers.
        $this->assertStringContainsString('150.00', $svg);
        $this->assertStringNotContainsString('Balance Due', $svg);
    }

    /**
     * The heading is part of the IMAGE, not something the page draws around it —
     * so it is there however the receipt is opened, downloaded or forwarded.
     */
    public function test_the_receipt_image_carries_the_payment_receipt_heading_and_stays_compact(): void
    {
        $invoice = $this->makeInvoice($this->clientA, 'INV-RCPT-12', 275);
        $payment = $this->pay($invoice, 275);

        $receipt = PaymentReceipt::where('payment_id', $payment->id)->firstOrFail();
        $svg = Storage::get($receipt->file_path);

        $this->assertStringContainsString('PAYMENT RECEIPT', $svg);

        $xml = simplexml_load_string($svg);
        $this->assertNotFalse($xml);

        // A receipt, not an invoice sheet: it has to fit on a screen without
        // scrolling, which is what the earlier 820×1016 version could not do.
        $this->assertSame('460', (string) $xml['width']);
        $this->assertLessThanOrEqual(520, (int) (string) $xml['height']);

        // And it carries only the payment facts — no invoice sheet content.
        foreach (['Due date', 'Invoice date', 'Invoice Amount', 'Total Paid', 'BILLED TO'] as $absent) {
            $this->assertStringNotContainsString($absent, $svg);
        }
    }

    /** A pending claim has not been paid, so there is nothing to receipt. */
    public function test_an_unconfirmed_payment_gets_no_receipt(): void
    {
        $invoice = $this->makeInvoice($this->clientA, 'INV-RCPT-5', 90);

        $payment = Payment::create([
            'invoice_id' => $invoice->id, 'amount' => 90, 'currency' => 'USD',
            'method' => 'bank_transfer', 'status' => 'pending',
        ]);

        $this->assertNull(PaymentReceiptService::generateFor($invoice, $payment));
        $this->assertSame(0, PaymentReceipt::where('payment_id', $payment->id)->count());
    }

    // ── Client Portal → Documents ────────────────────────────────────────────

    public function test_the_receipt_appears_in_the_clients_documents_with_its_payment_detail(): void
    {
        $invoice = $this->makeInvoice($this->clientA, 'INV-RCPT-6', 300);
        $payment = $this->pay($invoice, 300);

        $rows = $this->actingAsClient()->getJson('/api/client/documents')
            ->assertOk()
            ->json('data');

        $row = collect($rows)->firstWhere('receipt.payment_id', $payment->id);

        $this->assertNotNull($row, 'The receipt did not appear in Client Portal → Documents.');
        $this->assertSame('INV-RCPT-6', $row['receipt']['invoice_number']);
        $this->assertSame('paid', $row['receipt']['invoice_status']);
        $this->assertSame(300.0, (float) $row['receipt']['paid_amount']);
        $this->assertSame('USD', $row['receipt']['currency']);
        $this->assertSame($payment->gateway_ref, $row['receipt']['reference']);

        // And it downloads through the module's own endpoint, unchanged.
        $this->actingAsClient()->get("/api/client/documents/{$row['id']}/download")->assertOk();
    }

    /** The isolation that matters most: another client cannot reach it. */
    public function test_another_client_cannot_see_or_download_the_receipt(): void
    {
        $invoice = $this->makeInvoice($this->clientA, 'INV-RCPT-7', 300);
        $payment = $this->pay($invoice, 300);
        $documentId = PaymentReceipt::where('payment_id', $payment->id)->value('document_id');

        $intruderUser = $this->makePortalUser($this->companyA);
        $this->makeClient($this->companyA, $intruderUser);

        $rows = $this->actingAsClient($intruderUser)->getJson('/api/client/documents')
            ->assertOk()
            ->json('data');

        $this->assertNull(collect($rows)->firstWhere('id', $documentId));

        // Not by id either — the download endpoint scopes, it does not trust.
        $this->actingAsClient($intruderUser)
            ->get("/api/client/documents/{$documentId}/download")
            ->assertStatus(404);
    }

    // ── Admin: the Invoice Receipts tab ──────────────────────────────────────

    public function test_an_admin_sees_the_clients_receipts_with_the_invoice_they_belong_to(): void
    {
        $invoice = $this->makeInvoice($this->clientA, 'INV-RCPT-8', 640);
        $payment = $this->pay($invoice, 640);

        $receipts = $this->actingAs($this->admin, 'admin')
            ->getJson("/api/admin/clients/{$this->clientA->id}/invoice-receipts")
            ->assertOk()
            ->json('data.receipts');

        $this->assertCount(1, $receipts);

        $card = $receipts[0];
        $this->assertSame('INV-RCPT-8', $card['invoice_number']);
        // The link target for the existing invoice detail page — no duplicate
        // invoice screen is introduced.
        $this->assertSame($invoice->id, $card['invoice_id']);
        $this->assertSame('paid', $card['invoice_status']);
        $this->assertSame(640.0, (float) $card['paid_amount']);
        $this->assertSame(0.0, (float) $card['balance_due']);
        $this->assertSame('USD', $card['currency']);
        $this->assertSame('stripe', $card['gateway']);
        $this->assertSame($payment->gateway_ref, $card['reference']);
        $this->assertNotEmpty($card['image_endpoint']);

        // And the image streams back as an image.
        $this->actingAs($this->admin, 'admin')
            ->get('/api' . $card['image_endpoint'])
            ->assertOk()
            ->assertHeader('content-type', 'image/svg+xml');
    }

    /** A different tenant's admin gets nothing — not the list, not the image. */
    public function test_an_admin_of_another_company_cannot_reach_the_receipt(): void
    {
        $invoice = $this->makeInvoice($this->clientA, 'INV-RCPT-9', 200);
        $payment = $this->pay($invoice, 200);
        $receiptId = PaymentReceipt::where('payment_id', $payment->id)->value('id');

        $this->actingAs($this->otherAdmin, 'admin')
            ->getJson("/api/admin/clients/{$this->clientA->id}/invoice-receipts")
            ->assertStatus(404);

        $this->actingAs($this->otherAdmin, 'admin')
            ->get("/api/admin/clients/{$this->clientA->id}/invoice-receipts/{$receiptId}/image")
            ->assertStatus(404);
    }

    /**
     * The receipt id alone addresses nothing: it is only ever resolved together
     * with the client it belongs to.
     */
    public function test_a_receipt_id_cannot_be_read_through_another_client_of_the_same_company(): void
    {
        $invoice = $this->makeInvoice($this->clientA, 'INV-RCPT-10', 200);
        $payment = $this->pay($invoice, 200);
        $receiptId = PaymentReceipt::where('payment_id', $payment->id)->value('id');

        $siblingUser = $this->makePortalUser($this->companyA);
        $sibling     = $this->makeClient($this->companyA, $siblingUser);

        $this->actingAs($this->admin, 'admin')
            ->get("/api/admin/clients/{$sibling->id}/invoice-receipts/{$receiptId}/image")
            ->assertStatus(404);
    }

    public function test_the_receipt_endpoints_require_an_authenticated_admin(): void
    {
        $this->getJson("/api/admin/clients/{$this->clientA->id}/invoice-receipts")->assertStatus(401);
    }

    // ── Compliance → Client Files ────────────────────────────────────────────
    //
    // A receipt is a client-originated record in the sense compliance cares
    // about, so it is listed beside the files the client sent. Same scoping
    // rules as everywhere else, checked here from the compliance side too
    // because it reaches receipts by PROJECT rather than by client.

    public function test_compliance_client_files_lists_the_receipts_billed_under_a_project(): void
    {
        $project = $this->makeProject();

        $invoice = $this->makeInvoice($this->clientA, 'INV-RCPT-13', 420);
        $invoice->update(['project_id' => $project->id]);
        $payment = $this->pay($invoice->fresh(), 420);

        // Billed to the same client but NOT to this project — must not appear.
        $other = $this->makeInvoice($this->clientA, 'INV-RCPT-14', 90);
        $this->pay($other, 90);

        $receipts = $this->actingAs($this->admin, 'admin')
            ->withHeader('X-Active-Company-Id', (string) $this->companyA)
            ->getJson("/api/admin/compliance/projects/{$project->id}/client-receipts")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $receipts);
        $this->assertSame('INV-RCPT-13', $receipts[0]['invoice_number']);
        $this->assertSame(420.0, (float) $receipts[0]['paid_amount']);
        $this->assertSame($payment->gateway_ref, $receipts[0]['reference']);

        // And the thumbnail streams as an image.
        $this->actingAs($this->admin, 'admin')
            ->withHeader('X-Active-Company-Id', (string) $this->companyA)
            ->get('/api' . $receipts[0]['image_endpoint'])
            ->assertOk()
            ->assertHeader('content-type', 'image/svg+xml');
    }

    /** The receipt id alone reaches nothing across a tenant boundary. */
    public function test_compliance_cannot_reach_another_companys_receipt(): void
    {
        $project = $this->makeProject();

        $invoice = $this->makeInvoice($this->clientA, 'INV-RCPT-15', 200);
        $invoice->update(['project_id' => $project->id]);
        $payment = $this->pay($invoice->fresh(), 200);
        $receiptId = PaymentReceipt::where('payment_id', $payment->id)->value('id');

        // The rival tenant owns neither the project nor the receipt.
        $this->actingAs($this->otherAdmin, 'admin')
            ->withHeader('X-Active-Company-Id', (string) $this->companyB)
            ->get("/api/admin/compliance/client-receipts/{$receiptId}/image")
            ->assertStatus(404);

        $this->actingAs($this->otherAdmin, 'admin')
            ->withHeader('X-Active-Company-Id', (string) $this->companyB)
            ->getJson("/api/admin/compliance/projects/{$project->id}/client-receipts")
            ->assertStatus(404);
    }

    // ── Lifecycle ────────────────────────────────────────────────────────────

    /**
     * A removed payment takes its receipt with it — otherwise the client keeps
     * a receipt in Documents for money the invoice no longer shows.
     */
    public function test_deleting_a_payment_retires_its_receipt(): void
    {
        $invoice = $this->makeInvoice($this->clientA, 'INV-RCPT-11', 180);
        $payment = $this->pay($invoice, 180);

        $receipt = PaymentReceipt::where('payment_id', $payment->id)->firstOrFail();
        $path    = $receipt->file_path;

        $this->actingAs($this->admin, 'admin')
            ->deleteJson("/api/admin/payments/{$payment->id}")
            ->assertOk();

        $this->assertSame(0, PaymentReceipt::where('payment_id', $payment->id)->count());
        $this->assertFalse(Storage::exists($path));
        $this->assertSoftDeleted('documents', ['id' => $receipt->document_id]);
    }
}
