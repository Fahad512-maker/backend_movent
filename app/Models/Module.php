<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Module extends Model
{
    protected $fillable = [
        'key', 'label', 'description', 'sub_modules', 'price_pkr', 'price_usd', 'is_active', 'is_system',
    ];

    protected $casts = [
        'sub_modules' => 'array',
        'price_pkr'   => 'float',
        'price_usd'   => 'float',
        'is_active'   => 'boolean',
        'is_system'   => 'boolean',
    ];

    /**
     * Granular company_modules keys whose parent catalog module the Super
     * Admin has deactivated. CheckCompanyModule refuses these on the API, so
     * every module list handed to the frontend (AdminResource, UserResource)
     * strips them too — one definition, so the UI and the API can't disagree.
     *
     * @return array<int, string>
     */
    public static function disabledSubModuleKeys(): array
    {
        return static::where('is_active', false)
            ->pluck('sub_modules')
            ->flatten()
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
