<?php

namespace App\Http\Controllers\Api\Client\Concerns;

use App\Models\Client;
use App\Services\ClientPortalService;
use Illuminate\Http\Request;

// Every Api\Client\* controller resolves "which Client row(s) does this
// portal login own" the same way (was copy-pasted per controller as
// clientIds()) — this adds the one check every copy was missing: cutting a
// client_id back out the moment Company Admin disables the portal module it
// maps to (or the company no longer has that module at all). See
// ClientPortalService::isModuleEnabled() for the actual rule. Previously
// client_portal_permissions was read only by Api\Client\AuthController's
// sidebar nav-hint endpoint — every feature-serving endpoint ignored it
// entirely, so a client could still reach a "disabled" module by direct API
// call or by typing its URL.
trait ScopesToPermittedClient
{
    // Every client_id this portal login owns AND that currently has
    // $moduleKey enabled. 404 if the login owns no portal-access client at
    // all (unchanged prior behavior); 403 if it owns one but $moduleKey is
    // off (or unpurchased) for all of them.
    private function permittedClientIds(Request $request, string $moduleKey): array
    {
        $clients = Client::where('user_id', $request->user()->id)
            ->where('portal_access', true)
            ->get();

        if ($clients->isEmpty()) {
            abort(404, 'Client not found');
        }

        $ids = $clients->filter(fn (Client $c) => ClientPortalService::isModuleEnabled($c, $moduleKey))
            ->pluck('id')
            ->toArray();

        if (empty($ids)) {
            abort(403, 'This feature is not enabled for your account.');
        }

        return $ids;
    }

    // Same rule, for a controller that only ever deals with a single Client
    // row per login (e.g. Support) rather than a list of ids.
    private function permittedClient(Request $request, string $moduleKey): Client
    {
        $client = Client::where('user_id', $request->user()->id)
            ->where('portal_access', true)
            ->first();

        if (!$client) {
            abort(404, 'Client not found');
        }

        if (!ClientPortalService::isModuleEnabled($client, $moduleKey)) {
            abort(403, 'This feature is not enabled for your account.');
        }

        return $client;
    }
}
