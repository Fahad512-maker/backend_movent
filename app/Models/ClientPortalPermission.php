<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientPortalPermission extends Model
{
    public $timestamps = false;

    protected $fillable = ['client_id', 'module_key', 'is_enabled'];

    protected $casts = ['is_enabled' => 'boolean'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    // All portal modules with their default state (all on by default)
    public const MODULES = [
        'projects'  => 'Projects',
        'invoices'  => 'Invoices',
        'payments'  => 'Payment History',
        'documents' => 'Documents',
        // 'chat' => 'Chat' was retired on 2026-09-11 with the Client Portal's
        // account-level Sales Chat (Api\Client\ChatController). The admin's
        // Portal tab had already stopped rendering a row for it; this is the
        // backend catching up, so the toggle no longer appears at all. Existing
        // client_portal_permissions rows with module_key='chat' are harmless —
        // nothing reads them now.
        'support'   => 'Support Tickets',
        'reports'   => 'Reports',
        // The "Grow Your Business With Us" section. Like every key here, an
        // unset row means enabled (see ClientPortalService::isModuleEnabled),
        // so existing clients get the section without a backfill — and a
        // Company Admin can switch it off per client for anyone who shouldn't
        // be sold to.
        'services'  => 'Recommended Services',
    ];
}
