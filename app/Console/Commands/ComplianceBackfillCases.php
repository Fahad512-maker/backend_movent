<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\ComplianceCaseService;
use Illuminate\Console\Command;

// Every project must have a Compliance Case (see ComplianceCaseService) —
// this backfills any that predate the Compliance Module, or that ever fell
// through the live auto-create call sites' swallowed exception. Idempotent:
// reuses the exact same ensureCaseForProject() live creation calls, so
// re-running it is always safe and a no-op once every project is covered.
class ComplianceBackfillCases extends Command
{
    protected $signature = 'compliance:backfill-cases {--dry-run : Report counts without writing anything} {--chunk=200 : Rows per chunk}';

    protected $description = 'Create a Compliance Case for every existing project that does not have one';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));

        if ($dryRun) {
            $this->warn('DRY RUN — nothing will be written.');
            $missing = Project::whereDoesntHave('complianceCase')->count();
            $this->info("{$missing} project(s) currently have no Compliance Case.");
            return self::SUCCESS;
        }

        $created = 0;
        $noTemplate = 0;
        $failed = 0;

        Project::whereDoesntHave('complianceCase')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($projects) use (&$created, &$noTemplate, &$failed) {
                foreach ($projects as $project) {
                    try {
                        $case = ComplianceCaseService::ensureCaseForProject($project);

                        if (!$case) {
                            $failed++;
                            $this->error("Project {$project->id}: failed, see log.");
                            continue;
                        }

                        $created++;
                        if ($case->status === 'not_started') {
                            $noTemplate++;
                        }
                    } catch (\Throwable $e) {
                        $failed++;
                        $this->error("Project {$project->id}: {$e->getMessage()}");
                    }
                }
            });

        $this->newLine();
        $this->info("Created {$created} Compliance Case(s); {$noTemplate} with no default template (still 'not_started'); {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
