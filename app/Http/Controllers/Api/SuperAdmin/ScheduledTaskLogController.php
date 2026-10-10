<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ScheduledTaskLog;
use App\Support\ScheduledCommandRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

// Read-only view onto scheduled_task_logs (see App\Support\ScheduleLogger,
// routes/console.php) — whether the server cron is actually firing the
// scheduler, and whether each command succeeded, is otherwise invisible.
class ScheduledTaskLogController extends Controller
{
    // GET /super-admin/scheduled-task-logs?command=X&status=failed&page=N
    public function index(Request $request): JsonResponse
    {
        $query = ScheduledTaskLog::query()->orderByDesc('ran_at');

        if ($request->filled('command')) {
            $query->where('command', $request->string('command'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $logs = $query->paginate(50);

        // The distinct command list — lets the UI offer a filter dropdown
        // without hardcoding routes/console.php's entries a second time.
        $commands = ScheduledTaskLog::query()->distinct()->orderBy('command')->pluck('command');

        return ApiResponse::success([
            'logs'     => $logs->items(),
            'page'     => $logs->currentPage(),
            'last_page' => $logs->lastPage(),
            'total'    => $logs->total(),
            'commands' => $commands,
            'health'   => $this->health(),
        ]);
    }

    // The other half of "is the scheduler actually working" that the log
    // table alone can't answer: a missing server cron entry means the
    // scheduler never fires at all, so NOTHING gets logged — there's no row
    // to point at. This walks every command routes/console.php is SUPPOSED
    // to run (ScheduledCommandRegistry, kept in step with it by hand) and
    // flags one as overdue/never-run based on wall-clock time elapsed since
    // its last actual success, regardless of what (if anything) is in the
    // log table.
    private function health(): array
    {
        return collect(ScheduledCommandRegistry::all())->map(function (string $command) {
            $lastSuccess = ScheduledTaskLog::where('command', $command)
                ->where('status', 'success')
                ->orderByDesc('ran_at')
                ->first();

            $thresholdMinutes = ScheduledCommandRegistry::overdueThresholdMinutes($command);
            $minutesSince = $lastSuccess ? (int) round($lastSuccess->ran_at->diffInMinutes(Carbon::now())) : null;
            $overdue = $minutesSince === null || $minutesSince > $thresholdMinutes;

            return [
                'command'          => $command,
                'last_success_at'  => $lastSuccess?->ran_at,
                'minutes_since'    => $minutesSince,
                'threshold_minutes' => $thresholdMinutes,
                'overdue'          => $overdue,
            ];
        })->values()->all();
    }
}
