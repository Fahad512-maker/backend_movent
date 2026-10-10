<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\PaymentReceipt;
use App\Services\PaymentReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Invoice Receipts tab on a client — the receipts generated for that
 * client's confirmed payments.
 *
 * Reads only. Receipts are produced by the payment pipeline
 * (InvoicePaymentService::applyToInvoice → PaymentReceiptService); nothing here
 * creates, edits or re-renders one, so this endpoint cannot put an invoice and
 * its receipt out of step.
 *
 * ISOLATION. Both methods resolve the client through the companies THIS admin
 * owns and then pin the receipt query to that client's own company_id. Two
 * independent columns have to agree before a row is returned, and neither of
 * them comes from the request — so a receipt id belonging to another client, or
 * to the same client under a company this admin does not own, is simply not in
 * the result set. There is no id-only lookup anywhere in this file.
 */
class ClientInvoiceReceiptController extends Controller
{
    private function admin()
    {
        return auth('admin')->user();
    }

    /** @return array<int, int> */
    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    /**
     * The client, if this admin owns the company it belongs to.
     *
     * Deliberately the same resolution Admin\ClientController::show() uses, so
     * a client reachable on that page is reachable here and nowhere else is.
     */
    private function client(int $id): Client
    {
        return Client::whereIn('company_id', $this->companyIds())->findOrFail($id);
    }

    /**
     * One receipt of this client's, pinned to the client's own company.
     *
     * client_id AND company_id, not the receipt id alone — the id is the only
     * part of this the caller supplies, and on its own it would address every
     * receipt in the system.
     */
    private function receipt(Client $client, int $receiptId): PaymentReceipt
    {
        return PaymentReceipt::where('id', $receiptId)
            ->where('client_id', $client->id)
            ->where('company_id', $client->company_id)
            ->firstOrFail();
    }

    // ── GET /admin/clients/{id}/invoice-receipts ─────────────────────────────

    public function index(int $id): JsonResponse
    {
        $client = $this->client($id);

        $receipts = PaymentReceipt::where('client_id', $client->id)
            ->where('company_id', $client->company_id)
            ->with(['invoice.company:id,name', 'invoice.brand', 'invoice.client:id,name,email,company_name', 'payment'])
            ->orderByDesc('id')
            ->get()
            // A receipt whose invoice or payment has since been removed is not
            // shown rather than rendered with holes in it.
            ->filter(fn (PaymentReceipt $r) => $r->invoice && $r->payment)
            ->map(fn (PaymentReceipt $r) => $this->card($r))
            ->values();

        return ApiResponse::success(['receipts' => $receipts]);
    }

    /**
     * Everything the card shows — read live off the invoice and payment rows
     * through the same facts() the image itself is rendered from, so the card
     * and the receipt beside it can never describe the payment differently.
     */
    private function card(PaymentReceipt $receipt): array
    {
        // The image endpoint is relative to the API root, because the
        // frontend's axios instance already carries the /api base. It is
        // authenticated and scoped like every other read here; there is no
        // public URL for a receipt.
        return PaymentReceiptService::card(
            $receipt,
            "/admin/clients/{$receipt->client_id}/invoice-receipts/{$receipt->id}/image",
        );
    }

    // ── GET /admin/clients/{id}/invoice-receipts/{receiptId}/image ────────────

    /**
     * The receipt image, streamed inline for the card thumbnail.
     *
     * Served through PHP rather than from a public path: receipts sit on the
     * private disk precisely so that possessing the filename is not enough to
     * read one.
     */
    public function image(int $id, int $receiptId): StreamedResponse
    {
        $client  = $this->client($id);
        $receipt = $this->receipt($client, $receiptId);

        abort_unless($receipt->file_path && Storage::exists($receipt->file_path), 404);

        return Storage::response($receipt->file_path, $receipt->file_name, [
            'Content-Type'        => 'image/svg+xml',
            'Content-Disposition' => 'inline; filename="' . $receipt->file_name . '"',
            // Never let a shared cache hold a tenant's receipt.
            'Cache-Control'       => 'private, max-age=0, no-store',
        ]);
    }
}
