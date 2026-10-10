<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ActiveCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Almost every Api\User\* controller reads $user->company_id directly for
// data scoping (a convention that predates multi-company support) — only a
// handful of methods were ever migrated to prefer X-Active-Company-Id
// instead (see Api\User\Concerns\ScopesToActiveCompany). That left a
// multi-company staff member's second company effectively non-functional:
// switching their active-company selector did nothing for Leads, Sales,
// Clients, Payments, etc., since those still silently read the static
// column. Rather than rewriting every one of those call sites, this
// overrides $user->company_id to the correctly-resolved active company
// (same resolver CheckCompanyModule/RequireActiveCompany already use) — IN
// MEMORY ONLY, for the lifetime of this one request — so every controller's
// existing $user->company_id read transparently gets the right value.
//
// syncOriginal() immediately after is what makes this safe: it tells
// Eloquent the overridden value IS the "original", so it's never counted as
// dirty. Without it, a later ->update()/->save() on this same $user
// instance in the same request — e.g. Api\User\ProfileController::update()/
// uploadAvatar()/changePassword(), all of which call Model::update(), which
// persists every dirty attribute, not just the ones passed in — would
// silently write this override back into the real users.company_id column.
class SyncActiveCompanyContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $resolved = ActiveCompany::resolve($request, $user);
            if ($resolved) {
                $user->company_id = $resolved;
                $user->syncOriginal();
            }
        }

        return $next($request);
    }
}
