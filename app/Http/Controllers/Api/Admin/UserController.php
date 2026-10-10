<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Admin\Concerns\ScopesToActiveCompany;
use App\Http\Resources\UserResource;
use App\Mail\UserWelcomeMail;
use App\Models\Company;
use App\Models\CompanyUserAssignment;
use App\Models\Deliverable;
use App\Models\Project;
use App\Models\SystemAuditLog;
use App\Models\Task;
use App\Models\Timesheet;
use App\Models\User;
use App\Models\UserCompanyPermission;
use App\Models\UserPermission;
use App\Rules\ValidPhoneNumber;
use App\Services\EventMailService;
use App\Services\ModuleCatalog;
use App\Services\RoleDefaultPermissions;
use App\Services\UserCompanyRoleService;
use App\Services\UserDeletionService;
use App\Support\CrossAccountEmail;
use App\Support\FrontendUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class UserController extends Controller
{
    use ScopesToActiveCompany;

    // Public because Api\User\UserManagementController::validRoles() reads it:
    // the Admin role is the Company Admin's deputy on the staff side and hands
    // out exactly this list, so the two must never drift apart.
    public const VALID_ROLES = [
        'seller', 'client', 'hr', 'finance', 'project_manager', 'production',
        'invoice_admin', 'invoice_manager', 'invoice_creator', 'invoice_viewer', 'payment_manager',
        // Role-based default permissions roles (Add/Edit User "Select Role") —
        // see App\Services\RoleDefaultPermissions::roleOptions() for the
        // current pickable list; the 5 invoice_* / payment_manager values
        // above are legacy and no longer offered as new picks but still
        // validate so existing users keep them.
        'developer', 'designer', 'qa', 'team_member', 'viewer', 'compliance', 'invoice_user', 'company_admin',
        'lead_manager',
        // Brand keeper, and the Company Admin's deputy for user management.
        // Deliberately absent from
        // Api\User\UserManagementController::VALID_ROLES so a DELEGATED User
        // Manager can never hand it out (see
        // RoleDefaultPermissions::COMPANY_ADMIN_ONLY_ROLES) — a deputy Admin
        // does, through validRoles() reading this list.
        'admin',
    ];

    private function admin()
    {
        return auth('admin')->user();
    }

    // role_type is purely descriptive (nothing checks it for access control —
    // real enforcement is the granular UserCompanyPermission rows). The admin
    // can now pick it explicitly (validated request field); this auto-derive
    // is only a fallback for callers that don't send one.
    private function roleTypeFromAssignments(array $assignments): string
    {
        // A resolved role beats guessing. withRoleDefaultPermissions() writes
        // the real per-company selection onto each assignment, so when the
        // wizard sent roles there is nothing to infer — take the first
        // assignment's primary and stop.
        foreach ($assignments as $assignment) {
            $primary = $assignment['roles'][0] ?? null;
            if (is_string($primary) && $primary !== '') {
                return $primary;
            }
        }

        // role_type is a strict DB enum (see VALID_ROLES) — catalog module keys
        // (e.g. 'project_management', 'sales') don't match it 1:1, so map to the
        // closest valid value instead of writing the raw key straight through.
        $moduleToRole = [
            'sales'              => 'seller',
            'client'             => 'client',
            'hr'                 => 'hr',
            'finance'            => 'finance',
            'project_management' => 'project_manager',
            'production'         => 'production',
            'invoice'            => 'invoice_manager',
        ];

        foreach ($assignments as $assignment) {
            foreach (($assignment['permissions'] ?? []) as $moduleKey => $permKeys) {
                if (!empty($permKeys) && isset($moduleToRole[$moduleKey])) {
                    return $moduleToRole[$moduleKey];
                }
            }
        }

        return 'seller';
    }

    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    // Count distinct users linked to any of this admin's companies via an
    // ACTIVE assignment — a user suspended in every company they belong to
    // must free up their seat (status='active' filter), and a user counted
    // once here even if active in several of this admin's companies at once
    // (distinct user_id) so multi-company membership never double-charges a seat.
    private function orgUserCount(): int
    {
        $companyIds = $this->companyIds();
        return CompanyUserAssignment::whereIn('company_id', $companyIds)
            ->where('status', 'active')
            ->distinct('user_id')
            ->count('user_id') + 1; // +1 for the admin themselves
    }

    private function orgUserLimit(): ?int
    {
        $admin = $this->admin()->load('package');

        // The seat count chosen (and paid for) at registration takes
        // precedence over the shared Package's default — falls back to the
        // Package limit only for admins registered before this column existed.
        return $admin->max_users_per_company ?? $admin->package?->max_users_per_company;
    }

    // Standardized seat-limit-reached message — used at every seat-gated
    // action (create, invite, link existing user, reactivate) so the admin
    // sees the same wording (and actual numbers) everywhere.
    private function seatLimitMessage(int $used, int $limit): string
    {
        return "Seat limit reached. You have used {$used} of {$limit} seats. Please upgrade your plan to add more users.";
    }

    // Check if this admin can manage a given user (user belongs to at least one of their companies).
    private function canManageUser(User $user): bool
    {
        $companyIds = $this->companyIds();
        return in_array($user->company_id, $companyIds)
            || CompanyUserAssignment::where('user_id', $user->id)
                   ->whereIn('company_id', $companyIds)
                   ->exists();
    }

    // Picks which of the admin's own companies a per-company action (remove,
    // toggle status, activity) applies to for this user — an explicit request
    // param when given (validated against the admin's own companies), else the
    // first assignment the admin actually owns.
    private function resolveCompanyId(User $user, ?int $requested = null): ?int
    {
        $companyIds = $this->companyIds();

        if ($requested !== null && in_array($requested, $companyIds)) {
            return $requested;
        }

        return CompanyUserAssignment::where('user_id', $user->id)
            ->whereIn('company_id', $companyIds)
            ->value('company_id') ?? (in_array($user->company_id, $companyIds) ? $user->company_id : null);
    }

    // When a user's primary company_id needs to move (their old primary was
    // just removed/suspended), prefer another company still owned by THIS
    // admin over one owned by a different admin entirely — otherwise the
    // account would be silently repointed to a company this admin can't see.
    private function pickReassignmentCompanyId(User $user, int $excludingCompanyId): ?int
    {
        $companyIds = $this->companyIds();
        $active = CompanyUserAssignment::where('user_id', $user->id)
            ->where('company_id', '!=', $excludingCompanyId)
            ->where('status', 'active')
            ->pluck('company_id');

        $ownedFirst = $active->first(fn ($cid) => in_array($cid, $companyIds));

        return $ownedFirst ?? $active->first();
    }

    // A user with no ACTIVE company assignment left anywhere is DORMANT: they
    // were removed from every company. destroy() unassigns and suspends
    // rather than deleting the row, because their projects/invoices/audit
    // history must survive — but that leaves the email occupying the
    // users.email unique index, so "create a user with that email again"
    // lands in store()/invite()'s existing-user branch. Without this, the
    // account was re-linked while still suspended and still carrying its old
    // name and password: the admin saw "user created" but the person could
    // never log in.
    //
    // Re-adding a dormant account IS a fresh onboarding, so the details the
    // admin just typed are applied and the account is reactivated.
    //
    // The guard matters: if ANY active assignment survives, this account
    // still belongs to another company, so it is only linked — never
    // renamed, never given a new password. Otherwise one Company Admin could
    // silently take over another's user just by "creating" a user with an
    // email they know.
    //
    // @return bool true if the account was dormant and has been re-onboarded.
    private function reonboardIfDormant(User $user, array $validated, bool $asInvite = false): bool
    {
        $activeElsewhere = CompanyUserAssignment::where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        if ($activeElsewhere) {
            return false;
        }

        $updates = [
            'name'      => $validated['name'],
            // Primary of the multi-role selection when one was sent; the roles
            // themselves are written by saveAssignments().
            'role_type' => UserCompanyRoleService::resolveFromPayload(
                [],
        
                $validated['roles'] ?? null,
                $validated['role_type'] ?? null
            )[0] ?? $user->role_type,
            'custom_role_label' => $validated['custom_role_label'] ?? null,
        ];

        if ($asInvite) {
            // Mirrors invite()'s own User::create() below — a re-invited
            // account starts over as a pending invite with a fresh token.
            $updates += [
                'password'             => Hash::make(Str::random(32)),
                'is_active'            => false,
                'status'               => 'invited',
                'invite_token'         => Str::random(64),
                'invite_expires_at'    => now()->addDays(7),
                'must_change_password' => true,
            ];
        } else {
            $updates += [
                'is_active'    => true,
                'status'       => 'active',
                'invite_token' => null,
            ];
            if (!empty($validated['password'])) {
                $updates['password'] = Hash::make($validated['password']);
            }
        }

        $user->update($updates);

        return true;
    }

    // Emails the invite link. Until now this URL was only ever returned in
    // the API response (UserResource::invite_url) for the Admin to copy and
    // forward by hand, so an invited user got no signal at all — unlike
    // store()'s brand-new-account path, which has always sent UserWelcomeMail.
    private function sendInviteEmail(User $user, int $companyId): void
    {
        $user = $user->fresh();
        if (!$user?->invite_token) return;

        EventMailService::userInvited(
            $user,
            FrontendUrl::to('invite/' . $user->invite_token),
            $companyId,
            $this->admin()->name ?? 'Your administrator',
        );
    }

    // "Company Assignment" — an account that already has credentials has been
    // given access to another company. Only for a user who is NOT being
    // re-onboarded/invited: those two already get their own email, and
    // stacking a second one for the same action is noise.
    private function sendCompanyAssignmentEmails(User $user, array $assignments): void
    {
        foreach ($assignments as $a) {
            EventMailService::companyAssigned($user, (int) $a['company_id'], $this->admin()->name ?? 'Your administrator');
        }
    }

    private function logAudit(Request $request, ?int $companyId, string $action, string $entityType, ?int $entityId, array $old = [], array $new = []): void
    {
        SystemAuditLog::create([
            'company_id'  => $companyId,
            'user_id'     => null, // SystemAuditLog.user_id FKs to `users`; Company Admin actor isn't a User row
            'action'      => $action,
            'module_key'  => 'users',
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'old_values'  => $old ?: null,
            'new_values'  => $new ?: null,
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);
    }

    // ── List users ────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        // Company-Wise Dashboard Filtering narrows WHICH USERS show up in
        // this list to the active company — but each shown user's own
        // companyAssignments/userCompanyPermissions/primary-company display
        // must stay bound to every company this admin owns (orgCompanyIds),
        // not just the active one, or a genuinely multi-company user would
        // wrongly appear to belong to only whichever company is active.
        $activeCompanyIds = $this->activeCompanyIds();
        $orgCompanyIds = $this->companyIds();

        // Include users assigned to any of the admin's companies (not just primary company)
        $assignedUserIds = CompanyUserAssignment::whereIn('company_id', $activeCompanyIds)
            ->pluck('user_id');

        // Unassign Company from User — removing someone's LAST company
        // deliberately leaves users.company_id pointing at that now-stale
        // company (destroy() above), with no CompanyUserAssignment row left
        // to back it, precisely so CheckCompanyModule denies them access.
        // But that also means the query above alone can never find them
        // again — with zero assignment rows, they can't match any company
        // filter, so they'd vanish from this list entirely with no way for
        // the admin to reassign them a company. Fall back to that stale
        // company_id (still validated against this admin's own companies,
        // so a foreign admin's orphan can never leak in) for exactly the
        // zero-assignment case.
        // Client-portal logins (role_type='client', created by
        // ClientController::createOrUpdatePortalUser()) never get a
        // CompanyUserAssignment row either — that mechanism is staff-only —
        // so without this exclusion every client login would also match
        // "zero assignments" and leak into this staff list.
        // Excludes 'suspended' — that's exactly the marker destroy() now
        // sets on a zero-assignment user with nothing left to remove them
        // from (see destroy()'s is_active/status rollup); without this
        // exclusion a "removed" user with no assignment row would keep
        // matching this exact query and reappear right after being removed.
        // A genuinely brand-new never-assigned user is never 'suspended' by
        // default (store() creates 'active', invite() creates 'invited'),
        // so this never hides someone who still needs a first assignment.
        $orphanedUserIds = User::whereIn('company_id', $activeCompanyIds)
            ->where('role_type', '!=', 'client')
            ->where('status', '!=', 'suspended')
            ->whereDoesntHave('companyAssignments')
            ->pluck('id');

        $userIds = $assignedUserIds->merge($orphanedUserIds)
            ->unique()
            ->values()
            ->toArray();

        // Fully-unassigned users (created with zero company_assignments —
        // see store()'s $defaultCompId fallback) have no CompanyUserAssignment
        // row at all, so they'd never match the query above no matter which
        // company is active — permanently invisible, with no way for the
        // admin to ever find them to assign a company. Always surfaced
        // regardless of the active-company filter since they aren't
        // structurally tied to any one company yet.
        $unassignedUserIds = User::whereIn('company_id', $orgCompanyIds)
            ->where('role_type', '!=', 'client')
            ->where('status', '!=', 'suspended')
            ->whereDoesntHave('companyAssignments')
            ->pluck('id')
            ->toArray();

        $userIds = array_values(array_unique(array_merge($userIds, $unassignedUserIds)));

        $query = User::with([
                // Scoped to the admin's own companies only — a user who also
                // belongs to a company outside this admin's org must never
                // leak that other membership/permissions in this response.
                'companyAssignments' => fn ($q) => $q->whereIn('company_id', $orgCompanyIds)->with('company:id,name,is_active,suspended_at'),
                'userCompanyPermissions' => fn ($q) => $q->whereIn('company_id', $orgCompanyIds),
                'companyRoles' => fn ($q) => $q->whereIn('company_id', $orgCompanyIds),
                'company:id,name',
                'createdBy:id,name',
            ])
            ->whereIn('id', $userIds);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $users = $query->latest()->get();

        // Never surface a "primary company" the admin doesn't own.
        $users->each(function (User $u) use ($orgCompanyIds) {
            if ($u->company_id && !in_array($u->company_id, $orgCompanyIds)) {
                $u->setRelation('company', null);
            }
        });

        return ApiResponse::success([
            'users' => UserResource::collection($users),
            'count' => $users->count(),
            'used'  => $this->orgUserCount(),
            'limit' => $this->orgUserLimit(),
        ]);
    }

    // ── Companies + modules list for the add-user form ────────────────────────

    public function companyOptions(): JsonResponse
    {
        // operationalCompanyIds(), not companyIds() — this is exactly the
        // "which company can a new record be created under" picker
        // ScopesToActiveCompany::operationalCompanyIds() documents itself
        // for: a suspended (or Super-Admin-deactivated) company must not be
        // assignable to a brand-new staff member here, even though its own
        // existing staff/data stay visible elsewhere until the Admin
        // reactivates it or offboards them.
        $companies = Company::whereIn('id', $this->operationalCompanyIds())
            ->select('id', 'name')
            ->orderBy('id')
            ->get()
            ->map(function (Company $c) {
                return [
                    'id'      => $c->id,
                    'name'    => $c->name,
                    'modules' => $c->modules()
                        ->where('is_enabled', true)
                        ->pluck('module_key')
                        ->toArray(),
                ];
            });

        return ApiResponse::success($companies);
    }

    // ── Check if email already exists ─────────────────────────────────────────

    public function checkEmail(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);

        // Mirrors the block store()/invite() already enforce — surfaced here
        // too so the live "already exists" hint doesn't stay silent for an
        // email that would actually be rejected on submit.
        if (CrossAccountEmail::existsAsAdmin($validated['email'])) {
            return ApiResponse::success(['exists' => true, 'is_admin' => true, 'name' => null, 'status' => null]);
        }

        // withTrashed(): a soft-deleted user (removed from their last
        // company — see destroy()) still occupies this email in the
        // database's unique index. Without withTrashed() here this reports
        // "available" for an email store()/invite() will then reject with a
        // raw duplicate-key SQL error instead of the intended "link/restore"
        // flow.
        $existing  = User::withTrashed()->where('email', $validated['email'])->first();

        return ApiResponse::success([
            'exists'   => (bool) $existing,
            'is_admin' => false,
            'name'     => $existing?->name,
            'status'   => $existing?->status,
        ]);
    }

    // ── Create user (direct, admin sets password) ─────────────────────────────
    //
    // Rule 1-4: Check if the email already exists.
    //   - If exists + already in requested company → 422 error.
    //   - If exists + not in company → just add the assignment (no new user created).
    //   - If not exists → create user and add assignment.

    public function store(Request $request): JsonResponse
    {
        $companyIds = $this->operationalCompanyIds();

        $validated = $request->validate([
            'name'                              => ['required', 'string', 'max:150'],
            'email'                             => ['required', 'email'],
            'password'                          => ['nullable', 'string', 'min:8'],
            'role_type'                         => ['nullable', 'string', 'in:' . implode(',', self::VALID_ROLES)],
            // Multi-role. 'role_type' above stays accepted and keeps being
            // written as the PRIMARY role, so callers that never heard of
            // 'roles' are unaffected.
            'roles'                             => ['nullable', 'array'],
            'roles.*'                           => ['string', 'in:' . implode(',', self::VALID_ROLES)],
            // Display-only override for a "Custom Role" (e.g. "Marketing
            // Lead") — role_type above still carries the real permission/
            // behavior bucket this custom role is based on; this is purely
            // what gets shown instead of the generic role_type label.
            'custom_role_label'                 => ['nullable', 'string', 'max:100'],
            'company_assignments'               => ['nullable', 'array'],
            'company_assignments.*.company_id'   => ['required', 'integer', 'in:' . implode(',', $companyIds)],
            'company_assignments.*.permissions'  => ['nullable', 'array'],
            'company_assignments.*.data_scopes'  => ['nullable', 'array'],
            // Per-company roles, for the case where the same person is a
            // Seller in one company and a Project Manager in another.
            'company_assignments.*.roles'        => ['nullable', 'array'],
            'company_assignments.*.roles.*'      => ['string', 'in:' . implode(',', self::VALID_ROLES)],
        ]);

        $assignments   = $this->withRoleDefaultPermissions(
            $validated['company_assignments'] ?? [],
            $validated['roles'] ?? $validated['role_type'] ?? null
        );

        // Keep the legacy column in step with the selection. Without this a
        // caller sending both 'roles' and a stale 'role_type' would store a
        // headline role that is not even in the user's role set.
        if (!empty($validated['roles'][0])) {
            $validated['role_type'] = $validated['roles'][0];
        }

        $defaultCompId = $assignments[0]['company_id'] ?? $companyIds[0] ?? null;
        if (!$defaultCompId) return ApiResponse::error('No company found', 404);

        // withTrashed(): a user soft-deleted after being removed from their
        // last company (see destroy()) still holds this email in the
        // database's unique index — without this, $existingUser comes back
        // null and the User::create() below throws a raw duplicate-key SQL
        // error instead of restoring and linking them like any other
        // existing user.
        $existingUser = User::withTrashed()->where('email', $validated['email'])->first();

        if ($existingUser) {
            if ($existingUser->trashed()) {
                $existingUser->restore();
            }

            // Rule 4: Error if user is already in any of the requested companies.
            foreach ($assignments as $a) {
                $alreadyLinked = CompanyUserAssignment::where('user_id', $existingUser->id)
                    ->where('company_id', $a['company_id'])
                    ->exists();
                if ($alreadyLinked) {
                    return ApiResponse::error('This user is already added to this company.', 422);
                }
            }

            // Rule 11: Check seat limit before adding existing user to a new company.
            $limit = $this->orgUserLimit();
            $used  = $this->orgUserCount();
            if ($limit !== null && $used >= $limit) {
                return ApiResponse::error($this->seatLimitMessage($used, $limit), 422);
            }

            // Removed from every company (or soft-deleted) → this is a real
            // re-onboarding, so reactivate and apply the submitted details.
            // Still active elsewhere → link only, leave their account alone.
            $reonboarded = $this->reonboardIfDormant($existingUser, $validated);

            // Rule 2: Link existing user to the new company (no new User row created).
            $this->saveAssignments($existingUser, $assignments);
            foreach ($assignments as $a) {
                $this->logAudit(
                    $request,
                    $a['company_id'],
                    $reonboarded ? 'user.reactivated' : 'user.linked_to_company',
                    'User',
                    $existingUser->id,
                    [],
                    ['email' => $existingUser->email]
                );
            }

            $this->sendCompanyAssignmentEmails($existingUser, $assignments);

            return ApiResponse::success(
                new UserResource($existingUser->fresh()->load(['companyAssignments.company:id,name,is_active,suspended_at', 'userCompanyPermissions', 'companyRoles', 'company:id,name'])),
                $reonboarded
                    ? 'User created'
                    : 'This email already exists in the system — that account has been linked to your company (its name and password are unchanged).',
                200
            );
        }

        // An email that's already a Company Admin account must never also
        // become a User row — the two guards would otherwise silently race
        // at login time depending on which table matches first.
        if (CrossAccountEmail::existsAsAdmin($validated['email'])) {
            return ApiResponse::error('This email is already registered as a Company Admin account.', 422);
        }

        // Rule 3: New user — password required.
        if (empty($validated['password'])) {
            return ApiResponse::error('Password is required when adding a new user.', 422);
        }

        // Rule 11: Seat limit check for new user creation.
        $limit = $this->orgUserLimit();
        $used  = $this->orgUserCount();
        if ($limit !== null && $used >= $limit) {
            return ApiResponse::error($this->seatLimitMessage($used, $limit), 422);
        }

        $user = User::create([
            'company_id'        => $defaultCompId,
            'name'              => $validated['name'],
            'email'             => $validated['email'],
            'password'          => $validated['password'],
            'role_type'         => $validated['role_type'] ?? $this->roleTypeFromAssignments($assignments),
            'custom_role_label' => $validated['custom_role_label'] ?? null,
            'is_active'         => true,
            'status'            => 'active',
        ]);

        $this->saveAssignments($user, $assignments);
        foreach ($assignments as $a) {
            $this->logAudit($request, $a['company_id'], 'user.created', 'User', $user->id, [], ['name' => $user->name, 'email' => $user->email]);
        }

        // Login details only ever go out for a brand-new account created with
        // a real password here — the "link existing user to another company"
        // branch above never reaches this, since that person already has
        // credentials. Non-blocking: a mail failure must never fail user
        // creation itself (same pattern as ClientController::store()'s
        // ClientPortalWelcomeMail).
        try {
            Mail::to($user->email)->send(new UserWelcomeMail($user, Company::find($defaultCompId), $validated['password']));
        } catch (\Throwable) {
            // Don't fail user creation if mail fails
        }

        return ApiResponse::success(
            new UserResource($user->load(['companyAssignments.company:id,name,is_active,suspended_at', 'userCompanyPermissions', 'companyRoles', 'company:id,name'])),
            'User created',
            201
        );
    }

    // ── Invite user (token, user sets own password) ───────────────────────────

    public function invite(Request $request): JsonResponse
    {
        $companyIds = $this->operationalCompanyIds();

        $validated = $request->validate([
            'name'                              => ['required', 'string', 'max:150'],
            'email'                             => ['required', 'email'],
            'role_type'                         => ['nullable', 'string', 'in:' . implode(',', self::VALID_ROLES)],
            'roles'                             => ['nullable', 'array'],
            'roles.*'                           => ['string', 'in:' . implode(',', self::VALID_ROLES)],
            'custom_role_label'                 => ['nullable', 'string', 'max:100'],
            'company_assignments'               => ['nullable', 'array'],
            'company_assignments.*.company_id'   => ['required', 'integer', 'in:' . implode(',', $companyIds)],
            'company_assignments.*.permissions'  => ['nullable', 'array'],
            'company_assignments.*.data_scopes'  => ['nullable', 'array'],
            'company_assignments.*.roles'        => ['nullable', 'array'],
            'company_assignments.*.roles.*'      => ['string', 'in:' . implode(',', self::VALID_ROLES)],
        ]);

        $assignments   = $this->withRoleDefaultPermissions(
            $validated['company_assignments'] ?? [],
            $validated['roles'] ?? $validated['role_type'] ?? null
        );

        if (!empty($validated['roles'][0])) {
            $validated['role_type'] = $validated['roles'][0];
        }

        $defaultCompId = $assignments[0]['company_id'] ?? $companyIds[0] ?? null;
        if (!$defaultCompId) return ApiResponse::error('No company found', 404);

        // withTrashed() — see the matching comment in store(); same reason.
        $existingUser = User::withTrashed()->where('email', $validated['email'])->first();

        if ($existingUser) {
            if ($existingUser->trashed()) {
                $existingUser->restore();
            }

            // Rule 4: Already in this company.
            foreach ($assignments as $a) {
                $alreadyLinked = CompanyUserAssignment::where('user_id', $existingUser->id)
                    ->where('company_id', $a['company_id'])
                    ->exists();
                if ($alreadyLinked) {
                    return ApiResponse::error('This user is already added to this company.', 422);
                }
            }

            $limit = $this->orgUserLimit();
            $used  = $this->orgUserCount();
            if ($limit !== null && $used >= $limit) {
                return ApiResponse::error($this->seatLimitMessage($used, $limit), 422);
            }

            // Same rule as store(): removed from everywhere → re-invite it
            // properly (fresh token, pending status); still active elsewhere
            // → link only and leave their account untouched.
            $reonboarded = $this->reonboardIfDormant($existingUser, $validated, asInvite: true);

            // Rule 2: Link existing user — no invite needed.
            $this->saveAssignments($existingUser, $assignments);
            foreach ($assignments as $a) {
                $this->logAudit(
                    $request,
                    $a['company_id'],
                    $reonboarded ? 'user.reinvited' : 'user.linked_to_company',
                    'User',
                    $existingUser->id,
                    [],
                    ['email' => $existingUser->email]
                );
            }

            // A re-invited dormant account gets the invite link; one that is
            // still active elsewhere just gets "you now have access to X".
            if ($reonboarded) {
                $this->sendInviteEmail($existingUser, (int) $defaultCompId);
            } else {
                $this->sendCompanyAssignmentEmails($existingUser, $assignments);
            }

            return ApiResponse::success(
                new UserResource($existingUser->fresh()->load(['companyAssignments.company:id,name,is_active,suspended_at', 'userCompanyPermissions', 'companyRoles', 'company:id,name'])),
                $reonboarded
                    ? 'Invite created'
                    : 'This email already exists in the system — that account has been linked to your company (its name and password are unchanged).',
                200
            );
        }

        // An email that's already a Company Admin account must never also
        // become a User row — see the matching check in store().
        if (CrossAccountEmail::existsAsAdmin($validated['email'])) {
            return ApiResponse::error('This email is already registered as a Company Admin account.', 422);
        }

        $limit = $this->orgUserLimit();
        $used  = $this->orgUserCount();
        if ($limit !== null && $used >= $limit) {
            return ApiResponse::error($this->seatLimitMessage($used, $limit), 422);
        }

        $user = User::create([
            'company_id'           => $defaultCompId,
            'name'                 => $validated['name'],
            'email'                => $validated['email'],
            'password'             => Hash::make(Str::random(32)),
            'role_type'            => $validated['role_type'] ?? $this->roleTypeFromAssignments($assignments),
            'custom_role_label'    => $validated['custom_role_label'] ?? null,
            'is_active'            => false,
            'status'               => 'invited',
            'invite_token'         => Str::random(64),
            'invite_expires_at'    => now()->addDays(7),
            'must_change_password' => true,
        ]);

        $this->saveAssignments($user, $assignments);
        foreach ($assignments as $a) {
            $this->logAudit($request, $a['company_id'], 'user.invited', 'User', $user->id, [], ['name' => $user->name, 'email' => $user->email]);
        }

        $this->sendInviteEmail($user, (int) $defaultCompId);

        return ApiResponse::success(
            new UserResource($user->load(['companyAssignments.company:id,name,is_active,suspended_at', 'userCompanyPermissions', 'companyRoles', 'company:id,name'])),
            'Invite created',
            201
        );
    }

    // ── Resend invite ─────────────────────────────────────────────────────────

    public function resendInvite(Request $request, User $user): JsonResponse
    {
        if (!$this->canManageUser($user)) {
            return ApiResponse::error('Not found', 404);
        }
        if ($user->status !== 'invited') {
            return ApiResponse::error('User has already accepted the invite', 422);
        }

        $user->update([
            'invite_token'      => Str::random(64),
            'invite_expires_at' => now()->addDays(7),
        ]);

        $this->logAudit($request, $this->resolveCompanyId($user), 'invite.sent', 'User', $user->id);

        $this->sendInviteEmail($user, (int) $this->resolveCompanyId($user));

        return ApiResponse::success(
            new UserResource($user->load(['companyAssignments.company:id,name,is_active,suspended_at', 'userCompanyPermissions', 'companyRoles', 'company:id,name'])),
            'Invite link refreshed'
        );
    }

    // ── Toggle active/suspended ───────────────────────────────────────────────

    public function toggleStatus(Request $request, User $user): JsonResponse
    {
        if (!$this->canManageUser($user)) {
            return ApiResponse::error('Not found', 404);
        }
        if ($user->status === 'invited') {
            return ApiResponse::error('Cannot suspend a pending invite. Remove the user instead.', 422);
        }

        $validated = $request->validate([
            'status'     => ['required', 'in:active,suspended'],
            'company_id' => ['nullable', 'integer'],
        ]);

        $companyId = $this->resolveCompanyId($user, $validated['company_id'] ?? null);
        if (!$companyId) {
            return ApiResponse::error('No company found for this user', 404);
        }

        // Reactivating (suspended → active): only a genuine seat concern if
        // this user isn't already counted via another active assignment
        // under this admin — reactivating a second company for someone
        // already active elsewhere doesn't add a new seat (orgUserCount()
        // dedupes by user_id).
        if ($validated['status'] === 'active') {
            $alreadyCountedElsewhere = CompanyUserAssignment::where('user_id', $user->id)
                ->where('company_id', '!=', $companyId)
                ->where('status', 'active')
                ->exists();
            if (!$alreadyCountedElsewhere) {
                $limit = $this->orgUserLimit();
                $used  = $this->orgUserCount();
                if ($limit !== null && $used >= $limit) {
                    return ApiResponse::error($this->seatLimitMessage($used, $limit), 422);
                }
            }
        }

        // Scoped to THIS company only — a user suspended by one admin must
        // keep working normally in any other company they belong to.
        CompanyUserAssignment::where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->update(['status' => $validated['status']]);

        // Global users.is_active/status is a rollup: active if any assignment
        // is still active, suspended only when every assignment is suspended.
        // Permission checks throughout the app key off users.company_id (the
        // "primary" company) — if we just suspended that one and another
        // active company remains, repoint it so the account stays usable there.
        $anyActive = CompanyUserAssignment::where('user_id', $user->id)->where('status', 'active')->exists();

        $update = [
            'is_active' => $anyActive,
            'status'    => $anyActive ? 'active' : 'suspended',
        ];
        if ($validated['status'] === 'suspended' && $user->company_id === $companyId) {
            $reassignTo = $this->pickReassignmentCompanyId($user, $companyId);
            if ($reassignTo) {
                $update['company_id'] = $reassignTo;
            }
        }
        $user->update($update);

        $this->logAudit(
            $request, $companyId,
            $validated['status'] === 'active' ? 'user.activated' : 'user.deactivated',
            'User', $user->id, [], ['status' => $validated['status']]
        );

        // Scoped to this admin's own companies, exactly like index()/show()
        // already do — this response replaces the row in the Users list, and
        // the list's per-company suspend/activate picker is built straight
        // from company_assignments. Unscoped, a multi-company user's row
        // would come back carrying a company belonging to a DIFFERENT admin
        // and offer it as something to suspend.
        $companyIds = $this->companyIds();
        $user->load([
            'companyAssignments' => fn ($q) => $q->whereIn('company_id', $companyIds)->with('company:id,name,is_active,suspended_at'),
            'userCompanyPermissions' => fn ($q) => $q->whereIn('company_id', $companyIds),
            'companyRoles' => fn ($q) => $q->whereIn('company_id', $companyIds),
            'company:id,name',
        ]);

        return ApiResponse::success(new UserResource($user), 'Status updated');
    }

    // ── Single user ───────────────────────────────────────────────────────────

    public function show(User $user): JsonResponse
    {
        if (!$this->canManageUser($user)) {
            return ApiResponse::error('Not found', 404);
        }

        $companyIds = $this->companyIds();
        $user->load([
            'companyAssignments' => fn ($q) => $q->whereIn('company_id', $companyIds)->with('company:id,name,is_active,suspended_at'),
            'userCompanyPermissions' => fn ($q) => $q->whereIn('company_id', $companyIds),
            'companyRoles' => fn ($q) => $q->whereIn('company_id', $companyIds),
            'company:id,name',
            'createdBy:id,name',
        ]);
        if ($user->company_id && !in_array($user->company_id, $companyIds)) {
            $user->setRelation('company', null);
        }

        return ApiResponse::success(new UserResource($user));
    }

    // ── Update basic info ─────────────────────────────────────────────────────

    public function update(Request $request, User $user): JsonResponse
    {
        if (!$this->canManageUser($user)) {
            return ApiResponse::error('Not found', 404);
        }

        $validated = $request->validate([
            'name'      => ['sometimes', 'string', 'max:150'],
            'email'     => [
                'sometimes', 'email', 'unique:users,email,' . $user->id,
                function ($attribute, $value, $fail) {
                    if (CrossAccountEmail::existsAsAdmin($value)) {
                        $fail('This email is already registered as a Company Admin account.');
                    }
                },
            ],
            'password'          => ['nullable', 'string', 'min:8'],
            'role_type'         => ['sometimes', 'string', 'in:' . implode(',', self::VALID_ROLES)],
            'roles'             => ['sometimes', 'array'],
            'roles.*'           => ['string', 'in:' . implode(',', self::VALID_ROLES)],
            'custom_role_label' => ['nullable', 'string', 'max:100'],
            'phone'             => ['nullable', 'string', 'max:30', new ValidPhoneNumber],
            'is_active'         => ['sometimes', 'boolean'],
        ]);

        if (empty($validated['password'])) unset($validated['password']);

        // Roles live in user_company_roles, never on the users row — lift them
        // out before the mass-assign below.
        $roles = UserCompanyRoleService::resolveFromPayload(
            [],
            $validated['roles'] ?? null,
            $validated['role_type'] ?? null
        );
        unset($validated['roles']);

        if ($roles !== []) {
            $validated['role_type'] = $roles[0];
        }

        $oldRoleType  = $user->role_type;
        $oldRoleLabel = $user->custom_role_label ?: $oldRoleType;
        $user->update($validated);

        $companyId = $this->resolveCompanyId($user);

        UserCompanyRoleService::sync($user, (int) $companyId, $roles);
        $loggable  = collect($validated)->except('password')->toArray();
        if (isset($validated['role_type']) && $validated['role_type'] !== $oldRoleType) {
            $this->logAudit($request, $companyId, 'user.role_changed', 'User', $user->id, ['role_type' => $oldRoleType], ['role_type' => $validated['role_type']]);

            // "Role Assignment" — the user's modules and permissions move with
            // their role, so they're told rather than left to discover it.
            if ($companyId) {
                EventMailService::roleAssigned(
                    $user->fresh(),
                    (int) $companyId,
                    ucfirst(str_replace('_', ' ', (string) $oldRoleLabel)),
                    ucfirst(str_replace('_', ' ', (string) ($user->custom_role_label ?: $validated['role_type']))),
                    $this->admin()->name ?? 'Your administrator',
                );
            }
        } else {
            $this->logAudit($request, $companyId, 'user.updated', 'User', $user->id, [], $loggable);
        }

        return ApiResponse::success(
            new UserResource($user->load(['companyAssignments.company:id,name,is_active,suspended_at', 'userCompanyPermissions', 'companyRoles', 'company:id,name'])),
            'User updated'
        );
    }

    // ── Delete user ───────────────────────────────────────────────────────────

    public function destroy(Request $request, User $user): JsonResponse
    {
        if (!$this->canManageUser($user)) {
            return ApiResponse::error('Not found', 404);
        }

        $validated = $request->validate(['company_id' => ['nullable', 'integer']]);
        $companyId = $this->resolveCompanyId($user, $validated['company_id'] ?? null);
        if (!$companyId) {
            return ApiResponse::error('No company found for this user', 404);
        }

        // Remove ONLY this company's membership — a user who belongs to
        // another company as well must keep that access. FK cascade on
        // company_user_id already clears matching permission rows; also
        // clear any legacy rows keyed by the raw (user_id, company_id) pair.
        CompanyUserAssignment::where('user_id', $user->id)->where('company_id', $companyId)->delete();
        UserCompanyPermission::where('user_id', $user->id)->where('company_id', $companyId)->delete();

        $remaining = CompanyUserAssignment::where('user_id', $user->id)->first();

        $update = [];

        if (!$remaining) {
            // No company left at all — keep the account (Unassign Company
            // from User: never delete the user/project/invoice data, just
            // block company-specific access until they're assigned again).
            // company_id is NOT NULL with no nullable migration, so it's left
            // pointing at the company they were just removed from — that's
            // fine, since it's now a stale value with no backing active
            // CompanyUserAssignment row, and CheckCompanyModule verifies
            // exactly that before trusting it for module access.
        } elseif ($user->company_id === $companyId) {
            // Their "primary" company_id — which every permission check in
            // this app reads — just lost its membership row. Repoint it to a
            // company they still belong to (preferring one this admin also
            // owns), or the account is left silently scoped to a company
            // they were just removed from.
            $reassignTo = $this->pickReassignmentCompanyId($user, $companyId) ?? $remaining->company_id;
            $update['company_id'] = $reassignTo;
        }

        // Same is_active/status rollup toggleStatus() already applies after
        // suspending an assignment — needed here too, because a user created
        // before CompanyUserAssignment existed (or seeded directly) has NO
        // assignment row to delete above, so the delete on line 714 is a
        // silent no-op: nothing about them changes, and index()'s
        // zero-assignment "orphan"/"unassigned" fallback queries re-surface
        // them completely unchanged on the very next list load — Remove
        // looked like it did nothing because, for this user, it truly did
        // nothing. Recomputing status here is the only way "Remove" leaves
        // any real trace for that case. A pending invite is left alone —
        // toggleStatus() already refuses to touch 'invited' for the same
        // reason (there's no active session to suspend yet).
        $anyActive = CompanyUserAssignment::where('user_id', $user->id)->where('status', 'active')->exists();
        if (!$anyActive && $user->status !== 'invited') {
            $update['is_active'] = false;
            $update['status'] = 'suspended';
        }

        if ($update) {
            $user->update($update);
        }

        $this->logAudit($request, $companyId, 'user.removed_from_company', 'User', $user->id, [], ['email' => $user->email]);

        return ApiResponse::success(null, 'User removed');
    }

    // ── Impact summary / reassignment / permanent delete ──────────────────────
    //
    // destroy() above only unassigns. These three back the Impact Summary
    // modal: show everything the person is holding, let the admin hand it
    // over, and only then permanently delete the account.

    // GET /admin/users/{user}/company-workload — how much this person is
    // holding in each of the admin's companies they belong to. Backs the
    // "delete them from which company?" picker a multi-company user gets
    // first, so that choice isn't made blind.
    public function companyWorkload(User $user): JsonResponse
    {
        if (!$this->canManageUser($user)) {
            return ApiResponse::error('Not found', 404);
        }

        return ApiResponse::success(UserDeletionService::companyWorkload($user, $this->companyIds()));
    }

    // GET /admin/users/{user}/delete-impact?company_id=
    public function deleteImpact(Request $request, User $user): JsonResponse
    {
        if (!$this->canManageUser($user)) {
            return ApiResponse::error('Not found', 404);
        }

        $companyId = $this->resolveCompanyId($user, $request->integer('company_id') ?: null);
        if (!$companyId) {
            return ApiResponse::error('No company found for this user', 404);
        }

        return ApiResponse::success(UserDeletionService::impact($user, $companyId));
    }

    // POST /admin/users/{user}/reassign — hand this user's work in one company
    // over to other people, one receiving user per bucket. Buckets left out
    // are untouched, so the modal can reassign only what the admin chose.
    public function reassignWorkload(Request $request, User $user): JsonResponse
    {
        if (!$this->canManageUser($user)) {
            return ApiResponse::error('Not found', 404);
        }

        $companyId = $this->resolveCompanyId($user, $request->integer('company_id') ?: null);
        if (!$companyId) {
            return ApiResponse::error('No company found for this user', 404);
        }

        $rules = ['company_id' => ['nullable', 'integer']];
        foreach (UserDeletionService::REASSIGNABLE as $bucket) {
            $rules[$bucket] = ['nullable', 'integer'];
        }
        $validated = $request->validate($rules);

        $targets = array_filter(
            array_intersect_key($validated, array_flip(UserDeletionService::REASSIGNABLE)),
            fn ($id) => $id !== null,
        );

        if (!$targets) {
            return ApiResponse::error('Pick who should take over at least one of these.', 422);
        }

        // Every receiving user must be an active member of the same company,
        // and never the person being removed.
        $allowed = collect(UserDeletionService::reassignCandidates($user, $companyId))->pluck('id');
        foreach ($targets as $bucket => $toUserId) {
            if (!$allowed->contains($toUserId)) {
                return ApiResponse::error("The user chosen for {$bucket} is not an active member of this company.", 422);
            }
        }

        $moved = UserDeletionService::reassign($user, $companyId, $targets);

        $this->logAudit($request, $companyId, 'user.workload_reassigned', 'User', $user->id, [], $moved);

        return ApiResponse::success(
            ['moved' => $moved, 'impact' => UserDeletionService::impact($user, $companyId)],
            'Records reassigned',
        );
    }

    // DELETE /admin/users/{user}/permanent — irreversible. Refused outright
    // when the account also belongs to another organisation, and refused with
    // the outstanding list when the person still holds active work, unless the
    // admin explicitly passes force=true after being shown it.
    public function destroyPermanently(Request $request, User $user): JsonResponse
    {
        if (!$this->canManageUser($user)) {
            return ApiResponse::error('Not found', 404);
        }

        $validated = $request->validate([
            'company_id' => ['nullable', 'integer'],
            'force'      => ['nullable', 'boolean'],
        ]);

        $companyId = $this->resolveCompanyId($user, $validated['company_id'] ?? null);
        if (!$companyId) {
            return ApiResponse::error('No company found for this user', 404);
        }

        $impact = UserDeletionService::impact($user, $companyId);

        if (!$impact['can_delete']) {
            return ApiResponse::error($impact['block_reason'], 422);
        }

        if ($impact['blockers'] && !($validated['force'] ?? false)) {
            return ApiResponse::error(
                'This user still holds active work. Reassign it first, or confirm you want to delete anyway.',
                422,
            );
        }

        $email = $user->email;
        UserDeletionService::purge($user, $companyId);

        $this->logAudit($request, $companyId, 'user.permanently_deleted', 'User', $user->id, [], ['email' => $email]);

        return ApiResponse::success(null, 'User account permanently deleted');
    }

    // ── Reset password (admin-triggered, shows the new password once) ─────────

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        if (!$this->canManageUser($user)) {
            return ApiResponse::error('Not found', 404);
        }
        if ($user->status === 'invited') {
            return ApiResponse::error('User has not accepted their invite yet — resend the invite instead.', 422);
        }

        $plain = Str::random(12);
        $user->update([
            'password'              => Hash::make($plain),
            'must_change_password'  => true,
        ]);

        $this->logAudit($request, $this->resolveCompanyId($user), 'user.password_reset_sent', 'User', $user->id);

        return ApiResponse::success(['password' => $plain], 'Password reset — share this with the user once, it will not be shown again');
    }

    // ── Per-user activity / workload (Project Management), read-only ──────────

    public function activity(Request $request, User $user): JsonResponse
    {
        if (!$this->canManageUser($user)) {
            return ApiResponse::error('Not found', 404);
        }

        $companyId = $this->resolveCompanyId($user, $request->integer('company_id') ?: null);
        if (!$companyId) {
            return ApiResponse::error('No company found for this user', 404);
        }

        $pmModuleKeys = ['projects', 'tasks', 'timesheets', 'production', 'revisions', 'deliverables', 'team_resources', 'file_storage'];
        $companyModules = Company::find($companyId)?->modules()->where('is_enabled', true)->pluck('module_key')->toArray() ?? [];
        $pmActive = !empty(array_intersect($pmModuleKeys, $companyModules));

        $result = ['project_management_active' => $pmActive];

        if ($pmActive) {
            $result['assigned_tasks'] = Task::where('assigned_to', $user->id)
                ->whereHas('project', fn ($q) => $q->where('company_id', $companyId))
                ->select('id', 'title', 'status', 'project_id')
                ->limit(50)->get();

            $result['managed_projects'] = Project::where('company_id', $companyId)
                ->where('project_manager_id', $user->id)
                ->select('id', 'name', 'status')
                ->get();

            $result['member_projects'] = Project::where('company_id', $companyId)
                ->whereHas('teamMembers', fn ($q) => $q->where('user_id', $user->id))
                ->select('id', 'name', 'status')
                ->get();

            $result['timesheets'] = Timesheet::where('user_id', $user->id)
                ->whereHas('task.project', fn ($q) => $q->where('company_id', $companyId))
                ->select('id', 'task_id', 'hours_logged', 'status', 'log_date')
                ->limit(50)->get();

            $result['deliverables'] = Deliverable::where('uploaded_by', $user->id)
                ->whereHas('project', fn ($q) => $q->where('company_id', $companyId))
                ->select('id', 'title', 'status', 'project_id')
                ->limit(50)->get();
        }

        $result['audit_logs'] = SystemAuditLog::where('user_id', $user->id)
            ->orWhere(function ($q) use ($user, $companyId) {
                // Company Admin actions about this user are logged with user_id=null
                // (Admin isn't a `users` row) — surface those by entity instead.
                $q->where('entity_type', 'User')->where('entity_id', $user->id)->where('company_id', $companyId);
            })
            ->orderByDesc('created_at')
            ->limit(30)
            ->get(['id', 'action', 'module_key', 'entity_type', 'entity_id', 'created_at']);

        return ApiResponse::success($result);
    }

    // ── Get permissions for one company ──────────────────────────────────────

    // GET /admin/users/role-defaults?role=X&company_id=Y — default permission
    // map for a role, filtered to only the modules that company has purchased.
    // Single source of truth for Add/Edit User's "select role, auto-check
    // permissions" behavior (App\Services\RoleDefaultPermissions).
    public function roleDefaults(Request $request): JsonResponse
    {
        $companyIds = $this->operationalCompanyIds();

        $validated = $request->validate([
            'role'       => ['required', 'string', 'in:' . implode(',', self::VALID_ROLES)],
            'company_id' => ['required', 'integer', 'in:' . implode(',', $companyIds)],
        ]);

        $rawDbModules = Company::find($validated['company_id'])
            ->modules()
            ->where('is_enabled', true)
            ->pluck('module_key')
            ->toArray();

        $catalogModules = array_values(array_unique(array_map(
            fn ($k) => ModuleCatalog::dbKeyToCatalog($k),
            $rawDbModules
        )));

        $defaults = \App\Services\RoleDefaultPermissions::forRole($validated['role'], $catalogModules);

        return ApiResponse::success(['permissions' => $defaults]);
    }

    // GET /admin/users/roles — the pickable role list for the "Select Role" dropdown.
    public function roleOptions(): JsonResponse
    {
        return ApiResponse::success(\App\Services\RoleDefaultPermissions::roleOptions());
    }

    public function getCompanyPermissions(User $user, int $companyId): JsonResponse
    {
        $orgCompanyIds = $this->companyIds();
        if (!$this->canManageUser($user) || !in_array($companyId, $orgCompanyIds)) {
            return ApiResponse::error('Not found', 404);
        }

        $assignment = CompanyUserAssignment::where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->first();

        $query = UserCompanyPermission::where('user_id', $user->id)
            ->where('company_id', $companyId);

        // Rule 7: prefer filtering by company_user_id when available
        if ($assignment) {
            $query = UserCompanyPermission::where('company_user_id', $assignment->id);
        }

        $rows = $query->get();

        $perms = $rows->groupBy('module_key')
            ->map(fn ($g) => $g->pluck('permission_key')->toArray())
            ->toArray();

        // Data Scope is descriptive-only (like role_type) — shown for clarity,
        // never used to filter any module's actual query results.
        $dataScopes = $rows->groupBy('module_key')
            ->map(fn ($g) => $g->first()->data_scope)
            ->filter()
            ->toArray();

        return ApiResponse::success(['permissions' => $perms, 'data_scopes' => $dataScopes]);
    }

    // ── Update permissions for one company ────────────────────────────────────

    public function updateCompanyPermissions(Request $request, User $user, int $companyId): JsonResponse
    {
        $orgCompanyIds = $this->companyIds();
        if (!$this->canManageUser($user) || !in_array($companyId, $orgCompanyIds)) {
            return ApiResponse::error('Not found', 404);
        }

        $validated = $request->validate([
            'permissions'   => ['required', 'array'],
            'data_scopes'   => ['nullable', 'array'],
            'data_scopes.*' => ['nullable', 'string', 'in:own,assigned,all,view_only,no_access'],
            // Roles for THIS company. This is the per-company role editor: the
            // same person can be a Seller here and a Project Manager elsewhere,
            // and saving one company must not disturb another.
            'roles'         => ['nullable', 'array'],
            'roles.*'       => ['string', 'in:' . implode(',', self::VALID_ROLES)],
        ]);
        $dataScopes = $validated['data_scopes'] ?? [];

        $roles = UserCompanyRoleService::sync($user, $companyId, $validated['roles'] ?? []);

        // Keep the legacy column pointing at this user's primary role, but only
        // when the company being edited is the one users.company_id names —
        // otherwise editing a secondary company would rewrite the headline role
        // the primary company's screens show.
        if ($roles !== [] && (int) $user->company_id === $companyId && $user->role_type !== $roles[0]) {
            $user->update(['role_type' => $roles[0]]);
        }

        $permissions = $this->withMandatoryRolePermissionsForCompany(
            $validated['permissions'],
            // The mandatory-extras rule is about Lead Manager specifically, so
            // it has to see every role held here, not just the headline one.
            $roles !== [] ? $roles : [$user->role_type],
            $companyId
        );

        // Rule 7: get or create the assignment, then store permissions against its ID
        $assignment = CompanyUserAssignment::firstOrCreate(
            ['user_id' => $user->id, 'company_id' => $companyId],
            ['assigned_by' => auth('admin')->id(), 'status' => 'active']
        );

        // Delete existing permissions via company_user_id (cascade) + old-style fallback
        UserCompanyPermission::where('company_user_id', $assignment->id)->delete();
        UserCompanyPermission::where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->delete();

        foreach ($permissions as $moduleKey => $permKeys) {
            foreach ((array) $permKeys as $permKey) {
                if (!ModuleCatalog::isValidPermission($moduleKey, $permKey)) continue;
                UserCompanyPermission::create([
                    'company_user_id' => $assignment->id,
                    'user_id'         => $user->id,
                    'company_id'      => $companyId,
                    'module_key'      => $moduleKey,
                    'permission_key'  => $permKey,
                    'data_scope'      => $dataScopes[$moduleKey] ?? null,
                ]);
            }
        }

        $perms = UserCompanyPermission::where('company_user_id', $assignment->id)
            ->get()
            ->groupBy('module_key')
            ->map(fn ($g) => $g->pluck('permission_key')->toArray())
            ->toArray();

        $this->logAudit($request, $companyId, 'user.permissions_updated', 'User', $user->id, [], ['permissions' => $permissions]);

        return ApiResponse::success(['permissions' => $perms, 'data_scopes' => $dataScopes], 'Permissions updated');
    }

    // ── Legacy: old-style module permissions (keep for backward compat) ───────

    public function syncPermissions(Request $request, User $user): JsonResponse
    {
        if (!$this->canManageUser($user)) {
            return ApiResponse::error('Not found', 404);
        }

        $validated = $request->validate([
            'permissions'              => ['required', 'array'],
            'permissions.*.module_key' => ['required', 'string'],
            'permissions.*.can_view'   => ['boolean'],
            'permissions.*.can_create' => ['boolean'],
            'permissions.*.can_edit'   => ['boolean'],
            'permissions.*.can_delete' => ['boolean'],
            'permissions.*.can_export' => ['boolean'],
        ]);

        $user->permissions()->delete();
        foreach ($validated['permissions'] as $perm) {
            if (!($perm['can_view'] ?? false)) continue;
            UserPermission::create([
                'user_id'    => $user->id,
                'module_key' => $perm['module_key'],
                'can_view'   => true,
                'can_create' => $perm['can_create'] ?? false,
                'can_edit'   => $perm['can_edit']   ?? false,
                'can_delete' => $perm['can_delete']  ?? false,
                'can_export' => $perm['can_export']  ?? false,
            ]);
        }

        return ApiResponse::success(
            new UserResource($user->load(['permissions', 'company:id,name'])),
            'Permissions updated'
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    // Rule 7: permissions are stored against company_user_id (the assignment row ID)
    private function saveAssignments(User $user, array $assignments): void
    {
        $orgCompanyIds = $this->companyIds();

        foreach ($assignments as $assignment) {
            $companyId = $assignment['company_id'];
            if (!in_array($companyId, $orgCompanyIds)) continue;

            // Roles for THIS company, already resolved by
            // withRoleDefaultPermissions(). An empty set leaves whatever roles
            // the user already holds here alone — see UserCompanyRoleService.
            UserCompanyRoleService::sync($user, (int) $companyId, $assignment['roles'] ?? []);

            // Get or create the assignment row and capture its ID
            $companyUserAssignment = CompanyUserAssignment::firstOrCreate(
                ['user_id' => $user->id, 'company_id' => $companyId],
                ['assigned_by' => auth('admin')->id(), 'status' => 'active']
            );

            // Rule 7: delete old permissions via company_user_id; also clear old-style rows
            UserCompanyPermission::where('company_user_id', $companyUserAssignment->id)->delete();
            UserCompanyPermission::where('user_id', $user->id)
                ->where('company_id', $companyId)
                ->delete();

            $dataScopes = $assignment['data_scopes'] ?? [];

            foreach ($assignment['permissions'] ?? [] as $moduleKey => $permKeys) {
                foreach ((array) $permKeys as $permKey) {
                    if (!ModuleCatalog::isValidPermission($moduleKey, $permKey)) continue;
                    UserCompanyPermission::create([
                        'company_user_id' => $companyUserAssignment->id,
                        'user_id'         => $user->id,
                        'company_id'      => $companyId,
                        'module_key'      => $moduleKey,
                        'permission_key'  => $permKey,
                        'data_scope'      => $dataScopes[$moduleKey] ?? null,
                    ]);
                }
            }
        }
    }

    /**
     * Fill each assignment's missing permission buckets from its roles' defaults.
     *
     * @param array<int, string>|string|null $roles The roles applying to every
     *        assignment that does not carry its own `roles` entry. A bare
     *        string is the legacy single-role call and still works.
     */
    private function withRoleDefaultPermissions(array $assignments, array|string|null $roles): array
    {
        $fallbackRoles = UserCompanyRoleService::resolveFromPayload(
            [],
            is_array($roles) ? $roles : null,
            is_string($roles) ? $roles : null
        );

        return array_map(function (array $assignment) use ($fallbackRoles) {
            $companyId = $assignment['company_id'] ?? null;
            if (!$companyId) return $assignment;

            // Roles are company-scoped, so an assignment carrying its own set
            // wins over the shared one for this company only.
            $roles = UserCompanyRoleService::resolveFromPayload($assignment, $fallbackRoles, null);
            if ($roles === []) return $assignment;

            // Written back so saveAssignments() has the RESOLVED set to store,
            // and does not have to repeat the per-company fallback logic.
            $assignment['roles'] = $roles;

            $purchasedCatalogModules = Company::find($companyId)?->modules()
                ->where('is_enabled', true)
                ->pluck('module_key')
                ->map(fn ($key) => ModuleCatalog::dbKeyToCatalog($key))
                ->unique()
                ->values()
                ->toArray() ?? [];

            // Union of every role's defaults — a second role only ever adds.
            $defaults = RoleDefaultPermissions::forRoles($roles, $purchasedCatalogModules);
            if (empty($defaults)) return $assignment;

            $permissions = $assignment['permissions'] ?? [];
            foreach ($defaults as $moduleKey => $permKeys) {
                // If the UI already sent this module bucket, respect whatever
                // the admin checked/unchecked. Fill only missing buckets so a
                // role like Lead Manager always gets its default Sales grants
                // when the frontend payload omitted that bucket entirely.
                if (array_key_exists($moduleKey, $permissions)) {
                    if (in_array('lead_manager', $roles, true) && $moduleKey === 'sales') {
                        $permissions[$moduleKey] = array_values(array_unique(array_merge(
                            (array) $permissions[$moduleKey],
                            $permKeys
                        )));
                    }
                    continue;
                }
                $permissions[$moduleKey] = $permKeys;
            }

            $assignment['permissions'] = $permissions;
            return $assignment;
        }, $assignments);
    }

    /**
     * @param array<int, string|null>|string|null $roles Every role held in this
     *        company. A bare string is the legacy single-role call.
     */
    private function withMandatoryRolePermissionsForCompany(array $permissions, array|string|null $roles, int $companyId): array
    {
        $roles = array_filter((array) $roles);

        if (!in_array('lead_manager', $roles, true)) return $permissions;

        $purchasedCatalogModules = Company::find($companyId)?->modules()
            ->where('is_enabled', true)
            ->pluck('module_key')
            ->map(fn ($key) => ModuleCatalog::dbKeyToCatalog($key))
            ->unique()
            ->values()
            ->toArray() ?? [];

        $defaults = RoleDefaultPermissions::forRole('lead_manager', $purchasedCatalogModules);

        // Mirrors canViewAllCompanyLeads above — a Lead Manager's
        // company-wide invoice visibility (canViewAllCompanyInvoices) must
        // survive an Admin re-saving this user's permissions the same way
        // the 'sales' bucket already does, or it silently drops the moment
        // any unrelated permission is edited (see
        // Api\User\InvoiceController::visibleInvoices()).
        foreach (['sales', 'invoice'] as $moduleKey) {
            $moduleDefaults = $defaults[$moduleKey] ?? [];
            if (empty($moduleDefaults)) continue;

            $permissions[$moduleKey] = array_values(array_unique(array_merge(
                (array) ($permissions[$moduleKey] ?? []),
                $moduleDefaults
            )));
        }

        return $permissions;
    }
}
