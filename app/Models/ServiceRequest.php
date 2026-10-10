<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A client asking for one of their company's services from the Client Portal.
// See the create_service_requests migration for why the three intents share
// one table, and why "Contact Support" is not one of them.
class ServiceRequest extends Model
{
    protected $fillable = [
        'company_id', 'client_id', 'company_service_id', 'service_name',
        'intent', 'message', 'preferred_date', 'status', 'lead_id',
    ];

    protected $casts = [
        'preferred_date' => 'date',
    ];

    // What the client wants to happen next. The label is what the company's
    // staff read on the request list and on the Lead this raises, so it says
    // who has to do something rather than restating the button's name.
    public const INTENTS = [
        'quote'        => 'Quote requested',
        'new_project'  => 'Wants to start a project',
        'consultation' => 'Consultation requested',
    ];

    public const STATUSES = ['new', 'in_progress', 'closed'];

    public function intentLabel(): string
    {
        return self::INTENTS[$this->intent] ?? $this->intent;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function service(): BelongsTo
    {
        // withTrashed(): the request has to keep resolving to the service it
        // was about even after the admin removes that service.
        return $this->belongsTo(CompanyService::class, 'company_service_id')->withTrashed();
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
