<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    protected $fillable = [
        'project_id', 'parent_task_id', 'assigned_to', 'production_assigned_to', 'assigned_by', 'created_by',
        'title', 'description', 'notes', 'status', 'priority', 'progress', 'task_type', 'is_production_task',
        'estimated_hours', 'start_date', 'due_date', 'completed_at', 'submitted_at', 'approved_at', 'delivered_at',
        'task_number', 'task_sequence',
        'ready_for_production_at',
        'status_changed_by_user_id', 'status_changed_by_admin_id',
    ];

    protected $casts = [
        'estimated_hours'    => 'decimal:2',
        'start_date'         => 'date',
        'due_date'           => 'date',
        'completed_at'       => 'datetime',
        'submitted_at'       => 'datetime',
        'approved_at'        => 'datetime',
        'delivered_at'       => 'datetime',
        'is_production_task' => 'boolean',
        'progress'           => 'integer',
        'ready_for_production_at' => 'datetime',
    ];

    // Canonical ordered pipeline — 'review' and 'cancelled' are legacy/side
    // states (Seller-linked-task pending-PM-review, and cancellation) kept
    // legal but outside this main flow. Movement between any of these is a
    // free jump for an allowed actor — see TaskStatusService.
    public const STATUS_FLOW = [
        'todo', 'in_progress', 'blocked', 'ready_for_production', 'in_production', 'completed',
    ];

    // Every legal db value — STATUS_FLOW plus the legacy 'review'/'cancelled'
    // side states — the single source of truth for both guards' validation
    // 'in:' rule (matches the tasks.status enum exactly).
    public const ALL_STATUSES = [...self::STATUS_FLOW, 'review', 'cancelled'];

    public const STATUS_LABELS = [
        'todo'                  => 'To Do',
        'in_progress'           => 'In Progress',
        'blocked'               => 'Blocked',
        'ready_for_production'  => 'Ready for Production',
        'in_production'         => 'In Production',
        'completed'             => 'Done / Completed',
        'review'                => 'Pending Review',
        'cancelled'             => 'Cancelled',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_task_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_task_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function productionAssignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'production_assigned_to');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function timesheets(): HasMany
    {
        return $this->hasMany(Timesheet::class);
    }

    public function deliverables(): HasMany
    {
        return $this->hasMany(Deliverable::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(TaskActivity::class)->orderByDesc('created_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ProjectTaskAttachment::class);
    }

    // Mirrors Lead::logActivity() — causer_name is a plain string so it works
    // uniformly whether the actor is a sub-user or a Company Admin (neither
    // of which needs a real users.id FK to be identified in this log).
    public function logActivity(string $type, string $description, ?string $causerName = null, array $meta = []): void
    {
        $this->activities()->create([
            'company_id'  => $this->project->company_id,
            'causer_name' => $causerName,
            'type'        => $type,
            'description' => $description,
            'meta'        => $meta ?: null,
        ]);
    }
}
