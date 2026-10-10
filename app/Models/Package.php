<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    protected $fillable = [
        'name', 'tier', 'price', 'price_pkr', 'price_usd', 'billing_cycle',
        'trial_days', 'discount_percent', 'max_companies', 'max_users_per_company', 'description',
        'is_active', 'is_visible', 'is_popular', 'features', 'monthly_package_id', 'billing_term_id',
    ];

    protected $casts = [
        'price'            => 'decimal:2',
        'price_pkr'        => 'decimal:2',
        'price_usd'        => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'is_active'        => 'boolean',
        'is_visible'       => 'boolean',
        'is_popular'       => 'boolean',
        'features'         => 'array',
    ];

    public function modules(): HasMany
    {
        return $this->hasMany(PackageModule::class);
    }

    public function companyAdmins(): HasMany
    {
        return $this->hasMany(CompanyAdmin::class);
    }

    public function subscriptionPayments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    // Non-null only on a system-generated multi-year companion row — see
    // App\Services\YearlyPackageSync.
    public function monthlyPackage(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'monthly_package_id');
    }

    // Every multi-year companion of this monthly package — one per active
    // BillingTerm (Yearly, 2-Year, 5-Year, whatever Super Admin has defined).
    public function companions(): HasMany
    {
        return $this->hasMany(Package::class, 'monthly_package_id');
    }

    // Non-null only on a companion row — which Billing Term (Yearly, 2-Year,
    // ...) it represents.
    public function billingTerm(): BelongsTo
    {
        return $this->belongsTo(BillingTerm::class);
    }

    public function isCompanion(): bool
    {
        return $this->monthly_package_id !== null;
    }
}
