<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment gateways move back from per-tenant to per-company ownership — the
 * reverse of 2026_07_17_130000_add_tenant_scope_to_company_payment_gateways,
 * which had collapsed every company under one Company Admin onto one shared
 * set of gateway accounts. Settings is per-company now (see
 * 2026_09_11_100000_backfill_per_company_settings), and a shared gateway
 * would have been the one tab where "change Company A, Company B changes
 * too" still held.
 *
 * NO SCHEMA CHANGE IS NEEDED:
 *   • `company_id` already exists, nullable — it was the original ownership
 *     column, kept as a legacy read path by the 2026_07_17 migration.
 *   • the (company_admin_id, gateway) unique index was already dropped by
 *     2026_08_07_100000_add_multi_account_support, so one tenant may already
 *     hold several rows of the same gateway type. Per-company rows need
 *     exactly that freedom.
 *
 * What this does is FORK: every tenant-level row (company_id IS NULL) is
 * copied once per company the tenant owns, with company_id set. The original
 * tenant row is deliberately left in place — CompanyPaymentGateway::
 * resolveActiveGateways() still falls back to it for any company that somehow
 * has no row of its own, and deleting a row that a confirmed Payment already
 * points at (payments.company_gateway_id) would orphan payment history.
 *
 * company_admin_id stays populated on every forked row. That is what keeps
 * the webhook URLs that Company Admins have ALREADY pasted into live Stripe/
 * PayPal/Authorize.net dashboards working: /webhooks/{gateway}/{adminId}
 * resolves by company_admin_id (see Api\Webhooks\PaymentWebhookController),
 * and a tenant whose companies now each have their own copy resolves to
 * several candidates — which that controller handles by verifying the
 * signature against each candidate rather than guessing one.
 *
 * `config` is copied as the raw stored string. The model casts it
 * `encrypted:array`, so the column holds ciphertext; moving it through
 * DB::table() (no casts) copies it verbatim rather than decrypting and
 * re-encrypting it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('company_payment_gateways')) {
            return;
        }

        $companiesByAdmin = DB::table('companies')
            ->select('id', 'admin_id')
            ->get()
            ->groupBy('admin_id');

        $tenantRows = DB::table('company_payment_gateways')
            ->whereNull('company_id')
            ->whereNotNull('company_admin_id')
            ->orderBy('id')
            ->get();

        foreach ($tenantRows as $row) {
            foreach ($companiesByAdmin[$row->company_admin_id] ?? [] as $company) {
                // A company that already owns a row for this gateway type is
                // either a pre-2026_07_17 legacy row or a re-run of this
                // migration — either way it already has its own configuration
                // and must not be overwritten or duplicated.
                $alreadyOwns = DB::table('company_payment_gateways')
                    ->where('company_id', $company->id)
                    ->where('gateway', $row->gateway)
                    ->exists();

                if ($alreadyOwns) {
                    continue;
                }

                DB::table('company_payment_gateways')->insert([
                    'company_admin_id' => $row->company_admin_id,
                    'company_id'       => $company->id,
                    'gateway'          => $row->gateway,
                    'label'            => $row->label,
                    'is_active'        => $row->is_active,
                    'is_default'       => $row->is_default,
                    'config'           => $row->config,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }
        }

        $this->repointPinnedInvoices();
    }

    /**
     * An invoice may be pinned to one specific gateway ACCOUNT (the
     * invoice_payment_gateways pivot, written when a user holding
     * canSelectInvoiceGateway picked accounts explicitly). Those pins point
     * at tenant-level row ids, which resolveActiveGateways() no longer
     * returns for a company that has its own rows — so an already-issued,
     * still-unpaid invoice would stop offering its chosen gateway.
     *
     * Repoint each such pin at the forked copy belonging to that invoice's
     * OWN company. Payments already taken are untouched: payments.
     * company_gateway_id still references the original row, which this
     * migration never deletes.
     */
    private function repointPinnedInvoices(): void
    {
        if (!Schema::hasTable('invoice_payment_gateways')) {
            return;
        }

        $pins = DB::table('invoice_payment_gateways as pivot')
            ->join('company_payment_gateways as gw', 'gw.id', '=', 'pivot.company_gateway_id')
            ->join('invoices as inv', 'inv.id', '=', 'pivot.invoice_id')
            ->whereNull('gw.company_id')
            ->select('pivot.invoice_id', 'pivot.company_gateway_id', 'gw.gateway', 'inv.company_id as invoice_company_id')
            ->get();

        foreach ($pins as $pin) {
            if (!$pin->invoice_company_id) {
                continue;
            }

            $replacementId = DB::table('company_payment_gateways')
                ->where('company_id', $pin->invoice_company_id)
                ->where('gateway', $pin->gateway)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->value('id');

            if (!$replacementId) {
                continue;
            }

            // The invoice may already be pinned to the replacement (two
            // tenant rows of the same type collapsing onto one company row) —
            // drop the stale pin rather than colliding on the pivot.
            $alreadyPinned = DB::table('invoice_payment_gateways')
                ->where('invoice_id', $pin->invoice_id)
                ->where('company_gateway_id', $replacementId)
                ->exists();

            $stalePin = DB::table('invoice_payment_gateways')
                ->where('invoice_id', $pin->invoice_id)
                ->where('company_gateway_id', $pin->company_gateway_id);

            $alreadyPinned
                ? $stalePin->delete()
                : $stalePin->update(['company_gateway_id' => $replacementId]);
        }
    }

    /**
     * Removes only the forked rows — a per-company row whose tenant-level
     * original still exists, carries the same gateway type, and which no
     * Payment has ever been recorded against. Anything a payment points at is
     * kept so payment history never loses the account it was taken on.
     */
    public function down(): void
    {
        if (!Schema::hasTable('company_payment_gateways')) {
            return;
        }

        $tenantPairs = DB::table('company_payment_gateways')
            ->whereNull('company_id')
            ->whereNotNull('company_admin_id')
            ->get()
            ->map(fn ($r) => "{$r->company_admin_id}:{$r->gateway}")
            ->unique()
            ->all();

        DB::table('company_payment_gateways')
            ->whereNotNull('company_id')
            ->whereNotNull('company_admin_id')
            ->orderBy('id')
            ->get()
            ->each(function ($row) use ($tenantPairs) {
                if (!in_array("{$row->company_admin_id}:{$row->gateway}", $tenantPairs, true)) {
                    return;
                }
                if (DB::table('payments')->where('company_gateway_id', $row->id)->exists()) {
                    return;
                }
                DB::table('company_payment_gateways')->where('id', $row->id)->delete();
            });
    }
};
