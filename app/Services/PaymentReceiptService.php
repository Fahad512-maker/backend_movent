<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Renders and files the receipt image for a confirmed payment.
 *
 * WHY SVG, and why this is a real image rather than a PDF: this app carries no
 * PDF or rasterizer dependency (no dompdf, no Imagick, no headless browser) and
 * adding one to satisfy a receipt would be a large operational change — a
 * binary, a font cache and a new failure mode on every host — for an artifact
 * nobody prints. SVG is produced by PHP alone, renders in an <img> tag (so the
 * admin's receipt cards get a real thumbnail), scales without blurring, and
 * downloads as an ordinary file. Everything is inlined: no external font, no
 * external image, no script, so it renders identically wherever it is opened.
 *
 * WHERE THE FILE GOES: the existing document architecture, untouched. One
 * `documents` row on the default (private) disk, linked_to_type='client', so
 * Client Portal → Documents picks it up through the query that module already
 * runs — no second documents system, no change to that endpoint's scoping. The
 * `payment_receipts` row alongside it is only an index (company, client,
 * invoice, payment), because a `documents` row can hold exactly one link and a
 * receipt belongs to all four.
 *
 * NOTHING HERE INVENTS DATA. Every figure and reference is read off the invoice
 * and payment rows as they stand; a field the system does not have is simply
 * omitted from the receipt rather than filled in.
 */
class PaymentReceiptService
{
    // ── Canvas ───────────────────────────────────────────────────────────────
    //
    // A RECEIPT, not an invoice page. 460 wide and roughly as tall, so the whole
    // thing is legible at a glance and fits on screen without scrolling — the
    // earlier 820×1016 sheet had to be scrolled in the viewer, which is what
    // pushed the heading out of sight.
    private const W = 460;

    /** Page margin — the left edge of every label and the right edge of every value. */
    private const M = 26;

    // Palette — the Client Portal's own (GREEN #10b981 action colour, slate
    // ink), so a receipt reads as part of the same product as the portal that
    // serves it.
    private const INK = '#0f172a';
    private const MUTED = '#64748b';
    private const FAINT = '#94a3b8';
    private const LINE = '#e2e8f0';
    private const GREEN = '#10b981';
    private const GREEN_DARK = '#059669';
    private const FONT = "'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif";

    /**
     * Generate the receipt for a confirmed payment, once.
     *
     * Idempotent: `payment_receipts.payment_id` is unique and an existing row is
     * returned untouched, which matters because applyToInvoice() is documented
     * as safe to reach more than once for the same payment (return callback
     * then webhook, for instance).
     *
     * Returns null — never throws — when there is nothing to issue: an
     * unconfirmed payment, or an invoice raised against one-off customer
     * details with no Client record to file the document against. A receipt is
     * an artifact of a payment, so it must never be the reason one fails.
     */
    public static function generateFor(Invoice $invoice, Payment $payment): ?PaymentReceipt
    {
        if ($payment->status !== 'confirmed' || !$invoice->client_id) {
            return null;
        }

        $existing = PaymentReceipt::where('payment_id', $payment->id)->first();
        if ($existing) {
            return $existing;
        }

        $invoice->loadMissing(['company', 'client', 'brand']);

        $svg      = self::render($invoice, $payment);
        $fileName = self::fileName($invoice, $payment);
        // Foldered by company first: the path itself carries the tenant, so a
        // stray listing of the disk cannot mix two companies' receipts.
        $path = "companies/{$invoice->company_id}/receipts/{$invoice->client_id}/{$payment->id}-" . $fileName;

        Storage::put($path, $svg);

        $document = Document::create([
            'company_id' => $invoice->company_id,
            // No uploader: the system issued this, not a person. The column is
            // nullable for exactly this case (see the 2026_07_04 migration).
            'uploaded_by'          => null,
            'title'                => "Payment Receipt — Invoice #{$invoice->invoice_number}",
            // 'image' is a member of the documents.type enum and is what the
            // portal's existing type filter already offers; there is no
            // 'receipt' value to add without altering that enum.
            'type'                 => 'image',
            'file_path'            => $path,
            'file_name'            => $fileName,
            'file_size_bytes'      => strlen($svg),
            'linked_to_type'       => 'client',
            'linked_to_id'         => $invoice->client_id,
            'is_visible_to_client' => true,
            'is_shared'            => false,
        ]);

        return PaymentReceipt::create([
            'company_id'     => $invoice->company_id,
            'client_id'      => $invoice->client_id,
            'invoice_id'     => $invoice->id,
            'payment_id'     => $payment->id,
            'document_id'    => $document->id,
            'file_path'      => $path,
            'file_name'      => $fileName,
            'receipt_number' => $payment->receipt_number,
        ]);
    }

    /**
     * The same call, wrapped so a rendering or disk problem can never break a
     * payment. This is what the payment pipeline calls.
     */
    public static function generateQuietly(Invoice $invoice, Payment $payment): ?PaymentReceipt
    {
        try {
            return self::generateFor($invoice, $payment);
        } catch (\Throwable $e) {
            Log::warning('[payment-receipt] payment ' . $payment->id . ': ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Re-render an existing receipt's image in place, keeping its row and its
     * document.
     *
     * This is how a change to the template reaches receipts that were already
     * issued. Deliberately IN PLACE: regenerating by delete-and-recreate would
     * retire the client's document and file a new one, so every client would
     * see a "new" receipt appear for a payment they made months ago. The file
     * is overwritten at the same path, so nothing in the portal moves.
     *
     * Returns false, never throws, when the receipt's invoice or payment has
     * since gone.
     */
    public static function rerender(PaymentReceipt $receipt): bool
    {
        try {
            $receipt->loadMissing(['invoice.company', 'invoice.client', 'invoice.brand', 'payment']);

            if (!$receipt->invoice || !$receipt->payment || !$receipt->file_path) {
                return false;
            }

            $svg = self::render($receipt->invoice, $receipt->payment);
            Storage::put($receipt->file_path, $svg);

            Document::where('id', $receipt->document_id)->update(['file_size_bytes' => strlen($svg)]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('[payment-receipt] rerender ' . $receipt->id . ': ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Remove a receipt and its file — used when the payment it documents is
     * deleted, so a client is never left holding a receipt for a payment the
     * company has since removed.
     */
    public static function forgetPayment(int $paymentId): void
    {
        try {
            $receipt = PaymentReceipt::where('payment_id', $paymentId)->first();
            if (!$receipt) {
                return;
            }

            if ($receipt->file_path && Storage::exists($receipt->file_path)) {
                Storage::delete($receipt->file_path);
            }

            // Soft delete, matching how every other Document leaves the system.
            Document::where('id', $receipt->document_id)->delete();
            $receipt->delete();
        } catch (\Throwable $e) {
            Log::warning('[payment-receipt] cleanup payment ' . $paymentId . ': ' . $e->getMessage());
        }
    }

    private static function fileName(Invoice $invoice, Payment $payment): string
    {
        $stem = $payment->receipt_number ?: ('receipt-' . $payment->id);

        return preg_replace('/[^A-Za-z0-9._-]+/', '-', $stem . '-' . $invoice->invoice_number) . '.svg';
    }

    // ── The receipt itself ───────────────────────────────────────────────────

    /**
     * The facts on the receipt, read straight off the rows. Shared by the
     * renderer and by the API responses, so the image and the card beside it
     * can never describe the same payment differently.
     *
     * @return array<string, mixed>
     */
    public static function facts(Invoice $invoice, Payment $payment): array
    {
        $profile = $invoice->brandingProfile();

        $total = round((float) $invoice->total_amount, 2);
        $paid  = round((float) $invoice->paid_amount, 2);

        return [
            'brand_name'     => $profile['name'] ?? $invoice->company?->name,
            'brand_email'    => $profile['email'] ?? null,
            'brand_phone'    => $profile['phone'] ?? null,
            'brand_website'  => $profile['website'] ?? null,
            'logo_path'      => $profile['logo_path'] ?? null,
            'company_name'   => $invoice->company?->name,
            'is_brand'       => ($profile['invoice_type'] ?? 'company') === 'brand',

            'client_name'    => $invoice->client?->name ?? $invoice->customer_name,
            'client_company' => $invoice->client?->company_name,
            'client_email'   => $invoice->client?->email ?? $invoice->customer_email,

            'invoice_id'     => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'invoice_date'   => optional($invoice->created_at)->toDateString(),
            'due_date'       => $invoice->due_date ? $invoice->due_date->toDateString() : null,
            'invoice_status' => $invoice->status,
            'currency'       => $invoice->currency,
            'invoice_amount' => $total,
            'total_paid'     => $paid,
            'balance_due'    => max(0, round($total - $paid, 2)),

            'payment_id'     => $payment->id,
            'receipt_number' => $payment->receipt_number,
            'paid_amount'    => round((float) $payment->amount, 2),
            'payment_status' => $payment->status,
            'payment_date'   => $payment->payment_date
                ? $payment->payment_date->toDateString()
                : optional($payment->created_at)->toDateString(),
            'method'         => $payment->method,
            'gateway'        => $payment->gateway,
            // The gateway's own reference when there is one; otherwise nothing.
            // Never substituted with the receipt number, which is a different
            // thing and is shown on its own line.
            'reference'      => $payment->gateway_ref ?: null,
            // Only present when the gateway was charged in a different currency
            // than the invoice — omitted entirely otherwise.
            'converted'      => $payment->converted_amount && $payment->converted_currency
                && strcasecmp((string) $payment->converted_currency, (string) $invoice->currency) !== 0
                ? round((float) $payment->converted_amount, 2) . ' ' . $payment->converted_currency
                : null,
        ];
    }

    /**
     * The receipt itself — a compact payment receipt, not an invoice sheet.
     *
     * WHAT IT SHOWS, and nothing else: the PAYMENT RECEIPT heading, the amount
     * paid, and the handful of facts a client or an admin actually needs to
     * identify the payment — invoice number, client, date, method, reference,
     * status. Invoice line items, billing addresses, due dates and running
     * balances are deliberately absent: those live on the invoice, and
     * reproducing them here made this a second copy of the invoice that could
     * disagree with the real one.
     *
     * Laid out with a cursor rather than fixed coordinates, so a payment with no
     * gateway reference (or no converted amount) simply produces a shorter
     * receipt instead of a gap where the row would have been.
     */
    /**
     * Receipts for everything billed under one project — what the Compliance
     * module's Client Files view lists alongside the files the client sent.
     *
     * Selected through `invoices.project_id`, the SAME column the Compliance
     * Invoices view uses, so the two can never disagree about which invoices
     * belong to a project. Scoped by company on the receipt AND on the invoice,
     * so a project id in a URL cannot reach another tenant's billing.
     *
     * @return \Illuminate\Support\Collection<int, PaymentReceipt>
     */
    public static function forProject(int $projectId, int $companyId)
    {
        return PaymentReceipt::where('company_id', $companyId)
            ->whereIn(
                'invoice_id',
                Invoice::where('project_id', $projectId)->where('company_id', $companyId)->select('id')
            )
            ->with(['invoice.company:id,name', 'invoice.brand', 'invoice.client:id,name,email,company_name', 'payment'])
            ->orderByDesc('id')
            ->get()
            // A receipt whose invoice or payment has since been removed is
            // dropped rather than rendered with holes in it.
            ->filter(fn (PaymentReceipt $r) => $r->invoice && $r->payment)
            ->values();
    }

    /**
     * One receipt as every surface serialises it — the Admin client tab, both
     * Compliance guards, and (minus the image endpoint) the Client Portal.
     *
     * Built on facts(), the same source the image itself is rendered from, so a
     * card and the receipt it shows can never describe a payment differently.
     * $imageEndpoint differs per surface because each one authorises the read
     * its own way; nothing else about the shape does.
     */
    public static function card(PaymentReceipt $receipt, string $imageEndpoint): array
    {
        return array_merge(self::facts($receipt->invoice, $receipt->payment), [
            'id'             => $receipt->id,
            'image_endpoint' => $imageEndpoint,
            'document_id'    => $receipt->document_id,
            'file_name'      => $receipt->file_name,
            'generated_at'   => $receipt->created_at,
        ]);
    }

    private static function render(Invoice $invoice, Payment $payment): string
    {
        $f = self::facts($invoice, $payment);

        $ccy   = $f['currency'] ? $f['currency'] . ' ' : '';
        $badge = self::statusBadge($f['invoice_status']);
        $right = self::W - self::M;

        $parts = [];

        // ── Header ───────────────────────────────────────────────────────────
        // The heading leads, because this artifact's whole job is to say what it
        // is at a glance. The brand sits under it as context, not as the title.
        $parts[] = '<rect x="0" y="0" width="' . self::W . '" height="104" fill="' . self::INK . '"/>';
        $parts[] = '<rect x="0" y="100" width="' . self::W . '" height="4" fill="' . self::GREEN . '"/>';
        $parts[] = self::mark($f['logo_path'], (string) $f['brand_name'], self::M, 22, 34);

        $parts[] = self::text(72, 42, 'PAYMENT RECEIPT', 13.5, '#ffffff', 700, 'start', 3);

        if ($f['brand_name']) {
            $parts[] = self::text(72, 64, self::truncate((string) $f['brand_name'], 26), 11, self::FAINT);
        }

        // The receipt's own number — its identity, not invoice metadata, which
        // is why it stays in the header rather than in the detail rows.
        if ($f['receipt_number']) {
            $parts[] = self::text($right, 48, (string) $f['receipt_number'], 10.5, self::FAINT, 400, 'end');
        }

        // ── Amount ───────────────────────────────────────────────────────────
        $heroBottom = $f['converted'] ? 202 : 184;

        $parts[] = '<rect x="0" y="104" width="' . self::W . '" height="' . ($heroBottom - 104) . '" fill="#f8fafc"/>';
        $parts[] = self::text(self::M, 134, 'AMOUNT PAID', 9.5, self::MUTED, 700, 'start', 2);
        $parts[] = self::text(self::M, 166, $ccy . self::money($f['paid_amount']), 25, self::INK, 700);

        if ($f['converted']) {
            $parts[] = self::text(self::M, 187, 'Charged as ' . $f['converted'], 10, self::MUTED);
        }

        $parts[] = self::pill($right, 148, $badge['label'], $badge['bg'], $badge['fg']);
        $parts[] = '<line x1="0" y1="' . $heroBottom . '" x2="' . self::W . '" y2="' . $heroBottom . '" stroke="' . self::LINE . '" stroke-width="1"/>';

        // ── The facts ────────────────────────────────────────────────────────
        // Only rows the system actually holds a value for. A receipt printing
        // "Transaction ID: —" tells nobody anything.
        $rows = array_filter([
            'Invoice Number' => $f['invoice_number'] ? '#' . $f['invoice_number'] : null,
            'Client Name'    => $f['client_name'],
            'Payment Date'   => $f['payment_date'],
            'Payment Method' => self::methodLabel($f['method'], $f['gateway']),
            'Transaction ID' => $f['reference'],
            'Payment Status' => $f['payment_status'] ? ucfirst((string) $f['payment_status']) : null,
        ], fn ($v) => $v !== null && $v !== '');

        $y = $heroBottom + 8;

        foreach ($rows as $label => $value) {
            $y += 27;
            $parts[] = self::text(self::M, $y, (string) $label, 10.5, self::MUTED);
            $parts[] = self::text($right, $y, self::truncate((string) $value, 30), 11.5, self::INK, 600, 'end');
            $parts[] = '<line x1="' . self::M . '" y1="' . ($y + 11) . '" x2="' . $right . '" y2="' . ($y + 11) . '" stroke="#f1f5f9" stroke-width="1"/>';
        }

        $height = $y + 32;

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"'
            . ' width="' . self::W . '" height="' . $height . '" viewBox="0 0 ' . self::W . ' ' . $height . '">'
            . '<title>Payment receipt for invoice ' . self::esc((string) $f['invoice_number']) . '</title>'
            . '<rect width="' . self::W . '" height="' . $height . '" fill="#ffffff"/>'
            . implode('', $parts)
            . '</svg>';
    }

    /**
     * The brand's logo when there is a usable one, else a monogram.
     *
     * The logo is base64-INLINED rather than linked: an <img>-rendered SVG may
     * not fetch external resources, so a linked logo would silently render as
     * nothing. Anything unreadable, oversized or not a raster image falls back
     * to the monogram rather than producing a broken receipt.
     */
    private static function mark(?string $logoPath, string $name, int $x, int $y, int $size): string
    {
        $data = self::inlineLogo($logoPath);

        if ($data) {
            return '<image x="' . $x . '" y="' . $y . '" width="' . $size . '" height="' . $size . '"'
                . ' preserveAspectRatio="xMidYMid meet" xlink:href="' . self::esc($data) . '"/>';
        }

        $initials = self::initials($name);
        $cx = $x + ($size / 2);
        $cy = $y + ($size / 2);

        // Sized from the mark, not a fixed 20px — the canvas shrank and a
        // hardcoded glyph would have burst out of the circle.
        $glyph = round($size * 0.42, 1);

        return '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . ($size / 2) . '" fill="' . self::GREEN . '"/>'
            . '<text x="' . $cx . '" y="' . ($cy + ($glyph * 0.35)) . '" font-family="' . self::FONT . '" font-size="' . $glyph . '"'
            . ' font-weight="700" fill="#ffffff" text-anchor="middle">' . self::esc($initials) . '</text>';
    }

    private static function inlineLogo(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png'  => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            default => null,
        };

        if (!$mime) {
            return null;
        }

        try {
            // Logos are uploaded to the `public` disk — see Admin\BrandController.
            $disk = Storage::disk('public');
            if (!$disk->exists($path) || $disk->size($path) > 1_048_576) {
                return null;
            }

            return 'data:' . $mime . ';base64,' . base64_encode($disk->get($path));
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{label: string, bg: string, fg: string} */
    private static function statusBadge(?string $status): array
    {
        return match ($status) {
            'paid'           => ['label' => 'PAID',           'bg' => '#ecfdf5', 'fg' => self::GREEN_DARK],
            'partially_paid' => ['label' => 'PARTIALLY PAID', 'bg' => '#fffbeb', 'fg' => '#d97706'],
            'overdue'        => ['label' => 'OVERDUE',        'bg' => '#fef2f2', 'fg' => '#dc2626'],
            default          => ['label' => strtoupper(str_replace('_', ' ', (string) ($status ?: 'received'))), 'bg' => '#f1f5f9', 'fg' => '#475569'],
        };
    }

    private static function methodLabel(?string $method, ?string $gateway): ?string
    {
        $labels = [
            'bank_transfer' => 'Bank Transfer',
            'cash'          => 'Cash',
            'card'          => 'Card',
            'cheque'        => 'Cheque',
            'gateway'       => 'Online Payment',
        ];

        $label = $method ? ($labels[$method] ?? ucfirst(str_replace('_', ' ', $method))) : null;

        if ($gateway) {
            $gatewayLabel = ucfirst($gateway);

            return $label ? $label . ' · ' . $gatewayLabel : $gatewayLabel;
        }

        return $label;
    }

    // ── SVG primitives ───────────────────────────────────────────────────────

    private static function text(
        float $x,
        float $y,
        string $content,
        float $size,
        string $fill,
        int $weight = 400,
        string $anchor = 'start',
        ?float $spacing = null
    ): string {
        return '<text x="' . $x . '" y="' . $y . '" font-family="' . self::FONT . '"'
            . ' font-size="' . $size . '" font-weight="' . $weight . '" fill="' . $fill . '"'
            . ' text-anchor="' . $anchor . '"'
            . ($spacing !== null ? ' letter-spacing="' . $spacing . '"' : '')
            . '>' . self::esc($content) . '</text>';
    }

    /** A status chip, right-aligned so its right edge lands on $rightX. */
    private static function pill(float $rightX, float $centerY, string $label, string $bg, string $fg): string
    {
        // SVG cannot measure text, so the box is sized from the character count
        // using the ~0.62em average advance of this font stack at this weight.
        $width = max(74, (int) round(strlen($label) * 6.6) + 26);
        $x     = $rightX - $width;

        return '<rect x="' . $x . '" y="' . ($centerY - 13) . '" width="' . $width . '" height="26" rx="13" fill="' . $bg . '"/>'
            . self::text($x + ($width / 2), $centerY + 4, $label, 10, $fg, 700, 'middle', 1.2);
    }

    private static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $second = mb_substr($parts[1] ?? '', 0, 1);

        return mb_strtoupper($first . $second) ?: '•';
    }

    private static function truncate(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1) . '…' : $value;
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 2);
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
