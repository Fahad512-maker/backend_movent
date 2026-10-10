<?php

namespace App\Http\Middleware;

use App\Helpers\ApiResponse;
use App\Models\UserPermission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUserPermission
{
    /**
     * Usage: middleware('permission:leads.can_view')
     * Permission string format: {module_key}.{can_ability}
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (!$user) {
            return ApiResponse::error('Unauthorized', 401);
        }

        [$moduleKey, $ability] = array_pad(explode('.', $permission, 2), 2, null);

        if (!$moduleKey || !$ability) {
            return ApiResponse::error('Invalid permission format.', 500);
        }

        $record = UserPermission::where('user_id', $user->id)
            ->where('module_key', $moduleKey)
            ->first();

        if (!$record || !$record->{$ability}) {
            return ApiResponse::error("Permission denied: {$permission}", 403);
        }

        return $next($request);
    }
}
