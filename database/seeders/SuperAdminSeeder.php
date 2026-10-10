<?php

namespace Database\Seeders;

use App\Models\SuperAdmin;
use Illuminate\Database\Seeder;

// Creates the platform owner's account from the environment.
//
// The email and password used to be written here as literals, which put a
// working super admin login in the repository — seeding any deployment gave
// it a password everyone with the source could read. Both now come from
// SUPER_ADMIN_EMAIL / SUPER_ADMIN_PASSWORD (see .env.example); with neither
// set there is nothing safe to seed, so this skips rather than inventing a
// default.
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email    = config('super-admin.email');
        $password = config('super-admin.password');

        if (empty($email) || empty($password)) {
            $this->command?->warn(
                'SuperAdminSeeder skipped — set SUPER_ADMIN_EMAIL and SUPER_ADMIN_PASSWORD in .env first.'
            );

            return;
        }

        $superAdmin = SuperAdmin::firstOrNew(['email' => $email]);

        $superAdmin->name      = config('super-admin.name', 'Super Admin');
        $superAdmin->is_active = true;

        // Only set the password while the account is still on the environment
        // one. Re-seeding an existing install must not undo a password the
        // Super Admin chose in the console — that is exactly what
        // password_changed_at records (see SuperAdminAuthController::login).
        //
        // The model's 'hashed' cast does the hashing; Hash::make() here would
        // double-hash it and lock the account out.
        if (!$superAdmin->exists || $superAdmin->usesConfigPassword()) {
            $superAdmin->password = $password;
        }

        $superAdmin->save();
    }
}
