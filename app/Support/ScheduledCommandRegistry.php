<?php

namespace App\Support;

// Single source of truth for "which commands SHOULD be running, and how
// often" — mirrors routes/console.php's Schedule::command() entries exactly.
// Needed because scheduled_task_logs only ever records a run that actually
// happened: if the server's cron entry is missing entirely, the scheduler
// never fires and NO row gets written — there's nothing in the log table
// itself to say "this is missing." This registry is what lets
// ScheduledTaskLogController compute the other half: "it's been longer than
// expected since this command's last successful run," including a command
// that has NEVER run at all.
//
// Keep this in step with routes/console.php by hand — there's no way to
// introspect Schedule::command()'s configured frequency back out of the
// scheduler at runtime without re-registering it, and duplicating the whole
// schedule definition here just to read it back would be worse than this
// short, manually-kept list.
class ScheduledCommandRegistry
{
    // command => expected interval in minutes between runs.
    private const EXPECTED_INTERVAL_MINUTES = [
        'invoices:mark-overdue'         => 1440, // dailyAt('01:00')
        'invoices:send-due-reminders'   => 1440, // dailyAt('01:10')
        'tasks:notify-overdue'          => 1440, // dailyAt('01:15')
        'compliance:notify-expiring'    => 1440, // dailyAt('01:30')
        'subscription:check-lifecycle'  => 1440, // dailyAt('01:45')
        'attendance:mark-absentees'     => 60,   // hourly()
    ];

    public static function all(): array
    {
        return array_keys(self::EXPECTED_INTERVAL_MINUTES);
    }

    // Overdue threshold = expected interval + a buffer, so ordinary
    // scheduling jitter (the odd slow minute, a deploy restart) never reads
    // as "broken." The buffer is the larger of half the interval or 60
    // minutes — generous enough to not cry wolf, tight enough that a cron
    // entry that's actually gone missing is caught within one extra cycle,
    // not several days later.
    public static function overdueThresholdMinutes(string $command): int
    {
        $interval = self::EXPECTED_INTERVAL_MINUTES[$command] ?? 1440;

        return $interval + max((int) ($interval / 2), 60);
    }
}
