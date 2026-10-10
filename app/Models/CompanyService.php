<?php

namespace App\Models;

use App\Services\ServiceCatalog;
use App\Support\PublicFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// One IT service a company offers its clients — see the create_company_services
// migration for why these are per-company rows over a code catalogue rather
// than 37 seeded rows each.
class CompanyService extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'slug', 'name', 'short_description', 'icon_path',
        'starting_price', 'is_enabled', 'is_featured', 'is_repeatable', 'display_order',
        'created_by', 'created_by_admin_id',
    ];

    protected $casts = [
        'is_enabled'     => 'boolean',
        'is_featured'    => 'boolean',
        // Whether a client who already purchased this service may still be
        // recommended it again (e.g. a monthly retainer) — see
        // App\Services\ClientServiceRecommendationService. Default false:
        // most one-off services shouldn't be re-suggested once bought.
        'is_repeatable'  => 'boolean',
        'starting_price' => 'decimal:2',
        'display_order'  => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class);
    }

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    // The order the Client Portal shows them in: featured first, then the
    // admin's own display_order, then name so the list never reshuffles
    // between requests for rows that tie.
    public function scopeForDisplay($query)
    {
        return $query->orderByDesc('is_featured')->orderBy('display_order')->orderBy('name');
    }

    // The catalogue entry behind this row, or null for a service the admin
    // invented. Only the catalogue takes part in the recommendation rules.
    public function catalogEntry(): ?array
    {
        return $this->slug ? ServiceCatalog::get($this->slug) : null;
    }

    // What the client sees. Falls back to the catalogue for anything the admin
    // left blank — a company that enabled a service without writing its own
    // copy still gets a usable card rather than an empty one.
    public function presentation(): array
    {
        $catalog = $this->catalogEntry();

        return [
            'id'                => $this->id,
            'slug'              => $this->slug,
            'name'              => $this->name ?: ($catalog['name'] ?? ''),
            'short_description' => $this->short_description ?: ($catalog['description'] ?? null),
            // A custom uploaded banner wins; otherwise the catalogue's emoji,
            // which is why a card is never iconless.
            'icon_url'          => PublicFile::url($this->icon_path),
            'icon_emoji'        => $catalog['icon'] ?? '💼',
            'group'             => $catalog['group'] ?? 'Other',
            'starting_price'    => $this->starting_price !== null ? (float) $this->starting_price : null,
            'is_featured'       => (bool) $this->is_featured,
        ];
    }
}
