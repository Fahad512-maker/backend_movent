<?php

namespace App\Services\Finance;

use App\Models\SystemAuditLog;

/**
 * Finance's entries in the existing company audit log.
 *
 * Writes SystemAuditLog rows — the same table Api\User\InvoiceController::
 * auditLog() already writes and that Api\Admin\NotificationController reads
 * unfiltered as the Company Admin's activity bell. Nothing new was built for
 * Finance history: a Finance action shows up in the Company Admin's bell
 * alongside every other module's, which is the point.
 *
 * Each row records who (user_id, or the actor's name in new_values for a
 * Company Admin, who is not a users row), which company, what action, and
 * which resource — the four the spec asks for, plus created_at from the
 * table itself.
 *
 * Never throws. An audit write failing must not roll back the money
 * operation that just succeeded — the same posture InvoicePaymentService
 * takes for every one of its log* methods.
 */
class FinanceAudit
{
    public static function log(
        FinanceContext $ctx,
        int $companyId,
        string $action,
        string $entityType,
        ?int $entityId,
        string $preview,
        array $extra = [],
        array $oldValues = [],
    ): void {
        try {
            SystemAuditLog::create([
                'company_id'  => $companyId,
                // Null for a Company Admin — they have no `users` row, which
                // is why `actor` below carries the name either way.
                'user_id'     => $ctx->actorUserId(),
                'action'      => $action,
                'module_key'  => FinanceContext::MODULE,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'old_values'  => $oldValues ?: null,
                'new_values'  => array_merge([
                    'preview'     => $preview,
                    'actor'       => $ctx->actorName(),
                    'actor_guard' => $ctx->guard,
                ], $extra),
                'ip_address'  => request()->ip(),
                'user_agent'  => substr((string) request()->userAgent(), 0, 500),
            ]);
        } catch (\Throwable) {
            // Deliberately swallowed — see the class note.
        }
    }
}
