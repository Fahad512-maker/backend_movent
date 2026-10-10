<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackageModule extends Model
{
    protected $fillable = ['package_id', 'module_key', 'is_enabled', 'is_core'];

    protected $casts = ['is_enabled' => 'boolean', 'is_core' => 'boolean'];

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
