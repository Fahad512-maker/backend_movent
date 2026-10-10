<?php

namespace App\Http\Resources;

use App\Models\Module;
use App\Support\ActiveCompany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Support\PublicFile;

class AdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'name'                  => $this->name,
            'email'                 => $this->email,
            'phone'                 => $this->phone,
            'avatar_url'            => PublicFile::url($this->avatar_path),
            // Raw stored column — kept for reference/audit, but the frontend
            // should read effective_subscription_status below instead: the
            // stored value can lag behind by up to a day if the daily
            // subscription:check-lifecycle cron hasn't run yet (see
            // CompanyAdmin::effectiveSubscriptionStatus()).
            'subscription_status'   => $this->subscription_status,
            'effective_subscription_status' => $this->effectiveSubscriptionStatus(),
            'trial_ends_at'         => $this->trial_ends_at?->toIso8601String(),
            // Server-computed (not left to the frontend to derive from
            // trial_ends_at) so it matches CheckSubscriptionLifecycle's own
            // clamped/floored day count exactly — this rides the existing
            // /admin/me poll DashboardLayout already runs every 60s, so the
            // global trial-countdown bar needs no extra API call.
            'trial_days_remaining'  => $this->subscription_status === 'trial' ? $this->trialDaysRemaining() : null,
            'grace_days_remaining'  => $this->effectiveSubscriptionStatus() === 'grace_period' ? $this->graceDaysRemaining() : null,
            'subscription_ends_at'  => $this->subscription_ends_at?->toIso8601String(),
            'is_active'             => $this->is_active,
            // Set on My Profile (Api\Admin\ProfileController::update()) —
            // authoritative for every invoice this admin issues (see
            // Company::invoicingProfile()).
            'currency'              => $this->currency,
            // Raw per-admin override columns (null = use package default /
            // unlimited) — lets the frontend reflect a seat/company upgrade
            // purchase immediately via the same setAuthData()/auth_refreshed
            // pattern already used for module purchases.
            'max_users_per_company' => $this->max_users_per_company,
            'max_companies'         => $this->max_companies,
            'package'               => $this->whenLoaded('package', function () {
                return [
                    'name'                  => $this->package->name,
                    'tier'                  => $this->package->tier,
                    'max_companies'         => $this->package->max_companies,
                    'max_users_per_company' => $this->package->max_users_per_company,
                ];
            }),
            'companies'             => $this->whenLoaded('companies', function () {
                return $this->companies->map(fn ($c) => [
                    'id'        => $c->id,
                    'name'      => $c->name,
                    'is_active' => $c->is_active,
                ]);
            }),
            // The modules of the company picked in the Company Selector (every
            // owned company's, merged, in "All Companies" mode) — resolved by
            // the same ActiveCompany::adminCompanyIds() CheckCompanyModule
            // gates on, so the sidebar shows exactly what the API will allow.
            'modules'               => $this->whenLoaded('companies', function () use ($request) {
                $activeIds = ActiveCompany::adminCompanyIds($request, $this->resource);
                $companies = $this->companies->whereIn('id', $activeIds);
                if ($companies->isEmpty() || !$companies->first()->relationLoaded('modules')) return [];

                // Exclude granular keys whose parent catalog module has been
                // deactivated by the Super Admin — CheckCompanyModule already
                // blocks the underlying API, so keep the sidebar/UI in sync.
                $disabledKeys = Module::disabledSubModuleKeys();

                return $companies
                    ->flatMap(fn ($c) => $c->modules->where('is_enabled', true)->pluck('module_key'))
                    ->unique()
                    ->reject(fn ($key) => in_array($key, $disabledKeys, true))
                    ->values()
                    ->toArray();
            }),
        ];
    }
}
