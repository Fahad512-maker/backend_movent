<?php

namespace App\Services;

use App\Models\ComplianceActivityLog;
use App\Models\ComplianceCase;

// Thin append-only logger used by every Compliance mutation (document
// upload/version/review, case status change, checklist toggle, comment,
// officer assignment) so the Project Compliance Detail page has a single
// activity feed to render. Never throws into its caller — a logging
// failure must never undo the mutation that triggered it.
class ComplianceActivityLogService
{
    public static function log(
        ComplianceCase $case,
        string $action,
        string $description,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $oldValue = null,
        ?array $newValue = null,
        ?int $actorAdminId = null,
        ?int $actorUserId = null,
    ): void {
        try {
            ComplianceActivityLog::create([
                'company_id'         => $case->company_id,
                'project_id'         => $case->project_id,
                'client_id'          => $case->client_id,
                'compliance_case_id' => $case->id,
                'actor_admin_id'     => $actorAdminId,
                'actor_user_id'      => $actorUserId,
                'action'             => $action,
                'entity_type'        => $entityType,
                'entity_id'          => $entityId,
                'old_value'          => $oldValue,
                'new_value'          => $newValue,
                'description'        => $description,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[compliance] activity log write failed for case ' . $case->id . ': ' . $e->getMessage());
        }
    }
}
