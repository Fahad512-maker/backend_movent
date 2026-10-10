<?php

namespace App\Http\Controllers\Api\Client;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Client\Concerns\ScopesToPermittedClient;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Document;
use App\Models\PaymentReceipt;
use App\Models\Project;
use App\Models\ProjectAttachment;
use App\Models\ProjectDeliverySubmission;
use App\Services\AttachmentStorageService;
use App\Services\PaymentReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    use ScopesToPermittedClient;

    private function clientIds(Request $request): array
    {
        return $this->permittedClientIds($request, 'documents');
    }

    public function index(Request $request): JsonResponse
    {
        $clientIds  = $this->clientIds($request);
        $projectIds = Project::whereIn('client_id', $clientIds)->pluck('id');

        $query = Document::where('is_visible_to_client', true)
            ->where(function ($q) use ($projectIds, $clientIds) {
                $q->where(function ($sub) use ($projectIds) {
                    $sub->where('linked_to_type', 'project')
                        ->whereIn('linked_to_id', $projectIds);
                })->orWhere(function ($sub) use ($clientIds) {
                    $sub->where('linked_to_type', 'client')
                        ->whereIn('linked_to_id', $clientIds);
                });
            });

        if ($request->filled('project_id')) {
            $query->where('linked_to_type', 'project')->where('linked_to_id', $request->project_id);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $documents = $query->with(['uploadedBy:id,name'])
            ->get(['id', 'title', 'type', 'file_name', 'file_size_bytes', 'linked_to_type', 'linked_to_id', 'uploaded_by', 'created_at']);

        // Payment receipts are ordinary documents — same table, same
        // visibility rule, same download endpoint — so they arrive in the list
        // above with no change to the query. This only ATTACHES the payment
        // facts the client needs to tell one apart: which invoice it settles,
        // what was paid, when, and where the invoice stands now.
        //
        // Scoped to $clientIds independently of the document query rather than
        // looked up by document_id alone, so the block can only ever be
        // attached to a receipt this login actually owns.
        $receipts = $this->receiptsFor($documents->pluck('id')->all(), $clientIds);

        $docs = $documents->map(fn ($d) => [
            'id' => $d->id, 'source' => 'document', 'title' => $d->title, 'type' => $d->type,
            'file_name' => $d->file_name, 'file_size_bytes' => $d->file_size_bytes,
            'linked_to_type' => $d->linked_to_type, 'linked_to_id' => $d->linked_to_id,
            'uploaded_by' => $d->uploadedBy, 'created_at' => $d->created_at,
            'receipt' => $receipts[$d->id] ?? null,
        ]);

        $attachmentsQuery = ProjectAttachment::where('is_visible_to_client', true)
            ->whereIn('project_id', $projectIds);

        if ($request->filled('project_id')) {
            $attachmentsQuery->where('project_id', $request->project_id);
        }

        $attachments = $attachmentsQuery->with(['uploadedByAdmin:id,name', 'uploadedByUser:id,name'])
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id, 'source' => 'attachment', 'title' => $a->original_name,
                'type' => $this->typeFromExtension(pathinfo($a->original_name, PATHINFO_EXTENSION)),
                'file_name' => $a->original_name, 'file_size_bytes' => $a->file_size,
                'linked_to_type' => 'project', 'linked_to_id' => $a->project_id,
                'uploaded_by' => $a->uploadedByAdmin ?? $a->uploadedByUser, 'created_at' => $a->created_at,
                'receipt' => null,
            ]);

        // Every time a project's final package was (re)delivered — see
        // ProjectDeliverySubmission and Client\ProjectController::show()'s
        // identical merge. Without this, a delivered project's package(s)
        // never show up here, only in the per-project delivery banner (and,
        // once superseded by a redelivery, not even there).
        $deliveriesQuery = ProjectDeliverySubmission::whereIn('project_id', $projectIds)
            ->with('deliveredByAdmin:id,name');

        if ($request->filled('project_id')) {
            $deliveriesQuery->where('project_id', $request->project_id);
        }

        $deliveries = $deliveriesQuery->get()
            ->map(fn ($d) => [
                'id' => $d->id, 'source' => 'delivery', 'title' => $d->file_name,
                // Normalized by extension, not the raw stored file_type mime —
                // matches how the attachment mapping above categorizes files,
                // so the type filter/column behaves the same across all three
                // sources instead of showing a raw mime string for deliveries only.
                'type' => $this->typeFromExtension(pathinfo($d->file_name, PATHINFO_EXTENSION)),
                'file_name' => $d->file_name, 'file_size_bytes' => $d->file_size,
                'linked_to_type' => 'project', 'linked_to_id' => $d->project_id,
                'uploaded_by' => $d->deliveredByAdmin, 'created_at' => $d->delivered_at,
                'receipt' => null,
            ]);

        $all = $docs->concat($attachments)->concat($deliveries);

        if ($request->filled('type')) {
            $all = $all->where('type', $request->type)->values();
        }

        return ApiResponse::success($all->sortByDesc('created_at')->values());
    }

    /**
     * The payment facts for whichever of these documents are receipts, keyed by
     * document id.
     *
     * Every figure is read live from the invoice and payment rows (through the
     * same PaymentReceiptService::facts() the receipt image itself is rendered
     * from), so the row in this list always agrees with the receipt the client
     * opens from it — and with the invoice, however it has moved on since.
     *
     * @param array<int, int> $documentIds
     * @param array<int, int> $clientIds
     * @return array<int, array<string, mixed>>
     */
    private function receiptsFor(array $documentIds, array $clientIds): array
    {
        if (empty($documentIds)) {
            return [];
        }

        return PaymentReceipt::whereIn('document_id', $documentIds)
            ->whereIn('client_id', $clientIds)
            ->with(['invoice.company:id,name', 'invoice.brand', 'invoice.client:id,name,email,company_name', 'payment'])
            ->get()
            ->filter(fn (PaymentReceipt $r) => $r->invoice && $r->payment)
            ->mapWithKeys(fn (PaymentReceipt $r) => [
                $r->document_id => array_merge(
                    PaymentReceiptService::facts($r->invoice, $r->payment),
                    ['id' => $r->id, 'generated_at' => $r->created_at],
                ),
            ])
            ->all();
    }

    private function typeFromExtension(string $ext): string
    {
        $ext = strtolower($ext);
        return match (true) {
            $ext === 'pdf' => 'pdf',
            in_array($ext, ['xls', 'xlsx', 'csv']) => 'spreadsheet',
            in_array($ext, ['doc', 'docx']) => 'word',
            in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']) => 'image',
            default => 'other',
        };
    }

    public function download(Request $request, int $id): mixed
    {
        $clientIds  = $this->clientIds($request);
        $projectIds = Project::whereIn('client_id', $clientIds)->pluck('id');

        $doc = Document::where('id', $id)
            ->where('is_visible_to_client', true)
            ->where(function ($q) use ($projectIds, $clientIds) {
                $q->where(function ($sub) use ($projectIds) {
                    $sub->where('linked_to_type', 'project')->whereIn('linked_to_id', $projectIds);
                })->orWhere(function ($sub) use ($clientIds) {
                    $sub->where('linked_to_type', 'client')->whereIn('linked_to_id', $clientIds);
                });
            })
            ->firstOrFail();

        return AttachmentStorageService::download(
            $doc->storage_type, $doc->file_path, $doc->file_name,
            null, $doc->google_drive_file_id, $doc->company_id
        );
    }
}
