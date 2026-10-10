<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'email', 'password', 'google_id', 'role_type', 'custom_role_label', 'phone',
        'avatar_path', 'is_active', 'status', 'socket_id', 'is_online', 'created_by',
        'invite_token', 'invite_expires_at', 'must_change_password', 'last_login_at', 'last_seen_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'is_active'            => 'boolean',
        'is_online'            => 'boolean',
        'must_change_password' => 'boolean',
        'last_login_at'        => 'datetime',
        'last_seen_at'         => 'datetime',
        'invite_expires_at'    => 'datetime',
        'password'             => 'hashed',
    ];

    /**
     * How long after their last request someone still counts as present.
     *
     * Three minutes, against a last_seen_at that App\Http\Middleware\
     * TouchLastSeen refreshes at most once a minute: long enough that reading
     * a long message or filling a form never flips a working seller to
     * "Offline", short enough that someone who shut their laptop stops
     * claiming to be there.
     *
     * Not the `is_online` column — see the 2026_09_30 migration for why that
     * one is vestigial and never written.
     */
    public const ONLINE_WINDOW_MINUTES = 3;

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subMinutes(self::ONLINE_WINDOW_MINUTES));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(UserPermission::class);
    }

    public function companyAssignments(): HasMany
    {
        return $this->hasMany(CompanyUserAssignment::class);
    }

    // Company-membership check that also covers a multi-company user — true
    // for either the legacy primary company_id column OR an ACTIVE
    // company_user_assignments row for $companyId. A user assigned to more
    // than one company keeps this same company_id column pointed at just
    // one of them (whichever was picked as "default" when they were added),
    // so any "who belongs to company X" query that checks that column alone
    // silently drops them from every OTHER company they're actually active
    // in. Mirrors the pattern already used by
    // ProjectSellerAssignmentService::assignableSeller() and
    // Api\Admin\ProjectController::projectUsers()'s Seller block.
    public function scopeOfCompany($query, int $companyId)
    {
        return $query->where(function ($q) use ($companyId) {
            $q->where('company_id', $companyId)
                ->orWhereHas('companyAssignments', fn ($a) => $a
                    ->where('company_id', $companyId)
                    ->where('status', 'active'));
        });
    }

    public function userCompanyPermissions(): HasMany
    {
        return $this->hasMany(UserCompanyPermission::class);
    }

    /**
     * Users who hold any of $roles IN THIS COMPANY.
     *
     * The replacement for `->where('role_type', 'seller')` in every "list the
     * company's sellers" query. That column holds ONE role for the whole
     * account, so once a person could hold several roles it stopped answering
     * the question: someone given Seller as a second role still had
     * role_type='project_manager' and vanished from every Seller picker in the
     * app even though they plainly held the role.
     *
     * Two ways to match, and the order matters:
     *   1. A user_company_roles row for THIS company. Authoritative — it is the
     *      only company-scoped record of what someone is here.
     *   2. The legacy role_type, but ONLY for a user with no role rows in this
     *      company at all. That is the pre-multi-role account (or one created
     *      through a caller that sends neither `roles` nor `role_type`), and
     *      without this they would drop out of every picker.
     *
     * The guard on (2) is what keeps roles company-scoped: a user whose roles
     * ARE recorded here is judged on those alone, so a Seller in company A who
     * was made HR-only in company B no longer counts as a Seller in B.
     *
     * Company membership itself is NOT checked here — every caller already
     * scopes to its own company (ofCompany(), or an explicit company_id) and
     * doubling that up would silently narrow those queries.
     *
     * @param string|array<int, string> $roles
     */
    public function scopeHoldingRole($query, int $companyId, string|array $roles)
    {
        $roles = (array) $roles;

        return $query->where(function ($q) use ($companyId, $roles) {
            $q->whereHas('companyRoles', fn ($r) => $r
                    ->where('company_id', $companyId)
                    ->whereIn('role', $roles))
                ->orWhere(fn ($legacy) => $legacy
                    ->whereIn('role_type', $roles)
                    ->whereDoesntHave('companyRoles', fn ($r) => $r->where('company_id', $companyId)));
        });
    }

    public function companyRoles(): HasMany
    {
        return $this->hasMany(UserCompanyRole::class);
    }

    /**
     * Every role this user holds IN THIS COMPANY, primary first.
     *
     * role_type is NOT consulted as a fallback when the company has no rows.
     * That column is not company-scoped — a user active in three companies
     * carries one value across all of them — so falling back to it would hand
     * someone their company-A role inside company B, which is the exact bug
     * this table exists to end. No rows means no role here, and permissions
     * (which are the real gate) are unaffected either way.
     *
     * @return array<int, string>
     */
    public function rolesIn(int $companyId): array
    {
        return $this->companyRoles
            ->where('company_id', $companyId)
            ->sortByDesc('is_primary')
            ->pluck('role')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Does this user hold any of $roles in this company?
     *
     * The replacement for `$user->role_type === 'seller'` checks. Prefer a
     * permission check where one exists — permissions are already unioned and
     * company-scoped — and use this only where the branch is genuinely about
     * who someone is rather than what they may do.
     *
     * @param string|array<int, string> $roles
     */
    public function hasRoleIn(int $companyId, string|array $roles): bool
    {
        return array_intersect((array) $roles, $this->rolesIn($companyId)) !== [];
    }

    /**
     * The headline role for this company — what users.role_type mirrors and
     * what the roster shows in its "Role" column.
     */
    public function primaryRoleIn(int $companyId): ?string
    {
        return $this->rolesIn($companyId)[0] ?? null;
    }

    public function assignedLeads(): HasMany
    {
        return $this->hasMany(Lead::class, 'assigned_to');
    }

    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_to');
    }

    public function timesheets(): HasMany
    {
        return $this->hasMany(Timesheet::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function sentMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'sender_id');
    }

    public function chatParticipants(): HasMany
    {
        return $this->hasMany(ChatParticipant::class);
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }
}
