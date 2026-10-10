<?php

namespace App\Http\Controllers\Api\Client;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Client\Concerns\ScopesToPermittedClient;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\CompanyService;
use App\Models\ServiceRequest;
use App\Services\ClientServiceRequestService;
use App\Services\ServiceRecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// The "Grow Your Business With Us" section of the Client Portal: what this
// company offers, what this particular client should probably look at next,
// and the three ways they can ask for it.
//
// Gated on the 'services' portal module like every other portal section, so a
// Company Admin can switch the whole section off for a client who shouldn't be
// sold to (see ClientPortalService::PORTAL_TO_COMPANY).
class ServiceController extends Controller
{
    use ScopesToPermittedClient;

    // GET /client/services
    public function index(Request $request): JsonResponse
    {
        $client = $this->permittedClient($request, 'services');

        // Every client record behind this login, not just the primary one:
        // work bought under either is still this person's, and pretending
        // otherwise would recommend them something they already have.
        $clients = Client::whereIn('id', $this->permittedClientIds($request, 'services'))->get();

        return ApiResponse::success(
            ServiceRecommendationService::for($clients, $client->company_id)
        );
    }

    // POST /client/services/request
    //
    // "Contact Support" is deliberately not one of the intents here — that
    // button goes to the existing support ticket flow, because a support
    // request belongs in the support queue, not the sales pipeline.
    public function storeRequest(Request $request): JsonResponse
    {
        $client = $this->permittedClient($request, 'services');

        $validated = $request->validate([
            // Nullable so "Other IT Services" and a free-text ask still work.
            'company_service_id' => ['nullable', 'integer'],
            'intent'             => ['required', 'string', 'in:' . implode(',', array_keys(ServiceRequest::INTENTS))],
            'message'            => ['nullable', 'string', 'max:2000'],
            // Consultations only. after_or_equal:today — a client asking to
            // meet last Tuesday is a typo, and it would quietly sit in the
            // queue looking overdue.
            'preferred_date'     => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $service = null;
        if (!empty($validated['company_service_id'])) {
            // Resolved against this company's ENABLED services: a client must
            // not be able to raise a request for something the company doesn't
            // offer by posting an id straight at the API.
            $service = CompanyService::where('company_id', $client->company_id)
                ->enabled()
                ->find($validated['company_service_id']);

            if (!$service) {
                return ApiResponse::error('That service is not available.', 422);
            }
        }

        $serviceName = $service?->presentation()['name'] ?? 'Other IT Services';

        $serviceRequest = ClientServiceRequestService::create(
            $client,
            $service,
            $serviceName,
            $validated['intent'],
            $validated['message'] ?? null,
            $validated['preferred_date'] ?? null,
            $request->ip(),
            $request->userAgent(),
        );

        return ApiResponse::success([
            'id'      => $serviceRequest->id,
            'lead_id' => $serviceRequest->lead_id,
        ], "Thanks — your request for {$serviceName} has been sent. The team will be in touch.", 201);
    }
}
