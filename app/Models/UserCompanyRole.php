<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One role a user holds in one company.
 *
 * A user may have several rows per company — that is the point. Their effective
 * permissions are the UNION of every role's defaults (seeded into
 * user_company_permissions at create/edit time), so a second role can only ever
 * widen access, never narrow it.
 *
 * Company-scoped by construction: a role row names the company it applies to,
 * so being a Project Manager in one company grants nothing in another.
 */
class UserCompanyRole extends Model
{
    protected $fillable = ['user_id', 'company_id', 'role', 'is_primary'];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
