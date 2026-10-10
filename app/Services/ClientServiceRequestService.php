<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyService;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\ServiceRequest;
use App\Models\SystemAuditLog;
use Illuminate\Support\Facades\DB;

// The single place a client "asking for a service" from the Client Portal
// becomes a real ServiceRequest — used by both the "Grow Your Business With
// Us" section (Api\Client\ServiceController) and the AI Suggested IT
// Services widget on the dashboard (Api\Client\DashboardController).
// Whichever button the client actually clicked, it ends up as the same kind
// of row: shows in the company's Services → Client Requests exactly like
// every other request, raises the same Lead, and fires the same bell +
// email to the company. Keeping both entry points on one path is what keeps
// them from drifting into two different, half-supported request systems.
class ClientServiceRequestService
{
    /**
     * If this client already has an OPEN (new/in_progress) request for the
     * same service + intent, that row is returned as-is and nothing is
     * created or re-sent — the guard against a double-click or a retried API
     * call raising (and re-emailing) the same request twice. A closed
     * request never blocks a fresh ask for the same thing later.
     */
    public static function create(
        Client $client,
        ?CompanyService $service,
        string $serviceName,
        string $intent,
        ?string $message,
        ?string $preferredDate,
        ?string $ip = null,
        ?string $userAgent = null,
    ): ServiceRequest {
        $existing = ServiceRequest::where('client_id', $client->id)
            ->where('service_name', $serviceName)
            ->where('intent', $intent)
            ->whereIn('status', ['new', 'in_progress'])
            ->first();

        if ($existing) {
            return $existing;
        }

        // One transaction: a request without its Lead would sit unseen, and a
        // Lead without its request loses the client's own message.
        $serviceRequest = DB::transaction(function () use ($client, $service, $serviceName, $intent, $message, $preferredDate) {
            $serviceRequest = ServiceRequest::create([
                'company_id'         => $client->company_id,
                'client_id'          => $client->id,
                'company_service_id' => $service?->id,
                'service_name'       => $serviceName,
                'intent'             => $intent,
                'message'            => $message,
                'preferred_date'     => $preferredDate,
            ]);

            $intentLabel = $serviceRequest->intentLabel();

            $lead = Lead::create([
                'company_id'      => $client->company_id,
                'name'            => $client->name,
                'email'           => $client->email,
                'phone'           => $client->phone,
                'company_name'    => $client->company_name,
                // Its own source value — an existing paying client asking for
                // more work is the warmest lead there is and must not be
                // buried among cold website enquiries.
                'source'          => 'client_portal',
                'status'          => 'new',
                'priority'        => 'high',
                'notes'           => trim(
                    "{$intentLabel} — {$serviceName} (via Client Portal)"
                    . ($message ? "\n\n" . $message : '')
                    . ($preferredDate ? "\n\nPreferred date: {$preferredDate}" : '')
                ),
            ]);

            $serviceRequest->update(['lead_id' => $lead->id]);

            return $serviceRequest;
        });

        self::notifyCompany($client, $serviceRequest, $serviceName, $serviceRequest->intentLabel());

        SystemAuditLog::create([
            'company_id'  => $client->company_id,
            'user_id'     => null,
            'action'      => 'service.requested',
            'module_key'  => 'client_portal',
            'entity_type' => 'ServiceRequest',
            'entity_id'   => $serviceRequest->id,
            'new_values'  => ['service' => $serviceName, 'intent' => $intent, 'client_id' => $client->id],
            'ip_address'  => $ip,
            'user_agent'  => $userAgent,
        ]);

        return $serviceRequest;
    }

    // The company should hear about this without having to open a list — by
    // BELL and by EMAIL.
    //
    // Both, not one: an invoice or a support ticket is expected, but an
    // existing client asking to buy more work is not, so nobody is watching
    // for it. A bell icon alone is how the warmest lead a company gets goes
    // cold over a weekend.
    //
    // Recipients are the Company Admin and, when the client has an account
    // manager, them too — they own the relationship and are the one who will
    // actually call.
    //
    // Best-effort throughout: the request and its Lead are already committed
    // by this point, and a mail server being down must not lose either.
    private static function notifyCompany(Client $client, ServiceRequest $serviceRequest, string $serviceName, string $intentLabel): void
    {
        $adminId = Company::find($client->company_id)?->admin_id;

        try {
            // "What does this client actually want from us?" — rarely just
            // the card they clicked a second ago. Someone who asked about SEO
            // on Monday and hosting today is one conversation, and the admin
            // picking this email up has to see both to quote either properly.
            //
            // Still-open only (a closed request is history, not an ask), this
            // one excluded since it is already the subject, de-duplicated
            // because a client re-asking for the same service should not pad
            // the list, and capped so a long-running account cannot turn the
            // email into a wall of text.
            //
            // Inside the try on purpose: this is a nicety on an email whose
            // request and Lead are already committed, so it must not be the
            // thing that fails the client's response.
            $alsoOpen = ServiceRequest::where('client_id', $client->id)
                ->whereIn('status', ['new', 'in_progress'])
                ->whereKeyNot($serviceRequest->id)
                ->orderByDesc('created_at')
                ->pluck('service_name')
                ->unique()
                ->take(5)
                ->values()
                ->all();

            EventMailService::serviceRequested(
                $client,
                $serviceName,
                $intentLabel,
                $serviceRequest->message,
                $serviceRequest->preferred_date?->toFormattedDateString(),
                $serviceRequest->lead_id,
                $alsoOpen,
                // EventMailService de-duplicates by address, so an account
                // manager who is also somehow the admin still gets one email.
                array_values(array_filter([
                    $adminId ? ['admin_id' => $adminId] : null,
                    $client->account_manager ? ['user_id' => $client->account_manager] : null,
                ])),
            );
        } catch (\Throwable $e) {
            report($e);
        }

        // data.link, not the url column: that is what the frontend bell reads
        // (see the same note in Services\InvoiceNotificationService).
        $payload = [
            'company_id'  => $client->company_id,
            'type'        => 'service_request',
            'title'       => "{$intentLabel}: {$serviceName}",
            'body'        => "{$client->name} requested {$serviceName} from the Client Portal.",
            'entity_type' => 'ServiceRequest',
            'entity_id'   => $serviceRequest->id,
            'data'        => [
                'service_request_id' => $serviceRequest->id,
                'link'               => $serviceRequest->lead_id ? "/leads/{$serviceRequest->lead_id}" : '/leads',
            ],
        ];

        try {
            if ($adminId) {
                Notification::create($payload + ['recipient_admin_id' => $adminId]);
            }

            if ($client->account_manager) {
                Notification::create($payload + ['user_id' => $client->account_manager]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
