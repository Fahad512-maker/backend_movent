<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\User\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Mail\ClientPortalWelcomeMail;
use App\Models\Client;
use App\Models\ClientAiServiceBatch;
use App\Models\ClientPortalPermission;
use App\Models\ClientTransfer;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserCompanyPermission;
use App\Rules\ValidPhoneNumber;
use App\Services\ClientDeletionService;
use App\Services\ClientPortalService;
use App\Support\PermissionDebug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ClientController extends Controller
{
    use ScopesToActiveCompany;

    private function user() { return auth('sanctum')->user(); }

    // canViewClients/canCreateClients/canEditClients are granted identically
    // whether the company purchased the Client module or the Sales module
    // ("basic client access included with Sales") — check both buckets so a
    // Sales-only grant still works here, mirroring the same fix applied to
    // the frontend's can() helper. Checked against activeCompanyId() (not
    // the user's static company_id) — a Lead Manager/Seller assigned to
    // multiple companies holds a distinct permission set per
    // CompanyUserAssignment, so this must follow the company switcher, same
    // as visibleClients() below.
    private function can(string $permKey): bool
    {
        $user = $this->user();
        $companyId = $this->activeCompanyId();
        $result = UserCompanyPermission::where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->whereIn('module_key', ['client', 'sales'])
            ->where('permission_key', $permKey)
            ->exists();
        PermissionDebug::log($user->id, $companyId, $user->role_type, 'client/sales', $permKey, $result);
        return $result;
    }

    // Same-company clients this user may see/act on — every client if
    // canViewAllCompanyClients is held, otherwise only clients they're
    // actually tied to: the account manager, whose originating lead is
    // assigned/transferred to them, who has an invoice they created, or who
    // has a project handed off to them as seller. Mirrors
    // Api\User\LeadController::visibleLeads() and
    // Api\User\ProjectController::visibleProjects()'s ownership-scoping
    // pattern.
    //
    // An unassigned client (account_manager null — typically one Company
    // Admin just created without naming an owner yet) used to also fall
    // through to everyone holding canViewClients, so it appeared in every
    // Seller's/Lead Manager's own client list before anyone was actually
    // assigned to it. Reported as unwanted — a client should only be visible
    // to whoever it's actually assigned to; an unassigned one belongs to
    // nobody until a Company Admin (or a canViewAllCompanyClients holder)
    // assigns it. Note: this also means a Seller can no longer pick a
    // not-yet-assigned client from the invoice-create "Select Client" list —
    // Company Admin should assign an account manager first, same as any
    // other client-ownership action.
    private function visibleClients()
    {
        $user = $this->user();
        $base = Client::where('company_id', $this->activeCompanyId());

        if ($this->can('canViewAllCompanyClients')) {
            return $base;
        }

        return $base->where(function ($q) use ($user) {
            $q->where('account_manager', $user->id)
              ->orWhereHas('lead', fn ($l) => $l->where('assigned_to', $user->id)->orWhere('transferred_to', $user->id))
              ->orWhereHas('invoices', fn ($i) => $i->where('created_by', $user->id))
              ->orWhereHas('projects', fn ($p) => $p->where('seller_id', $user->id));
        });
    }

    // GET /user/clients — returns clients belonging to the sub user's company
    public function index(Request $request): JsonResponse
    {
        if (!$this->can('canViewClients')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $q = $this->visibleClients()->where('status', 'active');

        // Mirrors Api\Admin\ClientController::index()'s identical search —
        // previously this endpoint accepted no query params at all, so the
        // frontend's search box on /clients had nothing to actually send
        // (and the sub-user branch of that page never sent one either, see
        // frontend/app/clients/page.tsx's load()).
        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(fn ($x) =>
                $x->where('name', 'like', "%{$s}%")
                  ->orWhere('email', 'like', "%{$s}%")
                  ->orWhere('company_name', 'like', "%{$s}%")
            );
        }

        $clients = $q->orderBy('name')
            ->get(['id', 'name', 'company_name', 'email', 'phone', 'address', 'notes', 'status']);

        return ApiResponse::success($clients);
    }

    // GET /user/clients/{id}
    public function show(int $id): JsonResponse
    {
        if (!$this->can('canViewClients')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $client = $this->visibleClients()
            ->with(['user:id,email,role_type,is_active', 'company.modules', 'createdBy:id,name', 'createdByAdmin:id,name', 'accountManager:id,name'])
            ->findOrFail($id);

        // Once this client has a Project, its chat conversation moved there
        // — see App\Services\PaymentProjectStartService::migrateChatHistory()
        // — so the frontend can point the Sales Chat tab at Project Chat
        // instead of the old, now-abandoned Client-anchored thread.
        $client->chat_project_id = $client->projects()->latest()->value('id');

        // Portal permissions are only meaningful (and only sent) to a user
        // who can actually manage them — everyone else just gets the client.
        if (!$this->can('canEnableClientPortal') && !$this->can('canDisableClientPortal')) {
            return ApiResponse::success($client);
        }

        $companyModules = $client->company->modules
            ->where('is_enabled', true)
            ->pluck('module_key')
            ->toArray();

        $permissions = ClientPortalPermission::where('client_id', $id)
            ->get(['module_key', 'is_enabled'])
            ->keyBy('module_key')
            ->map(fn($p) => $p->is_enabled);

        $allModules = [];
        foreach (ClientPortalPermission::MODULES as $key => $label) {
            $required  = ClientPortalService::PORTAL_TO_COMPANY[$key] ?? [$key];
            $purchased = count(array_intersect((array) $required, $companyModules)) > 0;
            $allModules[$key] = [
                'label'      => $label,
                'is_enabled' => $purchased ? (bool) ($permissions[$key] ?? true) : false,
                'purchased'  => $purchased,
            ];
        }

        // Kept as an extra attribute on the same Client payload (not a
        // {client, permissions} envelope) so this endpoint's response shape
        // never changes regardless of who's asking — every existing caller
        // of GET /user/clients/{id} already expects a plain Client back.
        $data = $client->toArray();
        $data['portal_permissions'] = $allModules;
        // Drives the frontend's Portal tab/"Enable Portal" button — a
        // company without the real Client Portal module only ever gets a
        // Basic Client record, portal login is never offerable.
        $data['has_portal_module'] = in_array('client_portal', $companyModules, true);

        return ApiResponse::success($data);
    }

    // GET /user/clients/{id}/ai-service-batch-items — this client's
    // outstanding (not yet 'taken') AI-suggested services, for the "Link to
    // AI-Suggested Service" dropdown on Create Invoice. See
    // App\Services\ClientAiServiceBatchService.
    public function aiServiceBatchItems(int $id): JsonResponse
    {
        if (!$this->can('canViewClients')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $client = $this->visibleClients()->findOrFail($id);

        $batch = ClientAiServiceBatch::where('client_id', $client->id)->active()->first();

        if (!$batch) {
            return ApiResponse::success(['batch_id' => null, 'items' => []]);
        }

        $items = $batch->items()
            ->where('status', '!=', 'taken')
            ->orderBy('id')
            ->get(['id', 'key', 'name', 'category', 'status']);

        return ApiResponse::success(['batch_id' => $batch->id, 'items' => $items]);
    }

    // POST /user/clients — basic client record only; document/support
    // features remain Admin-only and out of scope. Portal access itself is
    // available via enablePortal()/disablePortal() below, gated by their own
    // permissions, rather than as a flag on this create form.
    public function store(Request $request): JsonResponse
    {
        if (!$this->can('canCreateClients')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $validated = $request->validate([
            'name'         => ['required', 'string', 'max:255'],
            'email'        => ['nullable', 'email', 'max:255'],
            'phone'        => ['nullable', 'string', 'max:50', new ValidPhoneNumber],
            'company_name' => ['nullable', 'string', 'max:255'],
            'address'      => ['nullable', 'string', 'max:500'],
            'country'      => ['nullable', 'string', 'max:5'],
            'notes'        => ['nullable', 'string'],
            'status'       => ['nullable', 'in:active,inactive,blocked'],
        ]);

        // A new client belongs to whichever company is currently active (the
        // switcher), not the user's static primary company — otherwise a
        // Lead Manager/Seller viewing Company B's client list who creates a
        // client here would have it silently land in Company A instead.
        $companyId = $this->activeCompanyId();

        // Same company-scoped duplicate guard as Api\User\LeadController::
        // convert() — a Seller creating a client by hand must hit the same
        // "already exists" wall as one converting a lead.
        if (!empty($validated['email']) && Client::where('company_id', $companyId)->where('email', $validated['email'])->exists()) {
            return ApiResponse::error('Client with this email already exists.', 422);
        }

        $validated['company_id'] = $companyId;
        $validated['status']   ??= 'active';
        // Whoever creates this client becomes its account manager by
        // default — without this, a Seller who just created a client would
        // immediately lose visibility into it under visibleClients() above.
        $validated['account_manager'] = $this->user()->id;
        $validated['created_by']      = $this->user()->id;

        $client = Client::create($validated);

        return ApiResponse::success($client, 'Client created', 201);
    }

    // PUT /user/clients/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        if (!$this->can('canEditClients')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $client = $this->visibleClients()->findOrFail($id);

        $validated = $request->validate([
            'name'         => ['sometimes', 'string', 'max:255'],
            'email'        => ['nullable', 'email', 'max:255'],
            'phone'        => ['nullable', 'string', 'max:50', new ValidPhoneNumber],
            'company_name' => ['nullable', 'string', 'max:255'],
            'address'      => ['nullable', 'string', 'max:500'],
            'country'      => ['nullable', 'string', 'max:5'],
            'notes'        => ['nullable', 'string'],
            'status'       => ['sometimes', 'in:active,inactive,blocked'],
        ]);

        // Same company-scoped duplicate guard as store() — editing a
        // client's email must not collide with a different client's.
        if (!empty($validated['email'])) {
            $duplicate = Client::where('company_id', $client->company_id)
                ->where('email', $validated['email'])
                ->where('id', '!=', $client->id)
                ->exists();
            if ($duplicate) {
                return ApiResponse::error('Client with this email already exists.', 422);
            }
        }

        $client->update($validated);

        return ApiResponse::success($client, 'Client updated');
    }

    // POST /user/clients/{id}/transfer — mirrors Api\User\LeadController::
    // transfer() / Api\Admin\ClientController::transfer(). Gated on
    // canTransferClients (Lead Manager default; a Company Admin can always
    // do this regardless, via the Admin-side endpoint, which has no gate).
    public function transfer(Request $request, int $id): JsonResponse
    {
        if (!$this->can('canTransferClients')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $client = $this->visibleClients()->findOrFail($id);

        $validated = $request->validate([
            'to_user_id' => ['required', 'integer'],
            'reason'     => ['nullable', 'string', 'max:1000'],
        ]);

        $fromUserId = $client->account_manager;
        $toUserId   = $validated['to_user_id'];

        if ($fromUserId === $toUserId) {
            return ApiResponse::error('Client is already assigned to this user.', 422);
        }

        $isValidTarget = User::ofCompany($client->company_id)
            ->where('id', $toUserId)
            ->where('is_active', true)
            ->where('role_type', 'seller')
            ->exists();
        if (!$isValidTarget) {
            return ApiResponse::error('Selected user is not an eligible transfer target at this company.', 422);
        }

        ClientTransfer::create([
            'client_id'              => $client->id,
            'company_id'             => $client->company_id,
            'from_user_id'           => $fromUserId,
            'to_user_id'             => $toUserId,
            'transferred_by_user_id' => $this->user()->id,
            'reason'                 => $validated['reason'] ?? null,
        ]);

        $client->update(['account_manager' => $toUserId]);

        Notification::create([
            'user_id'    => $toUserId,
            'company_id' => $client->company_id,
            'type'       => 'client_transferred',
            'title'      => 'Client assigned to you',
            'body'       => "Client \"{$client->name}\" was assigned to you" . (!empty($validated['reason']) ? " — {$validated['reason']}" : '') . '.',
            'data'       => ['client_id' => $client->id, 'link' => "/clients/{$client->id}"],
        ]);

        $client->load(['user:id,email,role_type,is_active', 'createdBy:id,name', 'createdByAdmin:id,name', 'accountManager:id,name']);
        return ApiResponse::success($client, 'Client transferred');
    }

    // GET /user/clients/company-users — picker list for the Transfer Client
    // modal. Sellers only — mirrors transfer()'s validation.
    public function companyUsers(): JsonResponse
    {
        if (!$this->can('canTransferClients')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $users = User::ofCompany($this->activeCompanyId())
            ->where('is_active', true)
            ->where('role_type', 'seller')
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return ApiResponse::success($users);
    }

    // DELETE /user/clients/{id} — gated behind canDeleteClients, not on by
    // default for any role (Company Admin decides who gets it, e.g. a
    // Project Manager). Soft delete only (Client uses SoftDeletes) — see
    // Api\Admin\ClientController::destroy()'s comment: a real row delete
    // would cascade-wipe the client's invoices, a soft delete never does.
    // Also deactivates the linked portal login (if any).
    // GET /user/clients/{id}/delete-summary — everything a delete would take,
    // so the confirmation modal can spell out the blast radius first.
    // Read-only, but gated on canDeleteClients: only someone who could go
    // through with it has any business seeing the tally.
    public function deleteSummary(int $id): JsonResponse
    {
        if (!$this->can('canDeleteClients')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $client = $this->visibleClients()->findOrFail($id);

        return ApiResponse::success(ClientDeletionService::summary($client));
    }

    // Permanent, not a soft delete: the client and every project, task,
    // invoice, payment and compliance record hanging off it are removed for
    // good. The UI must show delete-summary and take an explicit confirmation
    // first — there is nothing to restore afterwards.
    public function destroy(int $id): JsonResponse
    {
        if (!$this->can('canDeleteClients')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $client = $this->visibleClients()->findOrFail($id);

        ClientDeletionService::purge($client, $this->user()->id);

        return ApiResponse::success(null, 'Client and all linked records permanently deleted');
    }

    // PUT /user/clients/{id}/permissions — which portal modules this client
    // can see; either portal permission implies "manages portal" broadly,
    // matching Admin's own updatePermissions() (no dedicated key there since
    // Company Admin is unrestricted by guard).
    public function updatePermissions(Request $request, int $id): JsonResponse
    {
        if (!$this->can('canEnableClientPortal') && !$this->can('canDisableClientPortal')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $client = $this->visibleClients()->findOrFail($id);

        $request->validate([
            'permissions'   => 'required|array',
            'permissions.*' => 'boolean',
        ]);

        $allowed = array_keys(ClientPortalPermission::MODULES);

        foreach ($request->permissions as $moduleKey => $isEnabled) {
            if (!in_array($moduleKey, $allowed)) continue;
            ClientPortalPermission::updateOrCreate(
                ['client_id' => $client->id, 'module_key' => $moduleKey],
                ['is_enabled' => (bool) $isEnabled]
            );
        }

        return ApiResponse::success(null, 'Permissions updated');
    }

    // POST /user/clients/{id}/enable-portal — was previously reachable only
    // via the admin guard despite canEnableClientPortal existing in
    // ModuleCatalog since introduction; nothing ever checked it. Mirrors
    // Api\Admin\ClientController::enablePortal() exactly, via the shared
    // App\Services\ClientPortalService so the seat-limit / portal-login
    // logic can't drift between the two guards.
    public function enablePortal(Request $request, int $id): JsonResponse
    {
        if (!$this->can('canEnableClientPortal')) {
            return ApiResponse::error('Permission denied', 403);
        }

        if (!ClientPortalService::hasPortalModule($this->activeCompanyId())) {
            return ApiResponse::error('Client Portal module is not enabled for this company.', 403);
        }

        $request->validate([
            'portal_email'    => 'required|email|max:255',
            'portal_password' => 'required|string|min:6|max:100',
        ]);

        $client = $this->visibleClients()->findOrFail($id);

        if (ClientPortalService::emailBelongsToAnotherAccount($request->portal_email)) {
            return ApiResponse::error('This email is already registered as a staff or Company Admin account.', 422);
        }

        // No seat limit on Clients — see the matching note in
        // Api\Admin\ClientController::store().

        $error = ClientPortalService::createOrUpdatePortalUser($client, $request->portal_email, $request->portal_password);
        if ($error) {
            return ApiResponse::error($error, 422);
        }
        ClientPortalService::seedPermissions($client->id);

        // Same login-details email as Api\Admin\ClientController::enablePortal()
        // — non-blocking, never fails this action.
        try {
            Mail::to($request->portal_email)->send(new ClientPortalWelcomeMail(
                $client, $client->company ?? Company::find($client->company_id), $request->portal_email, $request->portal_password
            ));
        } catch (\Throwable) {
            // Don't fail portal enablement if mail fails
        }

        return ApiResponse::success($client->fresh(), 'Portal access enabled');
    }

    // POST /user/clients/{id}/disable-portal
    public function disablePortal(int $id): JsonResponse
    {
        if (!$this->can('canDisableClientPortal')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $client = $this->visibleClients()->findOrFail($id);
        $client->update(['portal_access' => false]);

        return ApiResponse::success(null, 'Portal access disabled');
    }
}
