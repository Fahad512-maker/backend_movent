<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class SuperAdmin extends Authenticatable
{
    use HasApiTokens;

    protected $fillable = ['name', 'email', 'password', 'is_active', 'last_login_at', 'password_changed_at'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'is_active'           => 'boolean',
        'last_login_at'       => 'datetime',
        // Set the first time the Super Admin changes their own password from
        // the console; from then on the SUPER_ADMIN_PASSWORD env value is no
        // longer accepted at login (see SuperAdminAuthController::login).
        'password_changed_at' => 'datetime',
        'password'            => 'hashed',
    ];

    // Whether this account still falls back to the env password. False once
    // the admin has set their own — the one place that rule is written down,
    // so login and the profile screen can't drift apart on it.
    public function usesConfigPassword(): bool
    {
        return $this->password_changed_at === null;
    }
}
