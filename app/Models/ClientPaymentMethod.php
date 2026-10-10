<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A card a portal client has saved with one company's gateway.
 *
 * Holds gateway references only — never a card number or CVV. `card_brand` and
 * `card_last4` exist so a client can tell their cards apart and for nothing
 * else; they cannot be used to charge anything.
 */
class ClientPaymentMethod extends Model
{
    protected $fillable = [
        'client_id', 'company_id', 'gateway',
        'gateway_customer_id', 'payment_method_token',
        'card_brand', 'card_last4', 'exp_month', 'exp_year', 'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'exp_month'  => 'integer',
        'exp_year'   => 'integer',
    ];

    /**
     * Never serialise the gateway references to a client. They are not secrets
     * in the card sense, but they are the handles that move money, and no
     * screen needs them — hiding them here means no future endpoint can leak
     * them by accident.
     */
    protected $hidden = ['gateway_customer_id', 'payment_method_token'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** "Visa •••• 4242" — the only way a saved card is ever described. */
    public function label(): string
    {
        $brand = $this->card_brand ? ucfirst($this->card_brand) : 'Card';

        return $this->card_last4 ? "{$brand} •••• {$this->card_last4}" : $brand;
    }

    /**
     * Expired cards stay in the table (the gateway may still hold them, and
     * deleting silently would be confusing) but must not be offered for a
     * charge.
     */
    public function isExpired(): bool
    {
        if (!$this->exp_year || !$this->exp_month) {
            return false;
        }

        return now()->startOfMonth()->greaterThan(
            now()->setDate($this->exp_year, $this->exp_month, 1)->startOfMonth()
        );
    }
}
