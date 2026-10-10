<?php

namespace App\Support;

use App\Models\ScheduledTaskLog;
use Illuminate\Console\Scheduling\Event;

// Wires a single Schedule::command() entry (routes/console.php) to write one
// scheduled_task_logs row per run, success or failure — nothing currently
// records whether these ever actually ran, so a silently-broken cron entry
// (server cron missing, a command throwing, a bad deploy) looks identical to
// "nothing to do today" from the outside. One call per command:
//
//   ScheduleLogger::wrap(Schedule::command('invoices:mark-overdue')->dailyAt('01:00'), 'invoices:mark-overdue');
//
// Uses sendOutputTo() (overwrites), not appendOutputTo() (which would keep
// growing forever and mix every past run's output into "this run's" log
// row) — the temp file is read once right after the run and its content
// copied into the DB row; the file itself is disposable.
class ScheduleLogger
{
    public static function wrap(Event $event, string $command): Event
    {
        $outputPath = storage_path('logs/scheduler-' . str_replace([':', ' '], '-', $command) . '.log');
        $startedAt = null;

        $event->sendOutputTo($outputPath);

        $event->before(function () use (&$startedAt) {
            $startedAt = microtime(true);
        });

        $record = function (string $status, ?int $exitCode) use ($command, $outputPath, &$startedAt) {
            ScheduledTaskLog::create([
                'command'     => $command,
                'status'      => $status,
                'exit_code'   => $exitCode,
                'output'      => self::readOutput($outputPath),
                'duration_ms' => $startedAt ? (int) round((microtime(true) - $startedAt) * 1000) : null,
                'ran_at'      => now(),
            ]);
        };

        // Laravel's scheduler determines success/failure from the
        // sub-process's own exit code — 0 is success, anything else
        // (including an uncaught exception inside the command) is a
        // failure. Covers both: a command that throws, and one that
        // deliberately returns Command::FAILURE.
        $event->onSuccess(fn () => $record('success', 0));
        $event->onFailure(fn () => $record('failed', $event->exitCode ?? 1));

        return $event;
    }

    private static function readOutput(string $path): ?string
    {
        if (!file_exists($path)) {
            return null;
        }

        // Cap what lands in the DB — a runaway command printing megabytes
        // shouldn't bloat this table; the full output still exists on disk
        // at $path for anyone who needs more.
        $contents = trim((string) file_get_contents($path));

        return mb_strlen($contents) > 10000 ? mb_substr($contents, -10000) : $contents;
    }
}
