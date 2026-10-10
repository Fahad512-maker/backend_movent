<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Super Admin-managed billing option (Monthly, Yearly, 2-Year, 5-Year …) —
// see the billing_terms migration. Every monthly Package automatically gets
// one companion row per active MULTI-month term
// (App\Services\YearlyPackageSync), priced at monthly price × months,
// discounted by discount_percent.
//
// The months = 1 term is the exception: the base monthly Package already IS
// that term's offering, so it gets no companion and the term's discount is
// written straight onto the base package instead. It's a real, editable row
// like any other (that's what lets Super Admin change Monthly's discount);
// it just can't be deleted, deactivated or re-dated — see BASE_MONTHS below
// and the guards in Api\SuperAdmin\BillingTermController.
class BillingTerm extends Model
{
    // A term of this length is the base monthly plan, not an upgrade on it.
    public const BASE_MONTHS = 1;

    protected $fillable = ['name', 'months', 'discount_percent', 'is_active'];

    protected $casts = [
        'months'           => 'integer',
        'discount_percent' => 'decimal:2',
        'is_active'        => 'boolean',
    ];

    // Every companion Package generated for this term across every monthly package.
    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }

    // True for the single row that represents plain monthly billing.
    public function isBase(): bool
    {
        return $this->months === self::BASE_MONTHS;
    }

    // The monthly term, whatever it happens to be named. Uniqueness on
    // `months` is enforced in BillingTermController, so there is only ever one.
    public static function base(): ?self
    {
        return static::where('months', self::BASE_MONTHS)->first();
    }
}
