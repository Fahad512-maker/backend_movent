<?php

namespace App\Http\Middleware;

use App\Helpers\ApiResponse;
use App\Models\Company;
use App\Models\CompanyAdmin;
use App\Models\User;
use App\Support\ActiveCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Enforces a manual, per-Company suspension (Company::isOperational() — see
// that model for how it's kept distinct from the tenant-wide subscription
// block EnsureCompanySubscriptionActive/EnsureSubscriptionActive already
// enforce). Deliberately its own middleware rather than folded into those:
// this is a DIFFERENT restriction with a different resolution (reactivate
// the company vs. renew a subscription), and the two must be able to compose
// without either one masking the other's message.
//
// Staff (User, guard sanctum): always resolves to exactly one company (the
// one 'sync.active.company' already picked), so this blocks outright.
//
// Company Admin (guard admin): only blocks when the request's active-company
// selection resolves to a SINGLE specific company (the common case, and the
// only case a suspension is meaningful for) — a multi-company Admin viewing
// "All Companies" is deliberately left unblocked here (see
// ActiveCompany::adminCompanyIds()); an aggregate dashboard/list still
// includes a suspended company's data, which is an accepted trade-off rather
// than locking the whole aggregate view over one suspended company (Company A
// being suspended must not affect Company B — routes/api.php exempts the
// companies management endpoints themselves so the Admin can always reach
// Reactivate regardless of which mode they're in).
class EnsureCompanyNotSuspended
{
    private const MESSAGE = 'This company is currently suspended. Please contact your Company Admin.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $company = Company::find($user->company_id);

            if ($company && !$company->isOperational()) {
                return $this->blocked();
            }
        }

        $admin = $request->user('admin');

        if ($admin instanceof CompanyAdmin) {
            $ids = ActiveCompany::adminCompanyIds($request, $admin);

            if (count($ids) === 1) {
                $company = Company::find($ids[0]);

                if ($company && !$company->isOperational()) {
                    return $this->blocked();
                }
            }
        }

        return $next($request);
    }

    private function blocked(): Response
    {
        return ApiResponse::error(self::MESSAGE, 403, ['error_code' => 'company_suspended']);
    }
}
