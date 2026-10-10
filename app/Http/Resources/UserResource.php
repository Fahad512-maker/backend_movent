<?php

namespace App\Http\Resources;

use App\Models\Module;
use App\Support\FrontendUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Support\PublicFile;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $frontendUrl = FrontendUrl::base();

        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'email'       => $this->email,
            'role_type'         => $this->role_type,
            // Display-only override for a "Custom Role" — see
            // 2026_08_18_100000_add_custom_role_label_to_users_table.php.
            // role_type above still carries the real permission/behavior
            // bucket; the frontend shows this label instead when set.
            'custom_role_label' => $this->custom_role_label,
            'phone'       => $this->phone,
            'avatar_path' => $this->avatar_path,
            'avatar_url'  => PublicFile::url($this->avatar_path),
            'is_active'     => (bool) $this->is_active,
            'is_online'     => $this->is_online,
            'status'        => $this->status ?? 'active',
            'last_login_at' => $this->last_login_at,
            'created_at'    => $this->created_at,
            'created_by'    => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id'   => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null),
            'invite_url'  => $this->invite_token
                ? $frontendUrl . '/invite/' . $this->invite_token
                : null,

            'company' => $this->whenLoaded('company', fn () => [
                'id'       => $this->company->id,
                'name'     => $this->company->name,
                'currency' => $this->company->currency ?? 'USD',
                // On /user/me this is the ACTIVE company's list —
                // SyncActiveCompanyContext has already pointed company_id at
                // it. Keys whose catalog module the Super Admin deactivated
                // are dropped, exactly as AdminResource does, because
                // CheckCompanyModule refuses them on the API.
                'modules'  => $this->company->relationLoaded('modules')
                    ? $this->company->modules
                        ->where('is_enabled', true)
                        ->pluck('module_key')
                        ->diff(Module::disabledSubModuleKeys())
                        ->values()
                    : null,
                'admin'    => $this->company->relationLoaded('admin') ? [
                    'id'       => $this->company->admin?->id,
                    'name'     => $this->company->admin?->name,
                    // The tenant's Settings-configured currency — authoritative
                    // for invoice creation (see Company::invoicingProfile()),
                    // unlike the sibling `company.currency` above (legacy,
                    // pre-tenant-refactor, per-Company column).
                    'currency' => $this->company->admin?->currency,
                ] : null,
            ]),

            'company_assignments' => $this->whenLoaded('companyAssignments', function () {
                $allPerms = $this->relationLoaded('userCompanyPermissions')
                    ? $this->userCompanyPermissions
                    : collect();

                // Roles this user holds, per company. Absent (not empty) when
                // the relation was not eager-loaded, so a screen that never
                // asked for roles can tell "none" from "not fetched".
                $allRoles = $this->relationLoaded('companyRoles')
                    ? $this->companyRoles
                    : null;

                return $this->companyAssignments->map(function ($assignment) use ($allPerms, $allRoles) {
                    $rowsForCompany = $allPerms->where('company_id', $assignment->company_id);

                    $perms = $rowsForCompany
                        ->groupBy('module_key')
                        ->map(fn ($g) => $g->pluck('permission_key')->toArray())
                        ->toArray();

                    // Descriptive-only (like role_type) — never used to filter query results.
                    $dataScopes = $rowsForCompany
                        ->groupBy('module_key')
                        ->map(fn ($g) => $g->first()->data_scope)
                        ->filter()
                        ->toArray();

                    // A company-level suspension (Company::isOperational(),
                    // manual or Super-Admin-deactivated) must gray this
                    // company out here too, same as an 'active' assignment
                    // whose own status was already 'suspended' — reusing that
                    // exact status string is what makes /select-company's
                    // existing "· Suspended" label and disabled state apply
                    // to it with no separate frontend change.
                    $companyOperational = !$assignment->company || $assignment->company->isOperational();

                    return [
                        'company_id'   => $assignment->company_id,
                        'company_name' => $assignment->company?->name,
                        'status'       => ($assignment->status === 'active' && !$companyOperational) ? 'suspended' : $assignment->status,
                        'permissions'  => $perms,
                        'data_scopes'  => $dataScopes,
                        // Primary first, so roles[0] is the headline role the
                        // roster shows and the rest read as extras.
                        'roles'        => $allRoles === null ? null : $allRoles
                            ->where('company_id', $assignment->company_id)
                            ->sortByDesc('is_primary')
                            ->pluck('role')
                            ->unique()
                            ->values()
                            ->all(),
                    ];
                });
            }),

            'permissions' => $this->whenLoaded('permissions', function () {
                return $this->permissions->groupBy('module_key')
                    ->map(fn ($perms) => $perms->first()->only([
                        'module_key', 'can_view', 'can_create', 'can_edit', 'can_delete', 'can_export',
                    ]))
                    ->values();
            }),
        ];
    }
}
