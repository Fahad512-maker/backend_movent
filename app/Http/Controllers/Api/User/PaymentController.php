<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\UserCompanyPermission;
use App\Services\DealEligibilityService;
use App\Services\InvoicePaymentService;
use App\Support\PermissionDebug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Finance sub-users verifying/rejecting a customer-submitted payment claim —
// mirrors Api\Admin\PaymentController::confirm()/reject() exactly (same
// InvoicePaymentService calls, same status-flip rules); this endpoint simply
// didn't exist before, leaving "Finance User: Verify offline payments" (from
// the Deal-eligibility spec) unreachable by any sub-user, only Company Admin.
class PaymentController extends Controller
{
    private function user() { return auth('sanctum')->user(); }

    private function can(string $permKey): bool
    {
        $user = $this->user();
        $result = UserCompanyPermission::where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->where('module_key', 'finance')
            ->where('permission_key', $permKey)
            ->exists();
        PermissionDebug::log($user->id, $user->company_id, $user->role_type, 'finance', $permKey, $result);
        return $result;
    }

    private function ownedPayment(int $id): ?Payment
    {
        $payment = Payment::with('invoice')->find($id);
        if (!$payment || !$payment->invoice || $payment->invoice->company_id !== $this->user()->company_id) {
            return null;
        }
        return $payment;
    }

    // Mirrors Admin\PaymentController::recomputeDealEligibility() — the
    // actor here IS a real `users` row, so unlike the Admin mirror, the
    // seller-self-notification case genuinely needs skipping.
    private function recomputeDealEligibility($invoice, int $actorUserId): void
    {
        if (!$invoice || !$invoice->lead_id) return;
        $lead = $invoice->lead;
        if (!$lead) return;

        $before = $lead->fulfillment_status;
        $after  = DealEligibilityService::recomputeFulfillmentStatus($lead);

        if ($before === $after || !$lead->assigned_to || $lead->assigned_to === $actorUserId) {
            return;
        }

        if ($after === 'eligible_for_project') {
            Notification::create([
                'user_id' => $lead->assigned_to, 'company_id' => $lead->company_id,
                'type' => 'deal_eligible_for_project', 'title' => 'Deal eligible for project creation',
                'body' => "The required payment for \"{$lead->proposed_project_title}\" has been received. You may now create the Project.",
                'data' => ['lead_id' => $lead->id, 'link' => "/leads/{$lead->id}"],
            ]);
        } elseif ($before === 'eligible_for_project' && in_array($after, ['partially_paid', 'awaiting_payment'])) {
            Notification::create([
                'user_id' => $lead->assigned_to, 'company_id' => $lead->company_id,
                'type' => 'deal_eligibility_lost', 'title' => 'Project eligibility changed',
                'body' => "Payment eligibility for {$lead->deal_reference} has changed because a payment was refunded, reversed, or removed.",
                'data' => ['lead_id' => $lead->id, 'link' => "/leads/{$lead->id}"],
            ]);
        }
    }

    // PATCH /user/payments/{payment}/confirm
    public function confirm(int $id): JsonResponse
    {
        if (!$this->can('canReconcilePayments')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $payment = $this->ownedPayment($id);
        if (!$payment) {
            return ApiResponse::error('Payment not found', 404);
        }
        if ($payment->status !== 'pending') {
            return ApiResponse::error('Only a pending payment can be confirmed', 422);
        }

        $invoice = $payment->invoice;
        $payment->update([
            'status'         => 'confirmed',
            'receipt_number' => $payment->receipt_number ?? InvoicePaymentService::nextReceiptNumber($invoice->company_id),
        ]);

        InvoicePaymentService::applyToInvoice($invoice, $payment);
        InvoicePaymentService::logDecision($invoice, $payment, 'confirmed');
        InvoicePaymentService::notifyStakeholders($invoice, $payment);
        InvoicePaymentService::logClientActivity($invoice, $payment);
        InvoicePaymentService::logLeadActivity($invoice, $payment);
        $this->recomputeDealEligibility($invoice, $this->user()->id);

        return ApiResponse::success([
            'payment' => $payment->fresh(),
            'invoice' => ['status' => $invoice->status, 'paid_amount' => $invoice->paid_amount, 'total_amount' => $invoice->total_amount],
        ], 'Payment confirmed');
    }

    // PATCH /user/payments/{payment}/reject
    public function reject(Request $request, int $id): JsonResponse
    {
        if (!$this->can('canReconcilePayments')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $payment = $this->ownedPayment($id);
        if (!$payment) {
            return ApiResponse::error('Payment not found', 404);
        }
        if ($payment->status !== 'pending') {
            return ApiResponse::error('Only a pending payment can be rejected', 422);
        }

        $data = $request->validate(['reason' => 'nullable|string|max:500']);
        $invoice = $payment->invoice;

        $payment->update([
            'status' => 'failed',
            'notes'  => trim(($payment->notes ? $payment->notes . ' — ' : '') . ($data['reason'] ?? 'Rejected by finance')),
        ]);

        InvoicePaymentService::logDecision($invoice, $payment, 'rejected');
        InvoicePaymentService::notifyPaymentFailed($invoice, $payment, $data['reason'] ?? null);

        return ApiResponse::success(['payment' => $payment->fresh()], 'Payment rejected');
    }
}
