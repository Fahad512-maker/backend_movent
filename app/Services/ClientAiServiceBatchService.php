<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientAiServiceBatch;
use App\Models\ClientAiServiceBatchItem;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Owns the persisted, per-CLIENT-PER-PROJECT batch of 10 AI-suggested
 * services shown on the Client Portal dashboard — see
 * App\Http\Controllers\Api\Client\DashboardController. A client with two
 * projects in different categories gets two independent active batches (see
 * App\Models\ClientAiServiceBatch::scopeForProject()) — generating or rotating
 * one project's batch never touches another project's. Replaces the old
 * ephemeral, company-wide, 6h-cached behaviour (still available as a
 * bootstrap fallback via App\Services\AiServiceCatalogService::catalog() for
 * a client who has no batch of their own yet).
 *
 * Both entry points below are called from App\Services\InvoicePaymentService::
 * applyToInvoice() — the single place an invoice becomes paid/partially_paid
 * — deliberately NOT from App\Services\PaymentProjectStartService, so this
 * works for every company regardless of whether they have Deal Workflow
 * auto-project-creation configured. Neither method may ever throw: an AI or
 * DB failure here must never block a payment from being recorded.
 *
 * A service only ever becomes 'taken' via a real invoice explicitly linked
 * to it (invoices.ai_service_batch_item_id) being paid or partially paid —
 * never merely by the client clicking "Request Service" on the dashboard,
 * which only moves it to 'requested'.
 */
class ClientAiServiceBatchService
{
    private const BATCH_SIZE = 10;
    private const TAKEN_THRESHOLD = 8;

    // Same reasoning as LeadProjectTitleService::PROJECT_NAME_TIMEOUT_SECONDS/
    // PROJECT_NAME_MAX_MODELS — this runs inside the payment path (a gateway
    // webhook may be waiting on the response), so it must be bounded, not
    // absent.
    private const TIMEOUT_SECONDS = 12;
    private const MAX_TOKENS = 1600;
    private const MAX_MODELS = 3;

    /**
     * Call once per paid/partially_paid transition. If the client's project
     * this invoice belongs to has no 'active' batch yet, or that batch
     * already has TAKEN_THRESHOLD+ items 'taken', generates a fresh batch of
     * BATCH_SIZE FOR THAT PROJECT and supersedes the old one — an invoice for
     * a different project never touches that other project's batch.
     * Otherwise does nothing — the current batch still has room.
     */
    public static function generateIfNeeded(Invoice $invoice): void
    {
        try {
            if (!$invoice->client_id) {
                return;
            }

            $client = Client::find($invoice->client_id);
            if (!$client) {
                return;
            }

            // The invoice's own project when it has one; otherwise fall back
            // to this client's "most relevant project" heuristic — the same
            // resolution ClientServiceRecommendationService itself uses when
            // asked for no specific project (see primaryProjectIdFor()).
            $projectId = $invoice->project_id ?: ClientServiceRecommendationService::primaryProjectIdFor($client);

            $activeBatch = ClientAiServiceBatch::where('client_id', $client->id)->forProject($projectId)->active()->first();

            if ($activeBatch) {
                $total = ClientAiServiceBatchItem::where('batch_id', $activeBatch->id)->count();
                $taken = ClientAiServiceBatchItem::where('batch_id', $activeBatch->id)
                    ->where('status', 'taken')
                    ->count();

                // A batch can now legitimately have fewer than BATCH_SIZE
                // items (ClientServiceRecommendationService only returns
                // genuinely relevant services, never pads to hit a count) —
                // or even zero, when nothing was relevant at the time. Either
                // way, "the batch has no room left" means every item in THIS
                // batch is taken, not literally reaching TAKEN_THRESHOLD,
                // which a small or empty batch could never satisfy.
                if ($total > 0 && $taken < min(self::TAKEN_THRESHOLD, $total)) {
                    return;
                }
            }

            self::generateBatch($client, $invoice, $activeBatch, $projectId);
        } catch (\Throwable $e) {
            Log::warning('[client-ai-services] batch generation failed for invoice ' . $invoice->id . ': ' . $e->getMessage());
        }
    }

