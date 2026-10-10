<?php

namespace App\Http\Controllers\Api\Finance;

use App\Helpers\ApiResponse;
use App\Models\InvoiceReminder;
use App\Services\Finance\FinanceAudit;
use App\Services\Finance\FinancePresenter;
use App\Services\Finance\FinanceScope;
use App\Services\Finance\InvoiceReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Finance > Invoice Reminders — create, send, track.
 *
 * Three permissions because they are three genuinely different acts:
 * scheduling a chase, actually emailing a client, and reading the history of
 * both. A junior can be allowed to queue reminders for someone else to
 * release; a manager can be allowed to read the log without being able to
 * send anything.
 *
 * The flow the spec describes maps onto these endpoints directly:
 *   Create Reminder  → store() — pick the invoice, which also fixes the
 *                      client (verify() exposes who it will reach, so the
 *                      user confirms the address before saving)
 *   Send             → send(), or the daily
 *                      `invoices:send-due-reminders` command for a scheduled
 *                      one
 *   Track            → index()/show(), which report status, sent_at, the
 *                      address it went to, and the failure reason when it
 *                      did not
 *
 * Delivery itself is InvoiceReminderService's job, and it uses the app's
 * existing Mailable + blade + Notification stack. No separate email system.
 */
class InvoiceReminderController extends FinanceController
{
    /**
     * GET /{admin|user}/finance/invoice-reminders — Track.
     *
     * Also the list the Create screen reads, so someone with create rights
     * but not track rights can still see what they have queued. Those are
     * genuinely their own actions, not the company's history.
     */
    public function index(Request $request): JsonResponse
    {
        $required = [
            ['finance', 'canTrackInvoiceReminders'],
            ['finance', 'canCreateInvoiceReminders'],
            ['finance', 'canSendInvoiceReminders'],
        ];

        if ($fail = $this->guard($request, $required)) {
            return $fail;
        }

        $companyNames = $this->ctx->companyNames();

        $query = FinanceScope::reminders($this->ctx)
            ->with([
                'invoice:id,company_id,invoice_number,client_id,lead_id,currency,customer_name,total_amount,paid_amount,status,due_date',
                // paymentProgress() on the reminder's invoice reads payments; without
                // this the list fires one query per reminder.
                'invoice.payments:id,invoice_id,status,created_at',
                'invoice.client:id,name,company_name',
                'invoice.lead:id,name',
                'createdBy:id,name', 'createdByAdmin:id,name',
                'sentBy:id,name', 'sentByAdmin:id,name',
            ]);

        if ($request->filled('company_id') && $this->ctx->ownsCompany((int) $request->company_id)) {
            $query->where('invoice_reminders.company_id', (int) $request->company_id);
        }
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('invoice_reminders.status', $request->status);
        }
        if ($request->filled('invoice_id')) {
            $query->where('invoice_reminders.invoice_id', (int) $request->invoice_id);
        }
        if ($request->filled('from')) {
            $query->whereDate('invoice_reminders.reminder_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('invoice_reminders.reminder_date', '<=', $request->to);
        }

        // Someone who can only create — not track — sees their own queue and
        // nothing else. Enforced here rather than in the UI, so it holds for
        // a direct API call too.
        //
        // Matched on whichever actor column this caller actually occupies,
        // never both: a staff member has no admin id, and
        // `where('created_by_admin_id', null)` compiles to `IS NULL`, which
        // is true of every staff-created row — so an or-both version of this
        // check silently matched the whole company's queue.
        if (!$this->ctx->can('canTrackInvoiceReminders')) {
            $column = $this->ctx->isAdmin() ? 'created_by_admin_id' : 'created_by';
            $actorId = $this->ctx->isAdmin() ? $this->ctx->actorAdminId() : $this->ctx->actorUserId();

            // No resolvable actor id means nothing can be "theirs".
            $actorId
                ? $query->where("invoice_reminders.{$column}", $actorId)
                : $query->whereRaw('1 = 0');
        }

        $perPage = min(max((int) $request->input('per_page', 25), 1), 200);
        $page    = $query->orderByDesc('invoice_reminders.id')->paginate($perPage);

        return ApiResponse::success([
            'reminders' => collect($page->items())
                ->map(fn ($r) => FinancePresenter::reminderRow($r, $companyNames))
                ->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
            ],
            'filters' => [
                'companies' => collect($companyNames)->map(fn ($n, $id) => ['id' => (int) $id, 'name' => $n])->values()->all(),
                'statuses'  => InvoiceReminder::STATUSES,
                'types'     => InvoiceReminder::TYPES,
                'deliverable_types' => InvoiceReminder::DELIVERABLE_TYPES,
            ],
            'capabilities' => $this->capabilities($this->ctx),
        ]);
    }

    /**
     * GET /{admin|user}/finance/invoice-reminders/verify?invoice_id=
     *
     * The "verify client" step of the flow: before saving, show WHICH
     * invoice this is, WHO the reminder would reach and WHAT is still owed,
     * so nobody schedules a chase against the wrong customer or an invoice
     * that is already settled.
     *
     * Also the list of chaseable invoices when no invoice_id is given.
     */
    public function verify(Request $request): JsonResponse
    {
        if ($fail = $this->guard($request, [['finance', 'canCreateInvoiceReminders'], ['finance', 'canSendInvoiceReminders']])) {
            return $fail;
        }

        if (!$request->filled('invoice_id')) {
            // Invoices worth chasing: sent (or part paid), not settled, not
            // cancelled, not still a draft nobody has seen.
            $options = FinanceScope::invoices($this->ctx)
                ->whereNotIn('invoices.status', ['draft', 'cancelled', 'paid'])
                ->whereColumn('invoices.paid_amount', '<', 'invoices.total_amount')
                // payments is eager-loaded because paymentProgress() below
                // reads it — without this the list fires one query per
                // invoice (see the note on Invoice::$appends).
                ->with(['client:id,name,company_name', 'lead:id,name', 'payments:id,invoice_id,status,created_at'])
                ->orderBy('invoices.due_date')
                ->limit(200)
                ->get(['id', 'company_id', 'invoice_number', 'client_id', 'lead_id', 'customer_name', 'customer_email', 'currency', 'total_amount', 'paid_amount', 'due_date', 'status'])
                ->map(fn ($i) => [
                    'id'             => $i->id,
                    'invoice_number' => $i->invoice_number,
                    'customer_name'  => $i->client?->name ?? $i->lead?->name ?? $i->customer_name ?? '—',
                    'currency'       => $i->currency,
                    'outstanding'    => $i->paymentProgress()['remaining_amount'],
                    'due_date'       => $i->due_date?->toDateString(),
                    'status'         => $i->status,
                    'is_overdue'     => $i->isPastDue(),
                ]);

            return ApiResponse::success(['invoices' => $options]);
        }

        $invoice = FinanceScope::findInvoice($this->ctx, (int) $request->invoice_id, ['client:id,name,company_name,email', 'lead:id,name,email']);
        if (!$invoice) {
            return $this->notFound('Invoice');
        }

        $recipient = InvoiceReminderService::recipientFor($invoice);
        $progress  = $invoice->paymentProgress();

        return ApiResponse::success([
            'invoice' => [
                'id'             => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'company_id'     => $invoice->company_id,
                'status'         => $invoice->status,
                'payment_status' => $progress['payment_status'],
                'currency'       => $invoice->currency,
                'total_amount'   => $progress['total_amount'],
                'paid_amount'    => $progress['paid_amount'],
                'outstanding'    => $progress['remaining_amount'],
                'due_date'       => $invoice->due_date?->toDateString(),
                'is_overdue'     => $invoice->isPastDue(),
            ],
            'client' => [
                'id'    => $invoice->client_id,
                'name'  => $invoice->client?->name ?? $invoice->lead?->name ?? $invoice->customer_name,
                'email' => $recipient,
            ],
            // Everything the user needs to decide whether to go ahead —
            // returned as facts rather than as a blocked request, because a
            // reminder for a settled invoice is a mistake to warn about, not
            // an attack to refuse.
            'can_send' => $recipient !== null
                && $progress['remaining_amount'] > 0
                && !in_array($invoice->status, ['cancelled', 'draft'], true),
            'warnings' => array_values(array_filter([
                $recipient === null ? 'This invoice has no customer email address on file.' : null,
                $progress['remaining_amount'] <= 0 ? 'This invoice is already settled.' : null,
                $invoice->status === 'draft' ? 'This invoice is still a draft and has not been sent.' : null,
                $invoice->status === 'cancelled' ? 'This invoice has been cancelled.' : null,
            ])),
        ]);
    }

    // POST /{admin|user}/finance/invoice-reminders
    public function store(Request $request): JsonResponse
    {
        if ($fail = $this->guard($request, 'canCreateInvoiceReminders')) {
            return $fail;
        }

        $data = $request->validate([
            'invoice_id'    => 'required|integer',
            'type'          => 'nullable|in:' . implode(',', InvoiceReminder::TYPES),
            'reminder_date' => 'nullable|date|after_or_equal:today',
            'subject'       => 'nullable|string|max:255',
            'message'       => 'nullable|string|max:2000',
            // Convenience: create and release in one step, for the common
            // "chase this now" case. Still gated on the SEND permission
            // below — creating never implies sending.
            'send_now'      => 'nullable|boolean',
        ]);

        $invoice = FinanceScope::findInvoice($this->ctx, (int) $data['invoice_id'], ['client:id,name,email', 'lead:id,name,email']);
        if (!$invoice) {
            return $this->notFound('Invoice');
        }

        if (!$this->ctx->can('canCreateInvoiceReminders', 'finance', $invoice->company_id)) {
            return ApiResponse::error('Permission denied', 403);
        }

        $reminder = InvoiceReminderService::create($this->ctx, $invoice, $data);

        $message = 'Reminder created';

        if ($request->boolean('send_now')) {
            if (!$this->ctx->can('canSendInvoiceReminders', 'finance', $invoice->company_id)) {
                // The reminder is kept — it was validly created, and losing
                // it because the same person could not also send would be
                // the wrong failure. The response says what happened.
                return ApiResponse::success(
                    ['reminder' => FinancePresenter::reminderRow($reminder->fresh($this->relations()), $this->ctx->companyNames())],
                    'Reminder saved, but you do not have permission to send it.',
                    201
                );
            }

            $reminder = InvoiceReminderService::send($this->ctx, $reminder);
            $message  = $reminder->status === 'sent'
                ? 'Reminder sent'
                : ($reminder->failure_reason ?: 'Reminder could not be sent');
        }

        return ApiResponse::success(
            ['reminder' => FinancePresenter::reminderRow($reminder->fresh($this->relations()), $this->ctx->companyNames())],
            $message,
            201
        );
    }

    // POST /{admin|user}/finance/invoice-reminders/{id}/send
    public function send(Request $request, int $id): JsonResponse
    {
        if ($fail = $this->guard($request, 'canSendInvoiceReminders')) {
            return $fail;
        }

        $reminder = FinanceScope::findReminder($this->ctx, $id, ['invoice.client:id,name,email', 'invoice.lead:id,name,email']);
        if (!$reminder) {
            return $this->notFound('Reminder');
        }

        if (!$this->ctx->can('canSendInvoiceReminders', 'finance', $reminder->company_id)) {
            return ApiResponse::error('Permission denied', 403);
        }

        if ($reminder->status === 'sent') {
            return ApiResponse::error('This reminder has already been sent.', 422);
        }
        if ($reminder->status === 'cancelled') {
            return ApiResponse::error('This reminder was cancelled.', 422);
        }

        $reminder = InvoiceReminderService::send($this->ctx, $reminder);

        $payload = ['reminder' => FinancePresenter::reminderRow($reminder->fresh($this->relations()), $this->ctx->companyNames())];

        // A delivery failure is a recorded outcome, not a server error — the
        // row now says 'failed' with a reason, and Track shows it. Reported
        // as 422 so the UI surfaces the reason rather than a generic toast.
        return $reminder->status === 'sent'
            ? ApiResponse::success($payload, 'Reminder sent')
            : ApiResponse::error($reminder->failure_reason ?: 'Reminder could not be sent', 422, $payload);
    }

    // PATCH /{admin|user}/finance/invoice-reminders/{id}/cancel
    public function cancel(Request $request, int $id): JsonResponse
    {
        if ($fail = $this->guard($request, 'canCreateInvoiceReminders')) {
            return $fail;
        }

        $reminder = FinanceScope::findReminder($this->ctx, $id, ['invoice:id,invoice_number']);
        if (!$reminder) {
            return $this->notFound('Reminder');
        }

        if (!$this->ctx->can('canCreateInvoiceReminders', 'finance', $reminder->company_id)) {
            return ApiResponse::error('Permission denied', 403);
        }

        if ($reminder->status === 'sent') {
            return ApiResponse::error('A reminder that has already been sent cannot be cancelled.', 422);
        }

        $reminder->forceFill(['status' => 'cancelled'])->save();

        FinanceAudit::log(
            $this->ctx,
            (int) $reminder->company_id,
            'finance_reminder_cancelled',
            'InvoiceReminder',
            $reminder->id,
            "Reminder for invoice {$reminder->invoice?->invoice_number} cancelled",
        );

        return ApiResponse::success(
            ['reminder' => FinancePresenter::reminderRow($reminder->fresh($this->relations()), $this->ctx->companyNames())],
            'Reminder cancelled'
        );
    }

    /** @return array<int, string> */
    private function relations(): array
    {
        return [
            'invoice:id,company_id,invoice_number,client_id,lead_id,currency,customer_name,total_amount,paid_amount,status,due_date',
            // paymentProgress() on the reminder's invoice reads payments; without
            // this the list fires one query per reminder.
            'invoice.payments:id,invoice_id,status,created_at',
            'invoice.client:id,name,company_name',
            'invoice.lead:id,name',
            'createdBy:id,name', 'createdByAdmin:id,name',
            'sentBy:id,name', 'sentByAdmin:id,name',
        ];
    }
}
