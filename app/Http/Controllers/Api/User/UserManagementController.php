<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Company;
use App\Models\CompanyAdmin;
use App\Models\CompanyUserAssignment;
use App\Models\Deliverable;
use App\Models\Project;
use App\Models\SystemAuditLog;
use App\Models\Task;
use App\Models\Timesheet;
use App\Models\User;
use App\Models\UserCompanyPermission;
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
use Illuminate\Support\Str;

// Lets a staff member (e.g. a Project Manager) add a user to their own
// company, gated on a per-module `canAddUsers` permission — mirrors
// Api\Admin\UserController::store()'s existing-user-check flow, but scoped
// to the acting staff member's own company and capped by their own granted
// permissions (a staff member can never hand out access they don't have).
//
// Two callers with very different reach share it: an ordinary delegated
// manager, and the Admin role acting as the Company Admin's deputy. See
// isDeputyAdmin() for exactly what separates them.
class UserManagementController extends Controller
{
    // Api\Admin\UserController::VALID_ROLES minus
    // RoleDefaultPermissions::COMPANY_ADMIN_ONLY_ROLES: 'company_admin' and
    // 'admin' (the brand keeper) are the tenant owner's to hand out, so a
    // delegated manager can neither pick them from the dropdown (roleOptions()
    // below filters them out) nor slip one past it in a request — this list is
    // what the validator accepts.
    //
    // The Admin role is the exception to all of that: see isDeputyAdmin().
    private const VALID_ROLES = [
        'seller', 'client', 'hr', 'finance', 'project_manager', 'production',
        'invoice_admin', 'invoice_manager', 'invoice_creator', 'invoice_viewer', 'payment_manager',
        'developer', 'designer', 'qa', 'team_member', 'viewer', 'compliance', 'invoice_user',
        'lead_manager',
    ];

    private function user() { return auth('sanctum')->user(); }

    // ── Deputy: the Admin role ───────────────────────────────────────────────
    //
    // Two very different people share this controller.
    //
    // A DELEGATED MANAGER is ordinary staff (a Project Manager, say) who was
    // handed the User Management Permission for one company. They administer
    // that company's staff and nothing above themselves: they cannot mint more
    // managers, cannot hand out the Admin role, and cannot touch anyone who
    // already holds either. Those four caps are the whole shape of the
    // feature — hiddenRoles(), validRoles(), grantableKeys() and
    // refusePeerManager() below.
    //
    // The ADMIN ROLE is the Company Admin's deputy on the staff side, created
    // to do the tenant owner's job for them (RoleDefaultPermissions::forRole()
    // provisions it with the identical full permission set as
    // 'company_admin'). For them every one of those four caps is lifted: they
    // manage the whole roster including other Admins and managers, assign any
    // role including Admin, and grant the User Management Permission.
    //
    // What still holds for a deputy, and is the reason this stays safe:
    //   • refuseSelf() — nobody re-permissions, suspends or deletes their own
    //     account here, deputy included.
    //   • manageableCompanyIds() — still companies where they hold
    //     canAddUsers, so a Company Admin can withhold this per company by
    //     revoking that one permission.
    //   • The Company Admin's own login is a CompanyAdmin row on a separate
    //     guard, not a User row: nothing on this controller can reach it, so
    //     the tenant owner can always undo whatever a deputy does.
    private function isDeputyAdmin(): bool
    {
        return $this->user()->role_type === 'admin';
    }

    // Guard for the routes that exist ONLY for a deputy — the ones with no
    // delegated-manager equivalent at all (invite, reset password, the
    // deletion Impact Summary and its reassign step, permanent delete, the
    // per-user activity log). A delegated manager never sees the buttons, and
    // this is what makes hitting the route directly pointless.
    private function refuseUnlessDeputy(): ?JsonResponse
    {
        if (!$this->isDeputyAdmin()) {
            return ApiResponse::error(
                'Only your Company Admin or an Admin can do this.',
                403
            );
        }

        return null;
    }

    // Everything a deputy-only single-user route checks before touching the
    // target, in the one order they all use: deputy → in scope → not
    // themselves. Returns the refusal to hand straight back, or null to go on.
    private function refuseDeputyTarget(User $target, array $companyIds, string $verb): ?JsonResponse
    {
        if ($refusal = $this->refuseUnlessDeputy()) {
            return $refusal;
        }

        if (!$companyIds || !$this->isManageableTarget($target, $companyIds)) {
            return ApiResponse::error('Not found', 404);
        }

        return $this->refuseSelf($target, $verb);
    }

    // ── Company scope ────────────────────────────────────────────────────────
    //
    // Everything in the delegated User Management interface hangs off ONE
    // rule: this staff member may act inside a company only when they hold an
    // ACTIVE assignment there AND were granted the "User Management
    // Permission" (account.canAddUsers — the name Edit User already shows it
    // under) for that same company. Permissions are stored per company, so a
    // manager who belongs to two companies but only holds the grant in one
    // manages exactly that one; users of every other company stay invisible
    // and unreachable, which is the whole point of this feature.
    //
    // permission_key is matched without pinning module_key, mirroring
    // canAddUsersAnywhere() above: the key lives under 'account' today, but
    // it used to be duplicated inside every module and those older grants
    // must keep working.
    private function manageableCompanyIds(): array
    {
        $me = $this->user();

        $activeCompanyIds = CompanyUserAssignment::where('user_id', $me->id)
            ->where('status', 'active')
            ->pluck('company_id');

        $eligible = UserCompanyPermission::where('user_id', $me->id)
            ->where('permission_key', 'canAddUsers')
            ->whereIn('company_id', $activeCompanyIds)
            ->pluck('company_id')
            ->unique()
            ->values();

        // A suspended/deactivated company (Company::isOperational()) can
        // never be assigned a new/invited staff member.
        return Company::whereIn('id', $eligible)->operational()->pluck('id')->all();
    }

    // Narrows the scope to one company when the caller asks for it (the
    // company picker in the UI). Returns null when they asked for a company
    // they don't manage — never silently widens back to "all of mine".
    private function scopeCompanyIds(Request $request, array $companyIds): ?array
    {
        if (!$request->filled('company_id')) {
            return $companyIds;
        }

        $requested = (int) $request->input('company_id');

        return in_array($requested, $companyIds, true) ? [$requested] : null;
    }

    // Roles a delegated manager never sees or touches, on top of their own
    // account: the tenant owner's own buckets. An Admin is the Company
    // Admin's peer, not staff for a manager to administer.
    private const HIDDEN_ROLES = ['client', 'company_admin', 'admin'];

    // Client-portal logins are never staff, so they stay out of the roster for
    // everyone. The tenant owner's buckets are hidden from a delegated manager
    // only — a deputy manages the whole roster, other Admins included.
    private function hiddenRoles(): array
    {
        return $this->isDeputyAdmin() ? ['client'] : self::HIDDEN_ROLES;
    }

