<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientPortalPermission;
use App\Models\Company;
use App\Models\User;
use App\Support\CrossAccountEmail;
use Illuminate\Support\Facades\Hash;

// Shared by Api\Admin\ClientController and Api\User\ClientController — the
// portal-login-creation logic is identical regardless of which guard
// triggers it, so it lives here once instead of being duplicated per
// controller. No seat limit applies to client-portal logins — that cap is
// staff-Users-only (see Admin\UserController::orgUserLimit()).
class ClientPortalService
{
    // Portal module key → required company module key(s). Single source of
    // truth — was previously duplicated verbatim in Api\Admin\ClientController
    // (the admin Portal tab's read/write) and Api\Client\AuthController (the
    // sidebar nav-hint endpoint); now also the basis for isModuleEnabled()
    // below, the ONE place that actually enforces these toggles server-side.
    public const PORTAL_TO_COMPANY = [
        'projects'  => ['client_portal', 'projects'],
        'invoices'  => ['client_portal', 'invoices'],
        'payments'  => ['client_portal', 'payments'],
        'documents' => ['client_portal', 'documents'],
        // 'chat' retired 2026-09-11 — see ClientPortalPermission::MODULES.
        'support'   => ['client_portal'],
        'reports'   => ['client_portal', 'reports'],
        // Only the portal itself — what a company SELLS isn't tied to any
        // module it bought, so requiring a second one here would hide the
        // section from exactly the companies most likely to want it.
        'services'  => ['client_portal'],
    ];

    // Just the company-purchase half of isModuleEnabled() — "could any client
    // of this company use this portal module at all", ignoring the per-client
    // toggle. The Client Portal dashboard needs the two halves separately: a
    // module the company never bought has no tile, while one that was bought
    // and then switched off for THIS client still gets a tile, just an inert
    // one. Callers wanting the real access decision want isModuleEnabled().
    public static function isModuleAvailable(int $companyId, string $moduleKey): bool
    {
        $required = self::PORTAL_TO_COMPANY[$moduleKey] ?? [$moduleKey];

        return Company::find($companyId)
            ?->modules()
            ->whereIn('module_key', (array) $required)
            ->where('is_enabled', true)
            ->exists() ?? false;
    }

    // Whether THIS client may use the given portal module right now — both
    // the company's own purchase (an unpurchased module is never enabled
    // regardless of the per-client toggle) AND the per-client toggle Company
    // Admin sets on the client's Portal tab (defaults true — unset means
    // "not yet turned off", matching Api\Admin\ClientController::show()'s
    // existing display default).
    public static function isModuleEnabled(Client $client, string $moduleKey): bool
    {
        if (!self::isModuleAvailable($client->company_id, $moduleKey)) {
            return false;
        }

        $enabled = ClientPortalPermission::where('client_id', $client->id)
            ->where('module_key', $moduleKey)
            ->value('is_enabled');

        return $enabled ?? true;
    }

    // Unlike basic Client CRUD (grantable via either the Client module OR
    // the Sales module — see routes/api.php's comment), actually turning on
    // a client's portal LOGIN requires the real Client Portal module
    // specifically — no OR-with-Sales exception. Without this project the
    // client into having a working login on a Sales-only company that never
    // bought the module. 'clients' is included defensively even though it's
    // never a real CompanyModule row (see ModuleSeeder.php).
    public static function hasPortalModule(int $companyId): bool
    {
        return Company::find($companyId)
            ?->modules()
            ->whereIn('module_key', ['client_portal', 'clients'])
            ->where('is_enabled', true)
            ->exists() ?? false;
    }

    // Blocks a Company Admin match outright, and a staff match UNLESS that
    // User is already role_type='client' (re-using/re-linking an existing
    // client login is fine; silently hijacking a Seller/PM/QA/etc.'s login
    // is not).
    public static function emailBelongsToAnotherAccount(string $email): bool
    {
        if (CrossAccountEmail::existsAsAdmin($email)) {
            return true;
        }
        $user = User::where('email', $email)->first();
        return $user !== null && $user->role_type !== 'client';
    }

    public static function seedPermissions(int $clientId): void
    {
        foreach (array_keys(ClientPortalPermission::MODULES) as $key) {
            ClientPortalPermission::firstOrCreate(
                ['client_id' => $clientId, 'module_key' => $key],
                ['is_enabled' => true]
            );
        }
    }

    // Returns an error message on failure, null on success. Callers already
    // check emailBelongsToAnotherAccount() before reaching here — this is a
    // second, defense-in-depth guard against ever silently converting an
    // existing Admin/non-client staff login into a client login.
    public static function createOrUpdatePortalUser(Client $client, string $email, string $password): ?string
    {
        if (self::emailBelongsToAnotherAccount($email)) {
            return 'This email is already registered as a staff or Company Admin account.';
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            $user->update([
                'password'  => Hash::make($password),
                'role_type' => 'client',
                'is_active' => true,
            ]);
        } else {
            $user = User::create([
                'company_id' => $client->company_id,
                'name'       => $client->name,
                'email'      => $email,
                'password'   => Hash::make($password),
                'role_type'  => 'client',
                'is_active'  => true,
            ]);
        }

        $client->update(['portal_access' => true, 'user_id' => $user->id]);

        return null;
    }
}
