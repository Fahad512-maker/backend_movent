<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'reference',
        'company_id', 'client_id', 'company_service_id', 'lead_id', 'invoice_id', 'project_manager_id', 'created_by', 'created_by_admin_id',
        // seller_id/source: set when a Seller creates this as a sales handoff
        // (see Api\User\ProjectController::store()) — seller_id is who
        // initiated it, independent of whoever ends up as project_manager_id.
        'seller_id', 'source',
        'seller_assigned_by', 'seller_assigned_by_admin_id', 'seller_assigned_at',
        'name', 'description', 'status', 'priority', 'budget', 'start_date',
        'deadline', 'storage_folder', 'completed_at',
        'completed_by', 'completed_by_admin_id',
        'closed_at', 'closed_by', 'closed_by_admin_id', 'close_reason',
        'reopened_at', 'reopened_by', 'reopened_by_admin_id', 'reopen_reason',
        'completion_approved_at', 'completion_approved_by_admin_id',
        'reopen_requested_at', 'reopen_requested_by', 'reopen_request_reason',
        'delivery_status', 'delivery_file_path', 'delivery_file_name', 'delivery_file_type', 'delivery_file_size',
        'delivery_submitted_at', 'delivery_submitted_by', 'delivery_approved_at', 'delivery_approved_by_admin_id',
        // Google Drive company storage — see App\Services\AttachmentStorageService.
        'delivery_storage_type', 'delivery_google_drive_file_id', 'delivery_google_drive_folder_id',
    ];

    protected $casts = [
        'budget'       => 'decimal:2',
        'start_date'   => 'date',
        'deadline'     => 'date',
        'completed_at' => 'datetime',
        'closed_at'    => 'datetime',
        'reopened_at'  => 'datetime',
        'seller_assigned_at' => 'datetime',
        'delivery_submitted_at' => 'datetime',
        'delivery_approved_at' => 'datetime',
        'completion_approved_at' => 'datetime',
        'reopen_requested_at' => 'datetime',
    ];

    // Statuses that mean "not real work yet". Legacy from before project
    // creation moved entirely behind payment (see App\Services\
    // PaymentProjectStartService::createActiveProject()) — a Project is now
    // created already 'active', so nothing produces a 'draft' or 'unpaid' row
    // anymore, but any that already exist from before must still be excluded
    // from real work everywhere. Neither is work yet, so the Client Portal
    // must never surface either one. Keeping the rule here means every
    // Api\Client\* query states the same thing the same way.
    private const PRE_ACTIVATION_STATUSES = ['draft', 'unpaid'];

    public function scopeNotDraft($query)
    {
        return $query->whereNotIn('status', self::PRE_ACTIVATION_STATUSES);
    }

    // A draft (or unpaid) isn't work yet, so nothing that PRODUCES work may
    // happen on it: no tasks, timesheets, files, deliverables, comments or
    // chat messages (see the isDraft() guards across Api\User\* and
    // Api\Admin\*). Setting the project UP is still allowed — editing it,
    // naming a PM, assigning a team or seller, linking invoices — since that
    // is exactly what someone does before pressing Activate. Everything
    // blocked here opens up the moment the project becomes active; no
    // separate switch to flip.
    public function isDraft(): bool
    {
        return in_array($this->status, self::PRE_ACTIVATION_STATUSES, true);
    }

    // One wording for every guard, so a draft explains itself the same way
    // wherever the user runs into it.
    public const DRAFT_BLOCKED_MESSAGE = 'This project is still a draft. Activate it first — tasks, timesheets, files, comments and chat all open up once it is active.';

    // 'closed' is the pre-existing terminal/archival state; 'approved_locked'
    // is the new one reached via Api\Admin\ProjectController::
    // approveCompletion() once Admin signs off on a 'completed' project. Both
    // are equally read-only for every mutation guarded here — viewing, chat
    // and comments stay open regardless (see the Project Approval Lock
    // feature notes). Only Admin can lift either one (reopen()); a PM can
    // only request it (requestReopen()).
    public function isLocked(): bool
    {
        return in_array($this->status, ['closed', 'approved_locked'], true);
    }

    public const LOCKED_MESSAGE = 'This project is locked. Ask an Admin to reopen it before making changes.';

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    // Which of the company's services this project delivered. Nullable and
    // null on every project that predates the column — see
    // ServiceRecommendationService for the name-matching fallback that covers
    // those, and withTrashed() so a project keeps naming its service after the
    // admin removes it from their list.
    public function companyService(): BelongsTo
    {
        return $this->belongsTo(CompanyService::class, 'company_service_id')->withTrashed();
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    // Every invoice billed under this project (deposit/milestone/final/change
    // request), keyed off invoices.project_id — distinct from invoice()
    // above, which is only the single invoice this project originated from.
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'project_id');
    }

    public function projectManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'project_manager_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function sellerAssignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_assigned_by');
    }

    public function sellerAssignedByAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'seller_assigned_by_admin_id');
    }

    public function sellerAssignments(): HasMany
    {
        return $this->hasMany(ProjectSellerAssignment::class)->orderByDesc('created_at');
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'created_by_admin_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function completedByAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'completed_by_admin_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function closedByAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'closed_by_admin_id');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function reopenedByAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'reopened_by_admin_id');
    }

    public function deliverySubmittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivery_submitted_by');
    }

    public function deliveryApprovedByAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'delivery_approved_by_admin_id');
    }

    public function completionApprovedByAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'completion_approved_by_admin_id');
    }

    public function reopenRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopen_requested_by');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function teamMembers(): HasMany
    {
        return $this->hasMany(ProjectTeamMember::class);
    }

    public function deliverables(): HasMany
    {
        return $this->hasMany(Deliverable::class);
    }

    // History of the project-level "final package" handoff — distinct from
    // deliverables() above (task-level production QA submissions).
    public function deliverySubmissions(): HasMany
    {
        return $this->hasMany(ProjectDeliverySubmission::class)->orderByDesc('delivered_at');
    }

    public function folders(): HasMany
    {
        return $this->hasMany(ProjectFolder::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'linked_to_id');
    }

    // The Compliance Case every project must have (see ComplianceCaseService
    // ::ensureCaseForProject()) — deliberately distinct from the unused
    // compliancePolicies()/riskAssessments()/etc. relations below, which
    // belong to an earlier, dead compliance scaffold.
    public function complianceCase(): HasOne
    {
        return $this->hasOne(ComplianceCase::class);
    }

    public function compliancePolicies(): HasMany
    {
        return $this->hasMany(CompliancePolicy::class);
    }

    public function riskAssessments(): HasMany
    {
        return $this->hasMany(RiskAssessment::class);
    }

    public function complianceItems(): HasMany
    {
        return $this->hasMany(ComplianceItem::class);
    }

    public function complianceIncidents(): HasMany
    {
        return $this->hasMany(ComplianceIncident::class);
    }

    public function complianceViolations(): HasMany
    {
        return $this->hasMany(ComplianceViolation::class);
    }

    public function auditTrails(): HasMany
    {
        return $this->hasMany(AuditTrail::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ProjectActivity::class)->orderByDesc('created_at');
    }

    // Mirrors Task::logActivity() — causer_name is a plain string so it works
    // uniformly whether the actor is a sub-user or a Company Admin (neither
    // of which needs a real users.id FK to be identified in this log).
    public function logActivity(string $type, string $description, ?string $causerName = null, array $meta = []): void
    {
        $this->activities()->create([
            'company_id'  => $this->company_id,
            'causer_name' => $causerName,
            'type'        => $type,
            'description' => $description,
            'meta'        => $meta ?: null,
        ]);
    }

    // Shared field-reset for leaving 'completed'/'approved_locked'/'closed'
    // back to 'active' — used by both Api\Admin\ProjectController::reopen()
    // (an Admin acting deliberately) and the automatic reopen a new Project
    // Support ticket triggers (Api\Client\SupportController::store()), so
    // the two never drift out of sync on which fields get cleared.
    // Exactly one of $adminId/$userId should be passed; the automatic path
    // passes neither since no one "performed" the action.
    public function reopen(string $reason, ?int $adminId = null, ?int $userId = null): void
    {
        $this->update([
            'status'                          => 'active',
            'reopened_at'                     => now(),
            'reopened_by_admin_id'            => $adminId,
            'reopened_by'                     => $userId,
            'reopen_reason'                   => $reason,
            'completed_at'                    => null,
            'completed_by'                    => null,
            'completed_by_admin_id'           => null,
            'closed_at'                       => null,
            'closed_by'                       => null,
            'closed_by_admin_id'              => null,
            'close_reason'                    => null,
            'completion_approved_at'          => null,
            'completion_approved_by_admin_id' => null,
            'reopen_requested_at'             => null,
            'reopen_requested_by'             => null,
            'reopen_request_reason'           => null,
        ]);
    }
}