    // Roles this caller may assign. A deputy hands out the same list the
    // Company Admin does, the Admin role included, so it can staff the
    // company without the owner; a delegated manager is held to VALID_ROLES.
    private function validRoles(): array
    {
        return $this->isDeputyAdmin() ? AdminUserController::VALID_ROLES : self::VALID_ROLES;
    }

    // A user is manageable when they hold an assignment in one of the
    // manager's companies — the whole company roster, not just accounts this
    // manager happened to create. index() lists exactly the same set, and
    // this is checked on every single-user route too, so what can be reached
    // by id never exceeds what is shown.
    //
    // Excluded: hiddenRoles() (client logins always, the owner's own buckets
    // for a delegated manager) and the caller themselves (refuseSelf() covers
    // the mutations, this keeps them out of their own list).
    private function isManageableTarget(User $target, array $companyIds): bool
    {
        if (in_array($target->role_type, $this->hiddenRoles(), true)) {
            return false;
        }

        if ($target->id === $this->user()->id) {
            return false;
        }

        return CompanyUserAssignment::where('user_id', $target->id)
            ->whereIn('company_id', $companyIds)
            ->exists();
    }

    // Which company a per-company action (remove, suspend, permissions)
    // applies to: the requested one when the manager owns it AND the target
    // actually belongs to it, else the first company they have in common.
    private function resolveCompanyId(User $target, ?int $requested, array $companyIds): ?int
    {
        $shared = CompanyUserAssignment::where('user_id', $target->id)
            ->whereIn('company_id', $companyIds)
            ->pluck('company_id')
            ->all();

        if ($requested !== null && in_array($requested, $shared, true)) {
            return $requested;
        }

        return $shared[0] ?? null;
    }

    // The User Management Permission itself is never passed on by a delegated
    // manager — only a Company Admin creates managers. Everything else in the
    // catalog is theirs to grant (their Add/Edit User screens are the Company
    // Admin's own, showing every module), so these exclusions are what stop
    // the first manager minting more of themselves and, through them, more
    // again.
    //
    // canManageSettings (2026-09-11) is excluded for the adjacent reason: it
    // hands over the company's invoicing identity, bank account details and
    // live payment gateway credentials. A delegated manager is ordinary staff
    // who was handed the roster for one company — letting them mint a user
    // holding that would be an escalation route out of the cap, since they
    // cannot grant it to themselves (refuseSelf()) but could hand it to
    // someone they create. Both the Company Admin and the deputy Admin still
    // grant it; the Add/Edit User screens do not render either checkbox for a
    // delegated manager (see `ownerReach`), and this is what makes posting the
    // key directly pointless.
    private const NEVER_GRANTABLE = ['canAddUsers', 'canManageSettings'];

    // Does this person already hold the User Management Permission in any
    // company the manager works in? Such a user is a PEER, not staff to be
    // administered: they appear in the list by name (so the manager knows the
    // company's full roster) and nothing more — no edit, no permissions, no
    // suspend, no removal. Only Company Admin manages managers.
    private function isPeerManager(User $target, array $companyIds): bool
    {
        return UserCompanyPermission::where('user_id', $target->id)
            ->where('permission_key', 'canAddUsers')
            ->whereIn('company_id', $companyIds)
            ->exists();
    }

    private function refusePeerManager(User $target, array $companyIds): ?JsonResponse
    {
        // A deputy administers the whole roster — other Admins and other User
        // Managers included — because it is standing in for the Company Admin,
        // who has exactly that reach. Neither cap below applies to them.
        if ($this->isDeputyAdmin()) {
            return null;
        }

        // A Company Admin-only role (Admin, Company Admin) is as far out of a
        // delegated manager's reach as a peer manager is — otherwise someone
        // they created and the Company Admin later promoted could be demoted
        // straight back, or re-permissioned, by the manager.
        if (in_array($target->role_type, RoleDefaultPermissions::COMPANY_ADMIN_ONLY_ROLES, true)) {
            return ApiResponse::error(
                'Only your Company Admin can change this user\'s account.',
                403
            );
        }

        if ($this->isPeerManager($target, $companyIds)) {
            return ApiResponse::error(
                'This user also holds the User Management Permission, so only your Company Admin can change their account.',
                403
            );
        }

        return null;
    }

    // A delegated manager hands out the same permission catalog a Company
    // Admin does — every module, every key — so the Add/Edit User screens they
    // get are the real thing rather than a subset of their own access. The one
    // exception is NEVER_GRANTABLE above, which is filtered out here.
    //
    // A deputy is granting on the Company Admin's behalf, so nothing is held
    // back: it may pass the User Management Permission on, the way the owner
    // passed it to them.
    private function grantableKeys(array $keys): array
    {
        $keys = array_values(array_unique($keys));

        return $this->isDeputyAdmin() ? $keys : array_values(array_diff($keys, self::NEVER_GRANTABLE));
    }

    // Refuses any action a manager aims at their own account: suspending,
    // removing or re-permissioning yourself is either a foot-gun (locking
    // yourself out of the company) or an escalation route (handing yourself
    // rights nobody granted you). Company Admin remains the only one who can
    // change this person's own access.
    private function refuseSelf(User $target, string $verb): ?JsonResponse
    {
        if ($target->id === $this->user()->id) {
            return ApiResponse::error("You cannot {$verb} your own account. Ask your Company Admin.", 422);
        }

        return null;
    }

