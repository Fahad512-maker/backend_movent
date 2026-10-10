<?php

namespace App\Services;

use App\Models\ComplianceCase;

/**
 * Recomputes a Compliance Case's status from its requirements. Statuses
 * `on_hold` and `rejected` are manual-only exits (set by the Phase 2 review
 * controller with a mandatory reason) — this service never sets or clears
 * them. `compliant` is the opposite: automatic only, never set directly by a
 * user action, per the mandatory-compliance spec.
 *
 * Called once today, right after ComplianceCaseService::ensureCaseForProject()
 * seeds a case's requirements. Phase 2 will call it again after every review
 * action (approve/reject/resubmission/expiry) — this method's contract
 * already covers those inputs, so no signature change will be needed then.
 */
class ComplianceCaseStatusService
{
    public static function recompute(ComplianceCase $case): void
    {
        if (in_array($case->status, ['on_hold', 'rejected'], true)) {
            return;
        }

        $requirements = $case->requirements()->get(['status', 'is_mandatory', 'expires_at']);

        if ($requirements->isEmpty()) {
            if ($case->status !== 'not_started') {
                $case->update(['status' => 'not_started']);
            }
            return;
        }

        $mandatory = $requirements->where('is_mandatory', true);

        $allMandatoryApproved = $mandatory->isNotEmpty() && $mandatory->every(function ($r) {
            if ($r->status !== 'approved') {
                return false;
            }
            return !$r->expires_at || $r->expires_at->isFuture() || $r->expires_at->isToday();
        });

        if ($allMandatoryApproved) {
            if ($case->status !== 'compliant') {
                $case->update(['status' => 'compliant', 'compliant_at' => now()]);
            }
            return;
        }

        // Not (yet) fully compliant. Never force a downgrade out of
        // under_review — only the officer's own actions move it back to
        // pending — but always upgrade out of not_started/compliant (e.g. a
        // mandatory requirement expired after compliance was reached).
        if (in_array($case->status, ['not_started', 'compliant'], true)) {
            $case->update(['status' => 'pending']);
        }
    }
}
