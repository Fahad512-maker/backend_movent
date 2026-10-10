<?php

// The platform owner's console sign-in.
//
// No fallback values on purpose: these used to default to a real, working
// email and password written into this file, which meant the credentials were
// in the repository and any deployment that forgot to set them shipped with a
// publicly known super admin login. They are now REQUIRED to come from the
// environment (see .env.example), and Api\Auth\SuperAdminAuthController
// refuses every sign-in while they are missing rather than falling back to
// anything.
return [
    'name'     => env('SUPER_ADMIN_NAME', 'Super Admin'),
    'email'    => env('SUPER_ADMIN_EMAIL'),
    'password' => env('SUPER_ADMIN_PASSWORD'),
];