    private function logAudit(Request $request, ?int $companyId, string $action, ?int $entityId, array $old = [], array $new = []): void
    {
        SystemAuditLog::create([
            'company_id'  => $companyId,
            'user_id'     => $this->user()->id,
            'action'      => $action,
            'module_key'  => 'users',
            'entity_type' => 'User',
            'entity_id'   => $entityId,
            'old_values'  => $old ?: null,
            'new_values'  => $new ?: null,
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);
    }

    // Scoped reload for a response that replaces a row in the manager's list —
    // unscoped, a multi-company user would come back carrying a company this
    // manager has no business seeing (and the list's per-company suspend
    // picker is built straight from company_assignments).
    private function loadScoped(User $user, array $companyIds): User
    {
        $user->load([
            'companyAssignments' => fn ($q) => $q->whereIn('company_id', $companyIds)->with('company:id,name,is_active,suspended_at'),
            'userCompanyPermissions' => fn ($q) => $q->whereIn('company_id', $companyIds),
            'companyRoles' => fn ($q) => $q->whereIn('company_id', $companyIds),
            'company:id,name',
            'createdBy:id,name',
        ]);

        if ($user->company_id && !in_array($user->company_id, $companyIds, true)) {
            $user->setRelation('company', null);
        }

        return $user;
    }

    // Mirrors Api\Admin\UserController::orgUserCount() exactly — see that
    // method's comment for why the status filter and distinct() matter.
    private function orgUserCount(int $adminId): int
    {
        $companyIds = Company::where('admin_id', $adminId)->pluck('id')->toArray();
        return CompanyUserAssignment::whereIn('company_id', $companyIds)
            ->where('status', 'active')
            ->distinct('user_id')
            ->count('user_id') + 1;
    }

    private function orgUserLimit(int $adminId): ?int
    {
        $admin = CompanyAdmin::with('package')->find($adminId);
        return $admin?->max_users_per_company ?? $admin?->package?->max_users_per_company;
    }

    private function seatLimitMessage(int $used, int $limit): string
    {
        return "Seat limit reached. You have used {$used} of {$limit} seats. Please ask your Company Admin to upgrade the plan.";
    }

    public function store(Request $request): JsonResponse
    {
        $me = $this->user();
        $companyIds = $this->manageableCompanyIds();

        if (!$companyIds) {
            return ApiResponse::error('Permission denied', 403);
        }

        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:150'],
            'email'       => ['required', 'email'],
            'password'    => ['nullable', 'string', 'min:8'],
            'role_type'   => ['nullable', 'string', 'in:' . implode(',', $this->validRoles())],
            // Multi-role: the roles this user holds. 'role_type' above is still
            // accepted and still written (as the PRIMARY role) so every older
            // caller keeps working — see UserCompanyRoleService::resolveFromPayload().
            'roles'       => ['nullable', 'array'],
            'roles.*'     => ['string', 'in:' . implode(',', $this->validRoles())],
            'custom_role_label' => ['nullable', 'string', 'max:100'],
            // The shape the shared Add User wizard sends (same as
            // Api\Admin\UserController::store()): one entry per company, each
            // with its own permission set. Every company_id is validated
            // against this manager's own list, so the wizard can never place
            // someone in a company they don't manage.
            'company_assignments'                => ['nullable', 'array'],
            'company_assignments.*.company_id'   => ['required', 'integer', 'in:' . implode(',', $companyIds)],
            'company_assignments.*.permissions'  => ['nullable', 'array'],
            'company_assignments.*.data_scopes'  => ['nullable', 'array'],
            // Per-company roles. Roles are company-scoped, so the wizard may
            // send a different set for each company; when it sends none, the
            // top-level 'roles' (or legacy 'role_type') applies to them all.
            'company_assignments.*.roles'        => ['nullable', 'array'],
            'company_assignments.*.roles.*'      => ['string', 'in:' . implode(',', $this->validRoles())],
            // Flat shape kept for the Team Resources "Add New User" form,
            // which posts name/email/password/permissions with no company at
            // all and expects the manager's own company.
            'permissions' => ['nullable', 'array'],
            'company_id'  => ['nullable', 'integer', 'in:' . implode(',', $companyIds)],
        ]);

        $assignments = $validated['company_assignments'] ?? [];
        if (!$assignments) {
            $assignments = [[
                'company_id'  => (int) ($validated['company_id']
                    ?? (in_array($me->company_id, $companyIds, true) ? $me->company_id : $companyIds[0])),
                'permissions' => $validated['permissions'] ?? [],
            ]];
        }

        // The account's "primary" company — the one users.company_id points
        // at, which every permission check reads.
        $companyId = (int) $assignments[0]['company_id'];
        $company   = Company::findOrFail($companyId);
        $adminId   = $company->admin_id;

        // withTrashed(): a user soft-deleted after being removed from their
        // last company still holds this email in the database's unique
        // index — without this, $existingUser comes back null and the
        // User::create() below throws a raw duplicate-key SQL error instead
        // of restoring and linking them like any other existing user.
        $existingUser = User::withTrashed()->where('email', $validated['email'])->first();

        if ($existingUser) {
            if ($existingUser->trashed()) {
                $existingUser->restore();
            }

            foreach ($assignments as $a) {
                $alreadyLinked = CompanyUserAssignment::where('user_id', $existingUser->id)
                    ->where('company_id', $a['company_id'])
                    ->exists();
                if ($alreadyLinked) {
                    return ApiResponse::error('This user is already added to this company.', 422);
                }
            }

            $limit = $this->orgUserLimit($adminId);
            $used  = $this->orgUserCount($adminId);
            if ($limit !== null && $used >= $limit) {
                return ApiResponse::error($this->seatLimitMessage($used, $limit), 422);
            }

            foreach ($assignments as $a) {
                $this->grantAssignment(
                    $existingUser,
                    (int) $a['company_id'],
                    $a['permissions'] ?? [],
                    UserCompanyRoleService::resolveFromPayload($a, $validated['roles'] ?? null, $validated['role_type'] ?? null)
                );

                SystemAuditLog::create([
                    'company_id'  => $a['company_id'],
                    'user_id'     => $me->id,
                    'action'      => 'user.linked_to_company',
                    'module_key'  => 'users',
                    'entity_type' => 'User',
                    'entity_id'   => $existingUser->id,
                    'new_values'  => ['email' => $existingUser->email],
                ]);
            }

            return ApiResponse::success(
                new UserResource($existingUser->load(['companyAssignments.company:id,name,is_active,suspended_at', 'userCompanyPermissions', 'companyRoles', 'company:id,name'])),
                'User linked to company',
                200
            );
        }

        // An email that's already a Company Admin account must never also
        // become a User row — see the matching check in
        // Api\Admin\UserController::store().
        if (CrossAccountEmail::existsAsAdmin($validated['email'])) {
            return ApiResponse::error('This email is already registered as a Company Admin account.', 422);
        }

        if (empty($validated['password'])) {
            return ApiResponse::error('Password is required when adding a new user.', 422);
        }

        $limit = $this->orgUserLimit($adminId);
        $used  = $this->orgUserCount($adminId);
        if ($limit !== null && $used >= $limit) {
            return ApiResponse::error($this->seatLimitMessage($used, $limit), 422);
        }

        // Roles for the account's primary company decide users.role_type. That
        // column is not company-scoped, so it can only ever reflect ONE
        // company's primary role — user_company_roles is what the other
        // companies' roles live in, and rolesIn() is what reads them.
        $primaryCompanyRoles = UserCompanyRoleService::resolveFromPayload(
            $assignments[0],
            $validated['roles'] ?? null,
            $validated['role_type'] ?? null
        );

        $newUser = User::create([
            'company_id' => $companyId,
            'name'       => $validated['name'],
            'email'      => $validated['email'],
            'password'   => $validated['password'],
            // purely descriptive — matches Api\Admin\UserController's own fallback
            'role_type'  => $primaryCompanyRoles[0] ?? $validated['role_type'] ?? 'seller',
            'custom_role_label' => $validated['custom_role_label'] ?? null,
            'created_by' => $me->id,
            'is_active'  => true,
            'status'     => 'active',
        ]);

        foreach ($assignments as $a) {
            $this->grantAssignment(
                $newUser,
                (int) $a['company_id'],
                $a['permissions'] ?? [],
                UserCompanyRoleService::resolveFromPayload($a, $validated['roles'] ?? null, $validated['role_type'] ?? null)
            );
        }

        SystemAuditLog::create([
            'company_id'  => $companyId,
            'user_id'     => $me->id,
            'action'      => 'user.created',
            'module_key'  => 'users',
            'entity_type' => 'User',
            'entity_id'   => $newUser->id,
            'new_values'  => ['name' => $newUser->name, 'email' => $newUser->email],
        ]);

