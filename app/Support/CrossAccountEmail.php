<?php

namespace App\Support;

use App\Models\CompanyAdmin;
use App\Models\User;

// One email must never belong to both a CompanyAdmin and a User (staff or
// client) at the same time — the two tables have independent unique
// constraints, so nothing stopped that from happening today, and it already
// caused real login-routing bugs (an email resolving to the wrong account
// type depending on which table matched first). Every place that creates a
// CompanyAdmin or a User row checks the other table via this helper before
// inserting.
class CrossAccountEmail
{
    public static function existsAsAdmin(string $email): bool
    {
        return CompanyAdmin::where('email', $email)->exists();
    }

    public static function existsAsUser(string $email): bool
    {
        return User::where('email', $email)->exists();
    }
}
