<?php

namespace App\Support;

use App\Models\Company;
use App\Models\CompanyAdmin;
use Illuminate\Validation\ValidationException;

class CompanyName
{
    public static function normalize(?string $name): string
    {
        return preg_replace('/\s+/', ' ', trim((string) $name)) ?? '';
    }

    /**
     * Name uniqueness is scoped to a single admin's own account (their email),
     * not global: two different Company Admins may run companies with the
     * same name, but one admin can't reuse a name across their own companies.
     * A null $adminId (brand-new signup, admin doesn't exist yet) has no
     * companies to collide with, so it's always available.
     */
    public static function exists(string $name, ?int $adminId, ?int $exceptCompanyId = null): bool
    {
        if ($adminId === null) {
            return false;
        }

        $normalized = mb_strtolower(self::normalize($name));

        $companyExists = Company::query()
            ->where('admin_id', $adminId)
            ->when($exceptCompanyId, fn ($q) => $q->where('id', '!=', $exceptCompanyId))
            ->whereRaw('LOWER(TRIM(name)) = ?', [$normalized])
            ->exists();

        if ($companyExists) {
            return true;
        }

        return CompanyAdmin::query()
            ->where('id', $adminId)
            ->whereNotNull('business_name')
            ->whereRaw('LOWER(TRIM(business_name)) = ?', [$normalized])
            ->exists();
    }

    public static function throwIfTaken(string $name, string $field, ?int $adminId, ?int $exceptCompanyId = null): void
    {
        if (!self::exists($name, $adminId, $exceptCompanyId)) {
            return;
        }

        throw ValidationException::withMessages([
            $field => ['You already have a company with this name. Please choose a different name.'],
        ]);
    }
}