        return ApiResponse::success(
            new UserResource($newUser->load(['companyAssignments.company:id,name,is_active,suspended_at', 'userCompanyPermissions', 'companyRoles', 'company:id,name'])),
            'User created',
            201
        );
    }

    // Writes the permissions picked on the Add User screen. A manager grants
    // from the full catalog, exactly as a Company Admin does — see
    // grantableKeys() for the one key a DELEGATED manager can't pass on. The
    // right to add users at all was already established by
    // manageableCompanyIds() in store().
    /**
     * @param array<int, string> $roles Roles held in THIS company, primary
     *                                  first. Empty leaves existing roles
     *                                  untouched (see UserCompanyRoleService).
     */
    private function grantAssignment(User $target, int $companyId, array $requestedPerms, array $roles = []): void
    {
        UserCompanyRoleService::sync($target, $companyId, $roles);

        $assignment = CompanyUserAssignment::firstOrCreate(
            ['user_id' => $target->id, 'company_id' => $companyId],
            // assigned_by holds a CompanyAdmin id by convention elsewhere in
            // this codebase — left null here since a staff member (not an
            // admin) performed this assignment; SystemAuditLog carries the
            // real actor instead.
            ['assigned_by' => null, 'status' => 'active']
        );

        foreach ($requestedPerms as $moduleKey => $permKeys) {
            foreach ($this->grantableKeys((array) $permKeys) as $permKey) {
                if (!ModuleCatalog::isValidPermission($moduleKey, $permKey)) continue;

                UserCompanyPermission::create([
                    'company_user_id' => $assignment->id,
                    'user_id'         => $target->id,
                    'company_id'      => $companyId,
                    'module_key'      => $moduleKey,
                    'permission_key'  => $permKey,
                ]);
            }
        }
    }

    // ── Users list ───────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $companyIds = $this->manageableCompanyIds();
        if (!$companyIds) {
            return ApiResponse::error('Permission denied', 403);
        }

        $scoped = $this->scopeCompanyIds($request, $companyIds);
        if ($scoped === null) {
            return ApiResponse::error('Permission denied', 403);
        }

        // Membership rows are the only source of truth here. Api\Admin\
        // UserController::index() additionally sweeps up "orphans" — accounts
        // with no assignment row at all — because a Company Admin owns those
        // and would otherwise have no way to reach them. A delegated manager
        // has no such claim: an account with no membership in their company
        // isn't theirs to see, so it stays out.
        $userIds = CompanyUserAssignment::whereIn('company_id', $scoped)
            ->pluck('user_id')
            ->unique();

        $query = User::with([
                'companyAssignments' => fn ($q) => $q->whereIn('company_id', $companyIds)->with('company:id,name,is_active,suspended_at'),
                'userCompanyPermissions' => fn ($q) => $q->whereIn('company_id', $companyIds),
                'companyRoles' => fn ($q) => $q->whereIn('company_id', $companyIds),
                'company:id,name',
                'createdBy:id,name',
            ])
            ->whereIn('id', $userIds)
            // The whole roster of the selected company — everyone assigned to
            // it, however they were added. Two exclusions, matching
            // isManageableTarget() exactly so nothing reachable by id is
            // missing from the list: the Admin/Company Admin roles (the
            // tenant owner's own, not a manager's to see) and client-portal
            // logins; plus the manager's own account, which only the Company
            // Admin may change.
            ->whereNotIn('role_type', $this->hiddenRoles())
            ->where('id', '!=', $this->user()->id);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $users = $query->latest()->get();

        $users->each(function (User $u) use ($companyIds) {
            if ($u->company_id && !in_array($u->company_id, $companyIds, true)) {
                $u->setRelation('company', null);
            }
        });

        // Seat usage is an org-level figure (every company under the same
        // Company Admin shares one pool) — read from the admin who owns the
        // companies this manager works in, the same number they'd be told
        // about if they hit the limit while adding someone.
        $adminId = Company::whereIn('id', $companyIds)->value('admin_id');

        return ApiResponse::success([
            'users' => UserResource::collection($users),
            'count' => $users->count(),
            'used'  => $adminId ? $this->orgUserCount($adminId) : 0,
            'limit' => $adminId ? $this->orgUserLimit($adminId) : null,
        ]);
    }

    // ── Pickers for the add/edit forms ───────────────────────────────────────

    // Only the companies this manager may act in — this list IS the company
    // scope as far as the UI is concerned (Add User's company dropdown, the
    // Edit User company tabs, the suspend/remove company pickers).
    public function companyOptions(): JsonResponse
    {
        $companyIds = $this->manageableCompanyIds();
        if (!$companyIds) {
            return ApiResponse::error('Permission denied', 403);
        }

        $companies = Company::whereIn('id', $companyIds)
            ->select('id', 'name')
            ->orderBy('id')
            ->get()
            ->map(fn (Company $c) => [
                'id'      => $c->id,
                'name'    => $c->name,
                'modules' => $c->modules()->where('is_enabled', true)->pluck('module_key')->toArray(),
            ]);

        return ApiResponse::success($companies);
    }

    public function roleOptions(): JsonResponse
    {
        if (!$this->manageableCompanyIds()) {
            return ApiResponse::error('Permission denied', 403);
        }

        // Company Admin and Admin never appear in a DELEGATED manager's role
        // dropdown, however much user-management access they were given —
        // those two are the tenant owner's to assign. A deputy Admin assigns
        // on the owner's behalf and gets the full list, matching validRoles().
        if ($this->isDeputyAdmin()) {
            return ApiResponse::success(RoleDefaultPermissions::roleOptions());
        }

        return ApiResponse::success(array_values(array_filter(
            RoleDefaultPermissions::roleOptions(),
            fn ($r) => !in_array($r['value'], RoleDefaultPermissions::COMPANY_ADMIN_ONLY_ROLES, true)
        )));
    }

    // Role defaults, already capped to what this manager holds themselves —
    // the Add/Edit form pre-ticks boxes from this, so handing back the raw
    // role defaults would show a manager permissions they cannot actually
    // grant, and the save would silently drop them.
    public function roleDefaults(Request $request): JsonResponse
    {
        $companyIds = $this->manageableCompanyIds();
        if (!$companyIds) {
            return ApiResponse::error('Permission denied', 403);
        }

        $validated = $request->validate([
            // 'role' stays required-or-'roles': the wizard now sends the whole
            // selected set so the pre-ticked boxes match what the user will
            // actually get, but every older caller still sends one role.
            'role'       => ['required_without:roles', 'string', 'in:' . implode(',', $this->validRoles())],
            'roles'      => ['required_without:role', 'array'],
            'roles.*'    => ['string', 'in:' . implode(',', $this->validRoles())],
            'company_id' => ['required', 'integer', 'in:' . implode(',', $companyIds)],
        ]);

        $catalogModules = array_values(array_unique(array_map(
            fn ($k) => ModuleCatalog::dbKeyToCatalog($k),
            Company::find($validated['company_id'])->modules()->where('is_enabled', true)->pluck('module_key')->toArray()
        )));

        // The role's real defaults, uncapped — the manager's Add/Edit screens
        // show the full catalog, so pre-ticking a reduced set would just make
        // the role look like it grants less than it does.
        // Union across every selected role — holding two roles adds their
        // grants together, it never picks a winner between them.
        $defaults = RoleDefaultPermissions::forRoles(
            $validated['roles'] ?? [$validated['role']],
            $catalogModules
        );

        $out = [];
        foreach ($defaults as $moduleKey => $permKeys) {
            $keys = $this->grantableKeys((array) $permKeys);
            if ($keys) $out[$moduleKey] = $keys;
        }

        return ApiResponse::success(['permissions' => $out]);
    }

    public function checkEmail(Request $request): JsonResponse
    {
        if (!$this->manageableCompanyIds()) {
            return ApiResponse::error('Permission denied', 403);
        }

        $validated = $request->validate(['email' => ['required', 'email']]);

        if (CrossAccountEmail::existsAsAdmin($validated['email'])) {
            return ApiResponse::success(['exists' => true, 'is_admin' => true]);
        }

        $user = User::withTrashed()->where('email', $validated['email'])->first();

        return ApiResponse::success($user
            ? ['exists' => true, 'name' => $user->name, 'status' => $user->status]
            : ['exists' => false]);
    }

    // ── One user ─────────────────────────────────────────────────────────────

    public function show(User $user): JsonResponse
    {
        $companyIds = $this->manageableCompanyIds();
        if (!$companyIds || !$this->isManageableTarget($user, $companyIds)) {
            return ApiResponse::error('Not found', 404);
        }
        // Refused rather than returned read-only: the only screen that reads
        // this is Edit User, and loading it for someone nothing can be saved
        // on is a dead end. The list already carries their name, which is all
        // a manager gets to see of a peer.
        if ($refusal = $this->refusePeerManager($user, $companyIds)) {
            return $refusal;
        }

        return ApiResponse::success(new UserResource($this->loadScoped($user, $companyIds)));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $companyIds = $this->manageableCompanyIds();
        if (!$companyIds || !$this->isManageableTarget($user, $companyIds)) {
            return ApiResponse::error('Not found', 404);
        }
        if ($refusal = $this->refuseSelf($user, 'edit')) {
            return $refusal;
        }
        if ($refusal = $this->refusePeerManager($user, $companyIds)) {
            return $refusal;
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
            'role_type'         => ['sometimes', 'string', 'in:' . implode(',', $this->validRoles())],
            'roles'             => ['sometimes', 'array'],
            'roles.*'           => ['string', 'in:' . implode(',', $this->validRoles())],
            'custom_role_label' => ['nullable', 'string', 'max:100'],
            'phone'             => ['nullable', 'string', 'max:30', new ValidPhoneNumber],
        ]);

        if (empty($validated['password'])) unset($validated['password']);

        // Roles live in their own table, not on the users row — pull them out
        // before the mass-assign below so 'roles' is never handed to update().
        $roles = UserCompanyRoleService::resolveFromPayload(
            [],
            $validated['roles'] ?? null,
            $validated['role_type'] ?? null
        );
        unset($validated['roles']);

        // Resolved BEFORE the update: roles are company-scoped, and this edit
        // may change the row resolveCompanyId() reads.
        $companyId = $this->resolveCompanyId($user, null, $companyIds);

        // The primary role is whatever came first in the selection, so editing
        // roles keeps users.role_type in step with user_company_roles rather
        // than letting the legacy column drift out of date.
        if ($roles !== []) {
            $validated['role_type'] = $roles[0];
        }

        // is_active is deliberately NOT accepted here: account status is a
        // per-company decision and only toggleStatus() below may make it, so
        // an edit can never quietly suspend someone everywhere at once.
        $oldRoleType = $user->role_type;
        $user->update($validated);

        UserCompanyRoleService::sync($user, $companyId, $roles);

        if (isset($validated['role_type']) && $validated['role_type'] !== $oldRoleType) {
            $this->logAudit($request, $companyId, 'user.role_changed', $user->id, ['role_type' => $oldRoleType], ['role_type' => $validated['role_type']]);
        } else {
            $this->logAudit($request, $companyId, 'user.updated', $user->id, [], collect($validated)->except('password')->toArray());
        }

        return ApiResponse::success(new UserResource($this->loadScoped($user, $companyIds)), 'User updated');
    }

    // ── Suspend / activate (per company) ─────────────────────────────────────

    public function toggleStatus(Request $request, User $user): JsonResponse
    {
        $companyIds = $this->manageableCompanyIds();
        if (!$companyIds || !$this->isManageableTarget($user, $companyIds)) {
            return ApiResponse::error('Not found', 404);
        }
        if ($refusal = $this->refuseSelf($user, 'suspend')) {
            return $refusal;
        }
        if ($refusal = $this->refusePeerManager($user, $companyIds)) {
            return $refusal;
        }
        if ($user->status === 'invited') {
            return ApiResponse::error('Cannot suspend a pending invite. Remove the user instead.', 422);
        }

        $validated = $request->validate([
            'status'     => ['required', 'in:active,suspended'],
            'company_id' => ['nullable', 'integer'],
        ]);

        $companyId = $this->resolveCompanyId($user, $validated['company_id'] ?? null, $companyIds);
        if (!$companyId) {
            return ApiResponse::error('No company found for this user', 404);
        }

        // Reactivating only costs a seat when this person isn't already
        // counted through another active assignment — same rule (and same
        // dedupe-by-user_id count) as Api\Admin\UserController::toggleStatus().
        if ($validated['status'] === 'active') {
            $countedElsewhere = CompanyUserAssignment::where('user_id', $user->id)
                ->where('company_id', '!=', $companyId)
                ->where('status', 'active')
                ->exists();

            if (!$countedElsewhere) {
                $adminId = Company::find($companyId)?->admin_id;
                $limit = $adminId ? $this->orgUserLimit($adminId) : null;
                $used  = $adminId ? $this->orgUserCount($adminId) : 0;
                if ($limit !== null && $used >= $limit) {
                    return ApiResponse::error($this->seatLimitMessage($used, $limit), 422);
                }
            }
        }

        // This company's membership only — the same person keeps working
        // normally in every other company they belong to, including ones this
        // manager can't see.
        CompanyUserAssignment::where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->update(['status' => $validated['status']]);

        $anyActive = CompanyUserAssignment::where('user_id', $user->id)->where('status', 'active')->exists();

        $update = [
            'is_active' => $anyActive,
            'status'    => $anyActive ? 'active' : 'suspended',
        ];

        // users.company_id is what every permission check reads. If the
        // company just suspended was it, repoint to one they're still active
        // in so the account keeps working there.
        if ($validated['status'] === 'suspended' && $user->company_id === $companyId) {
            $reassignTo = CompanyUserAssignment::where('user_id', $user->id)
                ->where('company_id', '!=', $companyId)
                ->where('status', 'active')
                ->value('company_id');
            if ($reassignTo) $update['company_id'] = $reassignTo;
        }

        $user->update($update);

        $this->logAudit(
            $request, $companyId,
            $validated['status'] === 'active' ? 'user.activated' : 'user.deactivated',
            $user->id, [], ['status' => $validated['status']]
        );

        return ApiResponse::success(new UserResource($this->loadScoped($user, $companyIds)), 'Status updated');
    }

    // ── Remove from a company ────────────────────────────────────────────────
    //
    // Deliberately an UNASSIGN, never an account deletion: the person's
    // projects, invoices and audit trail must survive, and a delegated
    // manager has no business destroying a login that may still belong to
    // companies they cannot see. Permanent deletion stays Company Admin-only
    // (Api\Admin\UserController::destroyPermanently()).
    public function destroy(Request $request, User $user): JsonResponse
    {
        $companyIds = $this->manageableCompanyIds();
        if (!$companyIds || !$this->isManageableTarget($user, $companyIds)) {
            return ApiResponse::error('Not found', 404);
        }
        if ($refusal = $this->refuseSelf($user, 'remove')) {
            return $refusal;
        }
        if ($refusal = $this->refusePeerManager($user, $companyIds)) {
            return $refusal;
        }

        $validated = $request->validate(['company_id' => ['nullable', 'integer']]);
        $companyId = $this->resolveCompanyId($user, $validated['company_id'] ?? null, $companyIds);
        if (!$companyId) {
            return ApiResponse::error('No company found for this user', 404);
        }

        CompanyUserAssignment::where('user_id', $user->id)->where('company_id', $companyId)->delete();
        UserCompanyPermission::where('user_id', $user->id)->where('company_id', $companyId)->delete();

        $remaining = CompanyUserAssignment::where('user_id', $user->id)->first();
        $update = [];

        if ($remaining && $user->company_id === $companyId) {
            $update['company_id'] = CompanyUserAssignment::where('user_id', $user->id)
                ->where('status', 'active')
                ->value('company_id') ?? $remaining->company_id;
        }

        $anyActive = CompanyUserAssignment::where('user_id', $user->id)->where('status', 'active')->exists();
        if (!$anyActive && $user->status !== 'invited') {
            $update['is_active'] = false;
            $update['status'] = 'suspended';
        }

        if ($update) $user->update($update);

        $this->logAudit($request, $companyId, 'user.removed_from_company', $user->id, [], ['email' => $user->email]);

        return ApiResponse::success(null, 'User removed');
    }

    // ── Deputy-only: invite instead of setting a password ────────────────────
    //
    // Mirrors Api\Admin\UserController::invite(). Same three outcomes for the
    // same three states of the email — brand new (create + token), dormant
    // everywhere (re-invite with a fresh token), still active in another
    // company (link only, account untouched) — so a deputy's invites behave
    // exactly like the owner's.
    //
    // Unlike the admin version this does NOT re-apply role defaults over the
    // payload: the wizard already pre-ticks from roleDefaults() and store()
    // here has always written what it was sent, so filling buckets in again
    // would make invite and create disagree with each other.
    public function invite(Request $request): JsonResponse
    {
        if ($refusal = $this->refuseUnlessDeputy()) {
            return $refusal;
        }

        $me = $this->user();
        $companyIds = $this->manageableCompanyIds();
        if (!$companyIds) {
            return ApiResponse::error('Permission denied', 403);
        }

        $validated = $request->validate([
            'name'                              => ['required', 'string', 'max:150'],
            'email'                             => ['required', 'email'],
            'role_type'                         => ['nullable', 'string', 'in:' . implode(',', $this->validRoles())],
            'roles'                             => ['nullable', 'array'],
            'roles.*'                           => ['string', 'in:' . implode(',', $this->validRoles())],
            'custom_role_label'                 => ['nullable', 'string', 'max:100'],
            'company_assignments'               => ['nullable', 'array'],
            'company_assignments.*.company_id'  => ['required', 'integer', 'in:' . implode(',', $companyIds)],
            'company_assignments.*.permissions' => ['nullable', 'array'],
            'company_assignments.*.data_scopes' => ['nullable', 'array'],
            'company_assignments.*.roles'       => ['nullable', 'array'],
            'company_assignments.*.roles.*'     => ['string', 'in:' . implode(',', $this->validRoles())],
        ]);

        $assignments = $validated['company_assignments'] ?? [];
        if (!$assignments) {
            $assignments = [[
                'company_id'  => in_array($me->company_id, $companyIds, true) ? $me->company_id : $companyIds[0],
                'permissions' => [],
            ]];
        }

        $companyId = (int) $assignments[0]['company_id'];
        $adminId   = Company::findOrFail($companyId)->admin_id;

        // withTrashed(): see the matching comment in store() — the unique
        // index still holds this email even after a soft delete.
        $existingUser = User::withTrashed()->where('email', $validated['email'])->first();

        if ($existingUser) {
            if ($existingUser->trashed()) {
                $existingUser->restore();
            }

            foreach ($assignments as $a) {
                $alreadyLinked = CompanyUserAssignment::where('user_id', $existingUser->id)
                    ->where('company_id', $a['company_id'])
                    ->exists();
                if ($alreadyLinked) {
                    return ApiResponse::error('This user is already added to this company.', 422);
                }
            }

            $limit = $this->orgUserLimit($adminId);
            $used  = $this->orgUserCount($adminId);
            if ($limit !== null && $used >= $limit) {
                return ApiResponse::error($this->seatLimitMessage($used, $limit), 422);
            }

            $reonboarded = $this->reonboardIfDormant($existingUser, $validated, asInvite: true);

            foreach ($assignments as $a) {
                $this->grantAssignment(
                    $existingUser,
                    (int) $a['company_id'],
                    $a['permissions'] ?? [],
                    UserCompanyRoleService::resolveFromPayload($a, $validated['roles'] ?? null, $validated['role_type'] ?? null)
                );

                $this->logAudit(
                    $request,
                    (int) $a['company_id'],
                    $reonboarded ? 'user.reinvited' : 'user.linked_to_company',
                    $existingUser->id,
                    [],
                    ['email' => $existingUser->email]
                );
            }

            if ($reonboarded) {
                $this->sendInviteEmail($existingUser, $companyId);
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

        if (CrossAccountEmail::existsAsAdmin($validated['email'])) {
            return ApiResponse::error('This email is already registered as a Company Admin account.', 422);
        }

        $limit = $this->orgUserLimit($adminId);
        $used  = $this->orgUserCount($adminId);
        if ($limit !== null && $used >= $limit) {
            return ApiResponse::error($this->seatLimitMessage($used, $limit), 422);
        }

        $user = User::create([
            'company_id'           => $companyId,
            'name'                 => $validated['name'],
            'email'                => $validated['email'],
            // Unguessable placeholder — the invite link is how they get in,
            // and must_change_password makes them set their own.
            'password'             => Hash::make(Str::random(32)),
            // Primary role for the account's own company — the rest of the
            // selection lands in user_company_roles via grantAssignment().
            'role_type'            => UserCompanyRoleService::resolveFromPayload(
                $assignments[0],
                $validated['roles'] ?? null,
                $validated['role_type'] ?? null
            )[0] ?? 'seller',
            'custom_role_label'    => $validated['custom_role_label'] ?? null,
            'created_by'           => $me->id,
            'is_active'            => false,
            'status'               => 'invited',
            'invite_token'         => Str::random(64),
            'invite_expires_at'    => now()->addDays(7),
            'must_change_password' => true,
        ]);

        foreach ($assignments as $a) {
            $this->grantAssignment(
                $user,
                (int) $a['company_id'],
                $a['permissions'] ?? [],
                UserCompanyRoleService::resolveFromPayload($a, $validated['roles'] ?? null, $validated['role_type'] ?? null)
            );
            $this->logAudit($request, (int) $a['company_id'], 'user.invited', $user->id, [], ['name' => $user->name, 'email' => $user->email]);
        }

        $this->sendInviteEmail($user, $companyId);

        return ApiResponse::success(
            new UserResource($user->load(['companyAssignments.company:id,name,is_active,suspended_at', 'userCompanyPermissions', 'companyRoles', 'company:id,name'])),
            'Invite created',
            201
        );
    }

    public function resendInvite(Request $request, User $user): JsonResponse
    {
        $companyIds = $this->manageableCompanyIds();
        if ($refusal = $this->refuseDeputyTarget($user, $companyIds, 'resend an invite for')) {
            return $refusal;
        }
        if ($user->status !== 'invited') {
            return ApiResponse::error('User has already accepted the invite', 422);
        }

        $user->update([
            'invite_token'      => Str::random(64),
            'invite_expires_at' => now()->addDays(7),
        ]);

        $companyId = $this->resolveCompanyId($user, null, $companyIds);
        $this->logAudit($request, $companyId, 'invite.sent', $user->id);
        if ($companyId) {
            $this->sendInviteEmail($user, $companyId);
        }

        return ApiResponse::success(
            new UserResource($this->loadScoped($user, $companyIds)),
            'Invite link refreshed'
        );
    }

    // Shows the new password once, exactly like the admin route — nothing is
    // emailed, the deputy hands it over themselves.
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $companyIds = $this->manageableCompanyIds();
        if ($refusal = $this->refuseDeputyTarget($user, $companyIds, 'reset the password on')) {
            return $refusal;
        }
        if ($user->status === 'invited') {
            return ApiResponse::error('User has not accepted their invite yet — resend the invite instead.', 422);
        }

        $plain = Str::random(12);
        $user->update([
            'password'             => Hash::make($plain),
            'must_change_password' => true,
        ]);

        $this->logAudit($request, $this->resolveCompanyId($user, null, $companyIds), 'user.password_reset_sent', $user->id);

        return ApiResponse::success(
            ['password' => $plain],
            'Password reset — share this with the user once, it will not be shown again'
        );
    }

    // ── Deputy-only: the deletion Impact Summary and its reassign step ───────
    //
    // All three delegate to UserDeletionService, the same one the admin routes
    // use, so what a deputy is shown and what it may hand over is identical —
    // only the company scope differs (manageable companies, not owned ones).

    public function companyWorkload(User $user): JsonResponse
    {
        $companyIds = $this->manageableCompanyIds();
        if ($refusal = $this->refuseDeputyTarget($user, $companyIds, 'review')) {
            return $refusal;
        }

        return ApiResponse::success(UserDeletionService::companyWorkload($user, $companyIds));
    }

    public function deleteImpact(Request $request, User $user): JsonResponse
    {
        $companyIds = $this->manageableCompanyIds();
        if ($refusal = $this->refuseDeputyTarget($user, $companyIds, 'review')) {
            return $refusal;
        }

        $companyId = $this->resolveCompanyId($user, $request->integer('company_id') ?: null, $companyIds);
        if (!$companyId) {
            return ApiResponse::error('No company found for this user', 404);
        }

        return ApiResponse::success(UserDeletionService::impact($user, $companyId));
    }

    public function reassignWorkload(Request $request, User $user): JsonResponse
    {
        $companyIds = $this->manageableCompanyIds();
        if ($refusal = $this->refuseDeputyTarget($user, $companyIds, 'reassign work from')) {
            return $refusal;
        }

        $companyId = $this->resolveCompanyId($user, $request->integer('company_id') ?: null, $companyIds);
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

        // The actor IS a users row here, unlike the admin route where a
        // CompanyAdmin has no id to record — so the service's own audit rows
        // name the deputy rather than coming back with user_id null.
        $moved = UserDeletionService::reassign($user, $companyId, $targets, $this->user()->id);

        $this->logAudit($request, $companyId, 'user.workload_reassigned', $user->id, [], $moved);

        return ApiResponse::success(
            ['moved' => $moved, 'impact' => UserDeletionService::impact($user, $companyId)],
            'Records reassigned',
        );
    }

    // Irreversible, and refused for the same two reasons as the admin route:
    // the account belongs to another organisation as well, or it still holds
    // active work and force wasn't passed after being shown it.
    public function destroyPermanently(Request $request, User $user): JsonResponse
    {
        $companyIds = $this->manageableCompanyIds();
        if ($refusal = $this->refuseDeputyTarget($user, $companyIds, 'permanently delete')) {
            return $refusal;
        }

        $validated = $request->validate([
            'company_id' => ['nullable', 'integer'],
            'force'      => ['nullable', 'boolean'],
        ]);

        $companyId = $this->resolveCompanyId($user, $validated['company_id'] ?? null, $companyIds);
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
        // Actor id recorded for the same reason as in reassignWorkload().
        UserDeletionService::purge($user, $companyId, $this->user()->id);

        $this->logAudit($request, $companyId, 'user.permanently_deleted', $user->id, [], ['email' => $email]);

        return ApiResponse::success(null, 'User account permanently deleted');
    }

    // ── Deputy-only: per-user activity, read-only ───────────────────────────
    //
    // Same shape as Api\Admin\UserController::activity(), scoped to one of the
    // deputy's own companies. Everything is filtered by that company, so a
    // project or timesheet from a company they don't manage never appears.
    public function activity(Request $request, User $user): JsonResponse
    {
        $companyIds = $this->manageableCompanyIds();
        if ($refusal = $this->refuseDeputyTarget($user, $companyIds, 'view activity for')) {
            return $refusal;
        }

        $companyId = $this->resolveCompanyId($user, $request->integer('company_id') ?: null, $companyIds);
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
                // Company Admin actions about this user are logged with
                // user_id=null (Admin isn't a `users` row) — surface those by
                // entity instead.
                $q->where('entity_type', 'User')->where('entity_id', $user->id)->where('company_id', $companyId);
            })
            ->orderByDesc('created_at')
            ->limit(30)
            ->get(['id', 'action', 'module_key', 'entity_type', 'entity_id', 'created_at']);

        return ApiResponse::success($result);
    }

    // ── Invite helpers ──────────────────────────────────────────────────────

    // The invited-by name is the deputy's own, where the admin version uses
    // the Company Admin's — otherwise the recipient is told the owner invited
    // them when they didn't.
    private function sendInviteEmail(User $user, int $companyId): void
    {
        $user = $user->fresh();
        if (!$user?->invite_token) return;

        EventMailService::userInvited(
            $user,
            FrontendUrl::to('invite/' . $user->invite_token),
            $companyId,
            $this->user()->name ?? 'Your administrator',
        );
    }

    private function sendCompanyAssignmentEmails(User $user, array $assignments): void
    {
        foreach ($assignments as $a) {
            EventMailService::companyAssigned($user, (int) $a['company_id'], $this->user()->name ?? 'Your administrator');
        }
    }

    // An account with no ACTIVE assignment anywhere is dormant: re-inviting it
    // starts the account over rather than silently linking a shell nobody can
    // log into. One that is still active elsewhere is left completely alone —
    // its name, role and password belong to that other company's copy of this
    // person. Mirrors Api\Admin\UserController::reonboardIfDormant().
    private function reonboardIfDormant(User $user, array $validated, bool $asInvite = false): bool
    {
        $activeElsewhere = CompanyUserAssignment::where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        if ($activeElsewhere) {
            return false;
        }

        $updates = [
            'name'              => $validated['name'],
            // Prefer the multi-role selection's primary over the legacy field,
            // so a reonboarded user's headline role matches the roles that
            // grantAssignment() is about to write for them.
            'role_type'         => UserCompanyRoleService::resolveFromPayload(
                [],
                $validated['roles'] ?? null,
                $validated['role_type'] ?? null
            )[0] ?? $user->role_type,
            'custom_role_label' => $validated['custom_role_label'] ?? null,
        ];

        if ($asInvite) {
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

    // ── Permissions for one company ──────────────────────────────────────────

    public function getCompanyPermissions(User $user, int $companyId): JsonResponse
    {
        $companyIds = $this->manageableCompanyIds();
        if (!$companyIds || !in_array($companyId, $companyIds, true) || !$this->isManageableTarget($user, $companyIds)) {
            return ApiResponse::error('Not found', 404);
        }

        if ($refusal = $this->refusePeerManager($user, $companyIds)) {
            return $refusal;
        }

        $rows = UserCompanyPermission::where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->get();

        return ApiResponse::success([
            'permissions' => $rows->groupBy('module_key')->map(fn ($g) => $g->pluck('permission_key')->all())->toArray(),
            'data_scopes' => $rows->groupBy('module_key')->map(fn ($g) => $g->first()->data_scope)->filter()->toArray(),
        ]);
    }

    // Assign Company + Manage Permissions in one call, exactly like the admin
    // endpoint it mirrors: writing permissions for a company the user isn't in
    // yet creates the membership, which is what "Assign Company" means here.
    //
    // Two rules make this safe to delegate:
    //   1. Only within a company the manager holds the grant in.
    //   2. The manager can only ever toggle keys they hold THEMSELVES. Keys
    //      they don't hold are neither granted nor revoked — whatever the
    //      Company Admin gave this person outside the manager's own set is
    //      carried over untouched, so a manager can't strip access they were
    //      never trusted with either.
    public function updateCompanyPermissions(Request $request, User $user, int $companyId): JsonResponse
    {
        $companyIds = $this->manageableCompanyIds();
        if (!$companyIds || !in_array($companyId, $companyIds, true)) {
            return ApiResponse::error('Not found', 404);
        }
        // Not isManageableTarget(): assigning a NEW company to someone the
        // manager already shares another company with is the whole point, so
        // the target only has to be reachable somewhere in their scope.
        if (!$this->isManageableTarget($user, $companyIds)) {
            return ApiResponse::error('Not found', 404);
        }
        if ($refusal = $this->refuseSelf($user, 'change permissions on')) {
            return $refusal;
        }
        if ($refusal = $this->refusePeerManager($user, $companyIds)) {
            return $refusal;
        }

        $validated = $request->validate([
            'permissions'   => ['required', 'array'],
            'data_scopes'   => ['nullable', 'array'],
            'data_scopes.*' => ['nullable', 'string', 'in:own,assigned,all,view_only,no_access'],
            // Roles for THIS company only — see the matching block in
            // Api\Admin\UserController::updateCompanyPermissions().
            'roles'         => ['nullable', 'array'],
            'roles.*'       => ['string', 'in:' . implode(',', $this->validRoles())],
        ]);

        $requested  = $validated['permissions'];
        $dataScopes = $validated['data_scopes'] ?? [];

        $roles = UserCompanyRoleService::sync($user, $companyId, $validated['roles'] ?? []);

        // Only the account's own primary company may move the legacy column,
        // so editing a secondary company never rewrites the headline role.
        if ($roles !== [] && (int) $user->company_id === $companyId && $user->role_type !== $roles[0]) {
            $user->update(['role_type' => $roles[0]]);
        }

        $existing = UserCompanyPermission::where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->get()
            ->groupBy('module_key')
            ->map(fn ($g) => $g->pluck('permission_key')->all())
            ->toArray();

        // The screen is the Company Admin's own permission editor showing the
        // whole catalog, so what comes back IS the new set — no ceiling, no
        // silent dropping of boxes the manager ticked.
        $final = [];
        foreach ($requested as $moduleKey => $permKeys) {
            $keys = $this->grantableKeys((array) $permKeys);
            if ($keys) $final[$moduleKey] = $keys;
        }

        // NEVER_GRANTABLE is the one thing a delegated manager's save neither
        // adds nor removes: whatever the Company Admin granted stays exactly
        // as it was. (In practice refusePeerManager() above has already turned
        // such a request away — this is the belt to that braces.)
        //
        // Skipped for a deputy, who may hand the User Management Permission
        // out: preserving it here would make the box one-way — grantable but
        // never revocable, silently re-added every time they unticked it.
        if (!$this->isDeputyAdmin()) {
            foreach ($existing as $moduleKey => $permKeys) {
                $kept = array_values(array_intersect($permKeys, self::NEVER_GRANTABLE));
                if ($kept) {
                    $final[$moduleKey] = array_values(array_unique(array_merge($final[$moduleKey] ?? [], $kept)));
                }
            }
        }

        $assignment = CompanyUserAssignment::firstOrCreate(
            ['user_id' => $user->id, 'company_id' => $companyId],
            ['assigned_by' => null, 'status' => 'active']
        );

        UserCompanyPermission::where('company_user_id', $assignment->id)->delete();
        UserCompanyPermission::where('user_id', $user->id)->where('company_id', $companyId)->delete();

        foreach ($final as $moduleKey => $permKeys) {
            foreach ($permKeys as $permKey) {
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

        $this->logAudit($request, $companyId, 'user.permissions_updated', $user->id, [], ['permissions' => $final]);

        return ApiResponse::success([
            'permissions' => $final,
            'data_scopes' => $dataScopes,
        ], 'Permissions updated');
    }
}
