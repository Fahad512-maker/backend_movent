<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Idempotency ledger — one row per processed webhook event id per gateway.
// See migration 2026_07_17_130002_create_payment_gateway_webhook_events_table.
class PaymentGatewayWebhookEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['gateway', 'event_id'];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
