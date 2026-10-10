<?php

namespace App\Http\Controllers\Api\Client;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientPortalPermission;
use App\Models\User;
use App\Services\ClientPortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)
            ->where('role_type', 'client')
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return ApiResponse::error('Invalid credentials', 401);
        }

        if (!$user->is_active) {
            return ApiResponse::error('Your account has been deactivated', 403);
        }

        // Prefer a client record with portal_access enabled; fall back to the first one
        $client = Client::where('user_id', $user->id)
            ->where('portal_access', true)
            ->first()
            ?? Client::where('user_id', $user->id)->first();

        if (!$client) {
            return ApiResponse::error('No client profile found', 404);
        }

        if (!$client->portal_access) {
            return ApiResponse::error('Access disabled. Contact support.', 403);
        }

        // Super Admin's platform-level kill switch for the whole tenant — no
        // self-service remedy, so it blocks every one of this company's
        // client-portal logins too, same as EnsureCompanyAdminActive enforces
        // per-request once inside.
        $admin = $client->company?->admin;
        if ($admin && !$admin->is_active) {
            return ApiResponse::error(
                'Your company account is currently inactive. Please contact your administrator.',
                403,
                ['error_code' => 'company_admin_inactive']
            );
        }

        $user->update(['last_login_at' => now()]);

        $token = $user->createToken('client-token', ['role:client'])->plainTextToken;

        return ApiResponse::success([
            'token'  => $token,
            'user'   => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
            'client' => [
                'id'           => $client->id,
                'name'         => $client->name,
                'company_name' => $client->company_name,
            ],
        ], 'Login successful');
    }

    public function permissions(Request $request): JsonResponse
    {
        $client = Client::where('user_id', $request->user()->id)
            ->with('company.modules')
            ->first();

        if (!$client) {
            return ApiResponse::success([]);
        }

        // Modules the company has actually purchased
        $companyModules = $client->company->modules
            ->where('is_enabled', true)
            ->pluck('module_key')
            ->toArray();

        // Buying 'client_portal' unlocks all portal features.
        // Buying individual modules (invoices, projects, etc.) also unlocks their specific portal section.
        $portalToCompany = ClientPortalService::PORTAL_TO_COMPANY;

        // Per-client admin toggles from DB
        $perms = ClientPortalPermission::where('client_id', $client->id)
            ->get(['module_key', 'is_enabled'])
            ->keyBy('module_key')
            ->map(fn($p) => $p->is_enabled);

        $result = [];
        foreach (array_keys(ClientPortalPermission::MODULES) as $key) {
            $required = $portalToCompany[$key] ?? [$key];
            // Available if company purchased ANY of the qualifying modules
            $available = count(array_intersect((array) $required, $companyModules)) > 0;
            if (!$available) {
                $result[$key] = false;
                continue;
            }
            // Otherwise respect per-client toggle (default true)
            $result[$key] = $perms[$key] ?? true;
        }

        return ApiResponse::success($result);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return ApiResponse::success(null, 'Logged out successfully');
    }

    public function me(Request $request): JsonResponse
    {
        $user   = $request->user();
        $client = Client::where('user_id', $user->id)->first();

        return ApiResponse::success([
            'user'   => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
            'client' => $client ? [
                'id'           => $client->id,
                'name'         => $client->name,
                'company_name' => $client->company_name,
            ] : null,
        ]);
    }
}
