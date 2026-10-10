<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'user_id', 'employee_code', 'name', 'email', 'phone',
        'department', 'designation', 'employment_type', 'salary', 'join_date', 'status',
        // Expected daily schedule — distinct from attendance.check_in/
        // check_out, which are what actually happened on a given day.
        // shift_start_time/shift_end_time are a denormalized cache of the
        // selected Shift's timing (kept in sync by the controllers whenever
        // shift_id changes) so existing display code needs no Shift join.
        'shift_start_time', 'shift_end_time', 'shift_id',
        'created_by', 'created_by_admin_id',
    ];

    protected $casts = [
        'salary'    => 'decimal:2',
        'join_date' => 'date',
    ];

    // Always present in JSON (as `creator`), but only ever populated when the
    // caller eager-loaded createdBy/createdByAdmin — see getCreatorAttribute().
    protected $appends = ['creator'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(EmployeeNote::class);
    }

    // Reuses the existing generic Document model (linked_to_type/linked_to_id
    // polymorphic columns) — no dedicated hr_documents table.
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'linked_to_id')->where('linked_to_type', 'Employee');
    }

    // Dual-actor "who created this" pair — same pattern as Client/Lead/
    // Project/Invoice. withTrashed() on createdBy: a since-removed staff
    // member is still who created this employee record.
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'created_by_admin_id');
    }

    // Only populated when the caller eager-loaded createdBy/createdByAdmin
    // (see Api\Admin\EmployeeController / Api\User\EmployeeController) —
    // deliberately does NOT lazy-load on its own, so a listing endpoint that
    // didn't ask for this doesn't pay an N+1 query per row.
    public function getCreatorAttribute(): ?array
    {
        if ($this->relationLoaded('createdBy') && $this->createdBy) {
            return ['id' => $this->createdBy->id, 'name' => $this->createdBy->name, 'type' => 'user'];
        }
        if ($this->relationLoaded('createdByAdmin') && $this->createdByAdmin) {
            return ['id' => $this->createdByAdmin->id, 'name' => $this->createdByAdmin->name, 'type' => 'admin'];
        }
        return null;
    }
}
