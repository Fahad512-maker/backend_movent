<?php

use App\Models\CompanyAdmin;
use App\Models\SuperAdmin;
use App\Models\User;

return [

    'defaults' => [
        'guard'     => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    'guards' => [
        'web' => [
            'driver'   => 'session',
            'provider' => 'users',
        ],

        /*
         * Sanctum guard for company admins (company_admins table).
         * The `provider` key causes Sanctum to reject tokens that belong
         * to any model other than CompanyAdmin.
         */
        'admin' => [
            'driver'   => 'sanctum',
            'provider' => 'admins',
        ],

        /*
         * Sanctum guard for CRM users (users table).
         * Restricts auth:sanctum to User model tokens only.
         */
        'sanctum' => [
            'driver'   => 'sanctum',
            'provider' => 'users',
        ],

        /*
         * Sanctum guard for the hardcoded super admin (super_admins table).
         */
        'super_admin' => [
            'driver'   => 'sanctum',
            'provider' => 'super_admins',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model'  => env('AUTH_MODEL', User::class),
        ],

        'admins' => [
            'driver' => 'eloquent',
            'model'  => CompanyAdmin::class,
        ],

        'super_admins' => [
            'driver' => 'eloquent',
            'model'  => SuperAdmin::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table'    => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire'   => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
