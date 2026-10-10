<?php

namespace App\Services;

use App\Models\ComplianceCase;
use App\Models\ComplianceRequirement;
use App\Models\ComplianceTemplate;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Every Project must have exactly one Compliance Case — no "Compliance
 * Required Y/N" toggle. This is the single place that creates one, called
 * from every project-creation path (Api\User\ProjectController::store(),
 * Api\Admin\ProjectController::store(), PaymentProjectStartService
 * ::finishActivation()) and from the `compliance:backfill-cases` command.
 *
 * Designed to be safe to call fire-and-forget: it never throws, and is
 * idempotent under concurrent/retried calls via the unique constraint on
 * compliance_cases.project_id.
 */
class ComplianceCaseService
{
    public static function ensureCaseForProject(Project $project): ?ComplianceCase
    {
        try {
            $case = ComplianceCase::firstOrCreate(
                ['project_id' => $project->id],
                [
                    'company_id' => $project->company_id,
                    'client_id'  => $project->client_id,
                    'status'     => 'not_started',
                ]
            );
        } catch (QueryException $e) {
            // Lost a race against another concurrent/retried request — the
            // winner's row now satisfies the unique constraint, fetch it.
            $case = ComplianceCase::where('project_id', $project->id)->first();
            if (!$case) {
                Log::error('[compliance] ensureCaseForProject project ' . $project->id . ': ' . $e->getMessage());
                return null;
            }
            return $case;
        } catch (\Throwable $e) {
            Log::error('[compliance] ensureCaseForProject project ' . $project->id . ': ' . $e->getMessage());
            return null;
        }

        if (!$case->wasRecentlyCreated) {
            return $case;
        }

        try {
            self::seedFromDefaultTemplate($case, $project);
        } catch (\Throwable $e) {
            Log::error('[compliance] seedFromDefaultTemplate project ' . $project->id . ': ' . $e->getMessage());
        }

        // seedFromDefaultTemplate() (and the status recompute inside it)
        // mutate the DB row, not this in-memory $case instance — reload so
        // every caller (backfill reporting, controllers, tests) sees the
        // real post-seed status/template_id instead of the pre-seed values.
        return $case->fresh();
    }

    private static function seedFromDefaultTemplate(ComplianceCase $case, Project $project): void
    {
        $template = ComplianceTemplate::where('company_id', $project->company_id)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();

        if (!$template) {
            NotificationService::notifyCompanyAdmins($project->company_id, null, [
                'module'  => 'compliance',
                'type'    => 'compliance_no_default_template',
                'title'   => 'No default Compliance Template configured',
                'message' => "Project \"{$project->name}\" has no compliance requirements yet — set a default Compliance Template for this company.",
                'entity_type' => 'Project',
                'entity_id'   => $project->id,
                'url'         => "/admin/compliance/projects/{$project->id}",
            ]);
            return;
        }

        $case->update(['template_id' => $template->id]);

        foreach ($template->requirements as $templateRequirement) {
            ComplianceRequirement::create([
                'company_id'              => $project->company_id,
                'project_id'              => $project->id,
                'compliance_case_id'      => $case->id,
                'template_requirement_id' => $templateRequirement->id,
                'source'                  => 'template',
                'name'                    => $templateRequirement->name,
                'description'             => $templateRequirement->description,
                'category'                => $templateRequirement->category,
                'is_mandatory'            => $templateRequirement->is_mandatory,
                'requires_expiry'         => $templateRequirement->requires_expiry,
                'expires_at'              => $templateRequirement->requires_expiry && $templateRequirement->default_validity_days
                    ? now()->addDays($templateRequirement->default_validity_days)->toDateString()
                    : null,
                'sort_order'              => $templateRequirement->sort_order,
            ]);
        }

        ComplianceCaseStatusService::recompute($case->fresh());
    }
}