    /**
     * Call once per paid/partially_paid transition. Flips the specific
     * item this invoice was created for (invoices.ai_service_batch_item_id)
     * to 'taken' — idempotent, a no-op if already taken or unset.
     */
    public static function markTakenIfPaid(Invoice $invoice): void
    {
        try {
            if (!in_array($invoice->status, ['paid', 'partially_paid'], true)) {
                return;
            }

            if (!$invoice->ai_service_batch_item_id) {
                return;
            }

            $item = ClientAiServiceBatchItem::find($invoice->ai_service_batch_item_id);
            if (!$item || $item->status === 'taken') {
                return;
            }

            $item->update([
                'status'              => 'taken',
                'taken_at'            => now(),
                'taken_by_invoice_id' => $invoice->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[client-ai-services] mark-taken failed for invoice ' . $invoice->id . ': ' . $e->getMessage());
        }
    }

    /**
     * Used by DashboardController::requestService() — 'available' only, so
     * re-requesting an already-'requested' or 'taken' item is a no-op
     * (the caller decides whether that's an error).
     */
    public static function requestItem(ClientAiServiceBatchItem $item, ?string $notes): void
    {
        if ($item->status !== 'available') {
            return;
        }

        $item->update([
            'status'          => 'requested',
            'requested_at'    => now(),
            'requested_notes' => $notes,
        ]);
    }

    private static function generateBatch(Client $client, Invoice $invoice, ?ClientAiServiceBatch $previous, ?int $projectId): void
    {
        $focusContext = self::triggerContext($invoice);

        // Grounded in THIS project's own context + this company's real
        // service catalogue (see ClientServiceRecommendationService) — the
        // deliberate improvement over the old AiServiceCatalogService::
        // generate() call, which only ever saw bare project names and could
        // invent services unrelated to either the client's work or what the
        // company actually sells.
        $services = ClientServiceRecommendationService::recommend($client, self::BATCH_SIZE, $focusContext, $projectId);
        $generatedVia = 'ai';

        if (!$services) {
            $services = AiServiceCatalogService::fallbackCatalog(self::BATCH_SIZE);
            $generatedVia = 'fallback';
        }

        // Which generation this is for this (client, project) pair (1, 2,
        // 3...) — audit metadata only, doesn't drive content.
        $batchNumber = ($previous?->batch_number ?? 0) + 1;

        DB::transaction(function () use ($client, $invoice, $previous, $services, $generatedVia, $projectId, $batchNumber) {
            // 'completed', not deleted — rotated_at records exactly when, so
            // the history stays queryable (do not wait for the batch's
            // remaining un-taken items; they are abandoned, never carried
            // into the new batch).
            $previous?->update(['status' => 'completed', 'rotated_at' => now()]);

            $batch = ClientAiServiceBatch::create([
                'company_id'        => $client->company_id,
                'client_id'         => $client->id,
                'project_id'        => $projectId,
                'source_invoice_id' => $invoice->id,
                'status'            => 'active',
                'generated_via'     => $generatedVia,
                'batch_number'      => $batchNumber,
            ]);

            foreach ($services as $service) {
                ClientAiServiceBatchItem::create([
                    'batch_id'           => $batch->id,
                    'company_service_id' => $service['company_service_id'] ?? null,
                    'key'                => $service['key'],
                    'name'               => mb_substr($service['name'], 0, 150),
                    'category'           => mb_substr($service['category'] ?? 'IT', 0, 100),
                    'summary'            => mb_substr($service['summary'], 0, 500),
                    'timeline'           => mb_substr($service['timeline'] ?? 'Upon review', 0, 100),
                ]);
            }
        });

        Log::info('[client-ai-services] batch generated', [
            'client_id'  => $client->id,
            'invoice_id' => $invoice->id,
            'via'        => $generatedVia,
            'count'      => count($services),
        ]);
    }

    /**
     * What this invoice was actually for, in the model's own words — same
     * corpus shape as LeadProjectTitleService::invoiceContextText(), so the
     * generated services read as "related to what you just started"
     * instead of generic upsells.
     */
    private static function triggerContext(Invoice $invoice): ?string
    {
        $parts = [];

        if (trim((string) $invoice->project_title) !== '') {
            $parts[] = trim($invoice->project_title);
        } elseif ($invoice->lead && trim((string) $invoice->lead->proposed_project_title) !== '') {
            $parts[] = trim($invoice->lead->proposed_project_title);
        }

        if (trim((string) $invoice->invoice_purpose) !== '') {
            $parts[] = 'Purpose: ' . trim($invoice->invoice_purpose);
        }

        $itemDescriptions = $invoice->items->pluck('description')->filter()->implode('; ');
        if ($itemDescriptions !== '') {
            $parts[] = 'Items: ' . $itemDescriptions;
        }

        return $parts !== [] ? implode('. ', $parts) : null;
    }
}
