<?php

namespace App\Console\Commands;

use App\Services\PermissionRolloutService;
use Illuminate\Console\Command;

// Run on every deploy (see the deploy-setup route in routes/api.php) so a
// permission added to RoleDefaultPermissions::MAP reaches EXISTING users, not
// just newly-created ones. Replaces the per-key backfill migration ritual.
class SyncRolePermissions extends Command
{
    protected $signature = 'permissions:sync {--dry-run : Report what would change without writing anything}';

    protected $description = 'Grant newly-added role-default permissions to existing users';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — nothing will be written.');
        }

        $report = PermissionRolloutService::sync($dryRun);

        foreach ($report['details'] as $line) {
            $this->line($line);
        }

        if ($report['pending'] === 0) {
            $this->info('Already up to date — no new role-default permissions to roll out.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d new permission key(s); %s %d permission row(s) across %d user(s).',
            $dryRun ? 'Found' : 'Rolled out',
            $report['pending'],
            $dryRun ? 'would grant' : 'granted',
            $report['granted'],
            $report['users_touched']
        ));

        if ($dryRun) {
            $this->newLine();
            $this->warn('Nothing was written. Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }
}
