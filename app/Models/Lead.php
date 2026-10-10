<?php

namespace App\Models;

use App\Support\FrontendUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Lead extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'assigned_to', 'name', 'email', 'phone', 'company_name',
        // The credential behind this lead's no-login Sales Chat link. Never
        // set through mass assignment in practice — regenerateChatToken()
        // below is the only writer — but listed so seeders/factories can.
        'chat_token', 'chat_invited_at',
        'source', 'status', 'priority', 'estimated_value', 'notes',
        'next_followup_date', 'next_followup_time', 'lost_reason',
        'transferred_to', 'transferred_at', 'converted_at', 'created_by',
        // Set instead of created_by when a Company Admin raises the lead —
        // Admin is its own guard/table, never a `users` row.
        'created_by_admin_id',
        // Deal fields — a Won lead doubles as a lightweight "Deal" (see
        // App\Services\DealEligibilityService); no separate deals table.
        'deal_reference', 'proposed_project_title', 'service_category',
        'scope_summary', 'detailed_scope', 'quotation_reference',
        'required_kickoff_amount', 'required_kickoff_percentage',
        'expected_start_date', 'expected_end_date', 'fulfillment_status', 'won_at',
    ];

    // chat_token is a bearer credential: whoever holds it can read and reply
    // to this lead's conversation. Every lead endpoint builds its response
    // through an explicit format() array today, so it does not leak — this is
    // the guard against a future ->toArray()/->toJson() on a Lead doing it by
    // accident. Property access ($lead->chat_token) is unaffected, so
    // chatUrl() and the invite endpoints still work.
    protected $hidden = ['chat_token'];

    protected $casts = [
        'estimated_value'   => 'decimal:2',
        'chat_invited_at'   => 'datetime',
        'transferred_at'    => 'datetime',
        'converted_at'      => 'datetime',
        'next_followup_date' => 'date',
        'required_kickoff_amount'     => 'decimal:2',
        'required_kickoff_percentage' => 'decimal:2',
        'expected_start_date'         => 'date',
        'expected_end_date'           => 'date',
        'won_at'                      => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function transferredTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_to');
    }

    public function createdBy(): BelongsTo
    {
        // withTrashed(): User soft-deletes, and "who created this lead" is a
        // historical fact — a since-removed colleague must still resolve to a
        // name rather than falling back to "—".
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'created_by_admin_id');
    }

    public function client(): HasOne
    {
        return $this->hasOne(Client::class, 'lead_id');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class)->orderByDesc('created_at');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(LeadTransfer::class)->orderByDesc('created_at');
    }

    // Every invoice raised against this Deal (see invoices.lead_id) — used
    // by DealEligibilityService to sum verified payments toward the
    // kickoff-amount requirement.
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    // The Project(s) handed off from this Deal (see projects.lead_id).
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    // ── Sales Chat link ──────────────────────────────────────────────────────
    //
    // The emailed, no-login link that lets this Lead talk to their Seller. The
    // token is the ONLY thing the public URL carries (see
    // 2026_09_11_110000_add_chat_token_to_leads_table and
    // Api\PublicLeadChatController) — the lead id never appears in it, so
    // there is nothing to enumerate.

    /**
     * Issues a fresh token, invalidating any link already in circulation.
     *
     * Used both to mint the first one and to revoke-and-replace: because the
     * old token is overwritten, a link that leaked (a forwarded email, a
     * screenshot) stops working the moment this is called. Deliberately does
     * NOT touch chat_invited_at — that records when an invite was last
     * actually delivered, which regenerating a token does not do.
     */
    public function regenerateChatToken(): string
    {
        // Loop guarded by the column's unique index: a repeat would throw on
        // save rather than silently hand two leads the same conversation.
        do {
            $token = Str::random(48);
        } while (static::where('chat_token', $token)->exists());

        $this->update(['chat_token' => $token]);

        return $token;
    }

    /**
     * Kills the link without issuing a replacement — the Lead can no longer
     * reach the conversation at all until someone regenerates it. The
     * conversation itself is untouched: staff keep seeing it in the CRM's
     * Lead → Sales Chat tab, and a later regenerate drops the Lead straight
     * back into the same thread with all its history.
     */
    public function revokeChatToken(): void
    {
        $this->update(['chat_token' => null]);
    }

    /** The token, minting one if this lead predates the column being populated. */
    public function ensureChatToken(): string
    {
        return $this->chat_token ?: $this->regenerateChatToken();
    }

    /** Absolute URL of the Lead-facing Sales Chat page, or null once revoked. */
    public function chatUrl(): ?string
    {
        return $this->chat_token ? FrontendUrl::to('sales-chat/' . $this->chat_token) : null;
    }

    public function logActivity(string $type, string $description, ?string $causerName = null, array $meta = []): void
    {
        $this->activities()->create([
            'company_id'  => $this->company_id,
            'causer_name' => $causerName,
            'type'        => $type,
            'description' => $description,
            'meta'        => $meta ?: null,
        ]);
    }
}
