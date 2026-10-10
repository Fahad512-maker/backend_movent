<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// One row per Schedule::command() run (routes/console.php) — see
// Console\Scheduling\LogsScheduledRuns for how these get written. Platform-
// wide, not scoped to any company/user, so Super Admin is the only audience.
class ScheduledTaskLog extends Model
{
    protected $fillable = [
        'command', 'status', 'exit_code', 'output', 'duration_ms', 'ran_at',
    ];

    protected $casts = [
        'ran_at' => 'datetime',
    ];
}
