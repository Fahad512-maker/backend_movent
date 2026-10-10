<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// A trading name a company bills under — see the create_brands_table
// migration for why these live per company rather than per Company Admin.
class Brand extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'email', 'phone', 'website', 'country', 'address',
        'logo_path', 'is_active', 'created_by', 'created_by_admin_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // The staff who work this brand — several people can share one trading
    // name, the same way a project has a team. Only users holding Invoice
    // create/manage permissions in the same company are ever offered; see
    // BrandController::ASSIGNABLE_INVOICE_PERMISSIONS.
    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'brand_user')->withTimestamps();
    }

    public function createdBy(): BelongsTo
    {
        // withTrashed(): "who added this brand" is a historical fact — a
        // since-removed colleague must still resolve to a name.
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'created_by_admin_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // The brands an invoice may be raised under, for one company.
    //
    // $userId null  → the Company Admin: every active brand the company has.
    // $userId given → a staff member: ONLY the brands assigned to them. That
    // is the whole rule behind "sirf assigned brands" — it backs both the
    // dropdown the invoice form shows and the re-check on save, so a brand
    // that was never theirs (or has since been reassigned) can't be used even
    // if the id is sent by hand. Reassigning a brand therefore changes what
    // each person can invoice under from the very next request.
    //
    // Inactive brands are excluded on both paths: deactivating a brand is how
    // you stop new invoices going out under it.
    /**
     * @param  int|int[]  $companyIds  One company, or several.
     *
     * Several is the "All Companies" case on the invoice form: an admin with
     * the top-bar filter on All Companies picks a BRAND, and the brand's own
     * company_id is what the invoice is then raised under — a brand belongs
     * to exactly one company, so asking for the company separately would be
     * asking a question the answer already contains.
     */
    public static function invoiceableFor(int|array $companyIds, ?int $userId)
    {
        $q = static::whereIn('company_id', (array) $companyIds)
            ->active()
            // Ordered by company then name so the dropdown's per-company
            // groups come out contiguous rather than interleaved.
            ->orderBy('company_id')
            ->orderBy('name');

        if ($userId !== null) {
            $q->whereHas('assignedUsers', fn ($u) => $u->where('users.id', $userId));
        }

        return $q;
    }
}
