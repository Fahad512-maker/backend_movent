<?php

namespace App\Services;

use App\Models\ChatThread;
use App\Models\Invoice;
use App\Models\Project;

// Where a staff member's Sales Chat conversation actually lives right now.
//
// A paid invoice turns the pre-payment 'sales' conversation into a project
// conversation: PaymentProjectStartService::migrateChatHistory() moves the
// messages into the project's 'project_group' thread and records a forwarding
// pointer. The client's two surfaces already follow that move — the public
// invoice link via InvoiceChatContext::resolve() and the client portal via
// Api\Client\ProjectChatController, both reading the visibility='client' slice
// of the project thread. The staff side did not: Api\User\SalesChatController
// and Api\Admin\SalesChatController resolve thread_type='sales' unconditionally,
// so after payment a Seller/Lead Manager/Admin opened an empty Sales Chat and
// every reply they sent went somewhere the client never reads.
//
// Both guards' controllers route through here so the two behave identically.
class SalesConversationResolver
{
    /**
     * @param bool $create Whether a missing project thread may be created.
     *                     Reads pass false and writes pass true — the same
     *                     split Api\PublicInvoiceChatController already makes
     *                     (existingThreadFor() to display, threadFor() to
     *                     send), so merely looking at a chat never brings a
     *                     thread into existence.
     */
    public static function resolve(ChatThread $salesThread, string $linkedType, int $linkedId, bool $create = false): ChatThread
    {
        // 1. The forwarding pointer, when payment recorded one. Authoritative:
        //    it names the exact project this conversation drained into, which
        //    nothing else can reconstruct once the rows have moved.
        $forwarded = $salesThread->activeThread();
        if ($forwarded->id !== $salesThread->id) {
            return $forwarded;
        }

        // 2. No pointer. Either this sales thread was first opened AFTER the
        //    project already existed (firstOrCreate() made a fresh one, which
        //    payment never saw), or the payment predates the pointer column.
        //    Recover it from the invoice that created the project — but only
        //    when it is UNAMBIGUOUS. A Client with two projects gives no basis
        //    for choosing, and guessing would drop staff into the wrong
        //    client's conversation, so that case stays on the sales thread.
        $projectId = static::soleProjectIdFor($linkedType, $linkedId);
        if (!$projectId) {
            return $salesThread;
        }

        $project = Project::find($projectId);
        if (!$project) {
            return $salesThread;
        }

        if (!$create) {
            return ProjectChatService::existingThreadFor($project) ?? $salesThread;
        }

        return ProjectChatService::threadFor($project);
    }

    private static function soleProjectIdFor(string $linkedType, int $linkedId): ?int
    {
        $query = Invoice::query()->whereNotNull('project_id');

        match ($linkedType) {
            // A fully guest invoice anchors the thread to itself.
            'Invoice' => $query->where('id', $linkedId),
            'Lead'    => $query->where('lead_id', $linkedId),
            'Client'  => $query->where('client_id', $linkedId),
            default   => $query->whereRaw('1 = 0'),
        };

        $projectIds = $query->distinct()->pluck('project_id');

        return $projectIds->count() === 1 ? (int) $projectIds->first() : null;
    }
}
