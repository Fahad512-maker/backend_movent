<?php

namespace App\Http\Middleware;

use App\Helpers\ApiResponse;
use App\Support\ActiveCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Same "must have an active company assignment" gate as CheckCompanyModule,
// for User-side routes that aren't tied to a single purchasable module key
// (Clients/Accounts, add-a-user) — see routes/api.php's "NOT wrapped in
// `module:...`" comments on those groups for why they can't just use
// `module:clients`. Unlike CheckCompanyModule this never checks module
// enablement, only that the user currently has an active company.
class RequireActiveCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !ActiveCompany::resolve($request, $user)) {
            return ApiResponse::error('No company assigned to your account. Contact your admin.', 403);
        }

        return $next($request);
    }
}
