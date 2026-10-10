<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Project;

// Resolves which existing chat thread a public invoice's "Chat with Seller"
// button should join, and who the Seller actually is.
//
// Once an invoice has a Project (invoices.project_id), the conversation
// belongs there — the same 'project_group' thread staff already read/write
// at /projects/{id}/chat (App\Services\ProjectChatService), filtered to
// visibility='client' exactly like Api\Client\ProjectChatController already
// does for a logged-in Client. Before a Project exists, it falls back to the
// 'sales' thread keyed by Client or Lead (Api\User\SalesChatController,
// Api\Admin\SalesChatController, Api\Client\ChatController all read/write
// that exact same ChatThread::firstOrCreate(['thread_type'=>'sales',
// 'linked_to_type'=>...,'linked_to_id'=>...]) row). A fully guest invoice
// (no Project, no Client, no Lead — just customer_name/email typed directly
// on the invoice) falls back once more to a 'sales' thread keyed by the
// Invoice itself, viewable/repliable by the Seller on the invoice's own
// detail page (Api\User\SalesChatController::invoiceMessages()).
class InvoiceChatContext
{
    // Null means chat isn't available for this invoice — no Project, Client,
    // Lead, or even the invoice's own Seller to anchor a conversation to.
    public static function resolve(Invoice $invoice): ?array
    {
        if ($invoice->project_id && $invoice->project instanceof Project) {
            $project = $invoice->project;

            return [
                'threadType' => 'project_group',
                'project'    => $project,
                'title'      => $project->name,
                'sellerId'   => $project->seller_id
                    ?? $invoice->created_by
                    ?? $invoice->lead?->assigned_to
                    ?? $invoice->lead?->transferred_to,
            ];
        }

        if ($invoice->client_id) {
            $linkedType = 'Client';
            $linkedId   = $invoice->client_id;
            $title      = $invoice->client->name;
        } elseif ($invoice->lead_id) {
            $linkedType = 'Lead';
            $linkedId   = $invoice->lead_id;
            $title      = $invoice->lead->name;
        } elseif ($invoice->created_by || $invoice->created_by_admin_id) {
            // Fully guest invoice — no CRM Lead/Client at all, just
            // customer_name/email typed directly on the invoice form. Still
            // worth a conversation as long as there's a real Seller/Admin on
            // the other end to notify and view it from the invoice page.
            $linkedType = 'Invoice';
            $linkedId   = $invoice->id;
            $title      = $invoice->customer_name ?? $invoice->invoice_number;
        } else {
            return null;
        }

        // Mirrors the "handoff eligible" fallback chain already established
        // in App\Services\InvoicePaymentService::notifyStakeholders() —
        // whoever raised the invoice, else the Lead's current owner (no
        // Project yet at this point, so no Project.seller_id to prefer).
        $sellerId = $invoice->created_by
            ?? $invoice->lead?->assigned_to
            ?? $invoice->lead?->transferred_to;

        return [
            'threadType' => 'sales',
            'linkedType' => $linkedType,
            'linkedId'   => $linkedId,
            'title'      => $title,
            'sellerId'   => $sellerId,
        ];
    }
}
