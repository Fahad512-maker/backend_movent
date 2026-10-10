<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

// Dev-only visibility into sub-user permission checks. Logs nothing unless
// APP_DEBUG is on, so this never writes to production logs — added so a
// misconfigured grant (wrong module_key, missing row, unexpected data_scope)
// can be diagnosed from storage/logs/laravel.log instead of guessing.
class PermissionDebug
{
    public static function log(
        int $userId,
        ?int $companyId,
        ?string $roleType,
        string $moduleKey,
        string $permKey,
        bool $granted,
        ?string $dataScope = null
    ): void {
        if (!config('app.debug')) {
            return;
        }

        Log::debug('[permission-check]', [
            'endpoint'    => Request::method() . ' ' . Request::path(),
            'user_id'     => $userId,
            'company_id'  => $companyId,
            'role_type'   => $roleType,
            'module_key'  => $moduleKey,
            'permission'  => $permKey,
            'data_scope'  => $dataScope,
            'granted'     => $granted,
        ]);
    }
}
