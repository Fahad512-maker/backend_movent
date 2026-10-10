<?php

namespace App\Http\Middleware;

use App\Helpers\ApiResponse;
use App\Models\CompanyAdmin;
use App\Models\CompanyModule;
use App\Models\Module;
use App\Support\ActiveCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckCompanyModule
{
    /**
     * Usage: middleware('module:leads')
     *
     * Supports both CompanyAdmin (has companies() HasMany) and User (has company_id).
     */
    public function handle(Request $request, Closure $next, string $moduleKey): Response
    {
        $user = $request->user();

        if (!$user) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $disabledGlobally = Module::where('is_active', false)
            ->whereJsonContains('sub_modules', $moduleKey)
            ->exists();

        if ($disabledGlobally) {
            return ApiResponse::error("Module '{$moduleKey}' has been disabled by the administrator.", 403);
        }

        if ($user instanceof CompanyAdmin) {
            // The company the admin is working in (Company Selector), not the
            // union of every company they own — otherwise Company A holding a
            // module opened it for Company B too. In "All Companies" mode this
            // is every owned company, so the gate passes when any of them
            // holds the module; controllers that return per-company data in
            // that mode (e.g. Finance\FinanceContext) narrow it further.
            $companyIds = ActiveCompany::adminCompanyIds($request, $user);
            if (empty($companyIds)) {
                return ApiResponse::error('No company associated with this account.', 403);
            }
            $enabled = CompanyModule::whereIn('company_id', $companyIds)
                ->where('module_key', $moduleKey)
                ->where('is_enabled', true)
                ->exists();
        } else {
            $companyId = ActiveCompany::resolve($request, $user);

            if (!$companyId) {
                return ApiResponse::error('No company assigned to your account. Contact your admin.', 403);
            }
            $enabled = CompanyModule::where('company_id', $companyId)
                ->where('module_key', $moduleKey)
                ->where('is_enabled', true)
                ->exists();
        }

        if (!$enabled) {
            return ApiResponse::error("Module '{$moduleKey}' is not enabled for your company.", 403);
        }

        return $next($request);
    }
}
