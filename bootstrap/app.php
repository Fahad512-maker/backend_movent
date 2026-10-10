<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Presence. Appended to every API request rather than aliased onto
        // particular route groups: whichever endpoint a seller's session hits
        // is equally good evidence they are here, and the middleware itself
        // no-ops for anything that isn't a Sanctum-authenticated `users` row.
        $middleware->api(append: [
            \App\Http\Middleware\TouchLastSeen::class,
        ]);

        $middleware->alias([
            'module'               => \App\Http\Middleware\CheckCompanyModule::class,
            'permission'           => \App\Http\Middleware\CheckUserPermission::class,
            'client.role'          => \App\Http\Middleware\CheckClientRole::class,
            'tenant.active'        => \App\Http\Middleware\EnsureTenantActive::class,
            'subscription.active'  => \App\Http\Middleware\EnsureSubscriptionActive::class,
            'company.subscription.active' => \App\Http\Middleware\EnsureCompanySubscriptionActive::class,
            'company.not_suspended' => \App\Http\Middleware\EnsureCompanyNotSuspended::class,
            'company.admin.active'  => \App\Http\Middleware\EnsureCompanyAdminActive::class,
            'active.company'       => \App\Http\Middleware\RequireActiveCompany::class,
            'sync.active.company'  => \App\Http\Middleware\SyncActiveCompanyContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
