<?php

namespace App\Support;

use App\Models\Company;
use App\Models\CompanyAdmin;
use App\Models\CompanyDealSettings;
use App\Models\CompanyPaymentGateway;
use App\Rules\ValidPhoneNumber;
use Illuminate\Support\Facades\Storage;

/**
 * The one definition of what "company settings" ARE — the read payload, the
 * validation rules, and the write mapping for each tab.
 *
 * Two controllers serve exactly the same settings to two different callers:
 *   • Api\Admin\SettingsController — the Company Admin, on the `admin` guard,
 *     for whichever of their companies is selected.
 *   • Api\User\SettingsController — a staff member the Company Admin granted
 *     the account module's `canManageSettings` permission for, for their own
 *     active company.
 *
 * Keeping the payload here rather than duplicating it means a staff member
 * with the permission genuinely manages the same settings the Company Admin
 * does, and a new field can never appear on one screen but not the other.
 * The controllers keep what is genuinely theirs: who is allowed in, and which
 * company the request resolves to.
 *
 * Everything here is per-company (2026-09-11) — see
 * Api\Admin\SettingsController's header for the full history.
 */
class SettingsPayload
{
    // ── Validation rules ────────────────────────────────────────────────────
    //
    // Methods rather than consts throughout: `phone` needs a ValidPhoneNumber
    // INSTANCE (a const can only hold a class-STRING, which Laravel would try
    // to resolve as a rule name like 'required' and reject), and the `in:`
    // lists must be derived from CompanyPaymentGateway::GATEWAYS /
    // CompanyDealSettings::TRIGGERS rather than restated, so adding a gateway
    // type or a trigger can never leave a stale copy behind here.

    public static function companyRules(): array
    {
        return [
            'industry' => ['nullable', 'string', 'max:100'],
            'email'    => ['nullable', 'email', 'max:255'],
            'phone'    => ['nullable', 'string', 'max:30', new ValidPhoneNumber],
            'address'  => ['nullable', 'string', 'max:500'],
            'timezone' => ['nullable', 'string', 'max:60'],
        ];
    }

    public static function invoiceRules(): array
    {
        return [
            'prefix'        => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9\-]+$/'],
            'tax_rate'      => ['required', 'numeric', 'min:0', 'max:100'],
            'payment_terms' => ['required', 'integer', 'min:0', 'max:365'],
            'notes'         => ['nullable', 'string', 'max:1000'],
        ];
    }

    public static function bankRules(): array
    {
        return [
            'bank_name'      => ['nullable', 'string', 'max:100'],
            'account_name'   => ['nullable', 'string', 'max:150'],
            'account_number' => ['nullable', 'string', 'max:50'],
            'iban'           => ['nullable', 'string', 'max:50'],
            'swift'          => ['nullable', 'string', 'max:20'],
        ];
    }

    public static function logoRules(): array
    {
        return ['logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']];
    }

    public static function gatewayStoreRules(): array
    {
        return [
            'gateway_type' => ['required', 'string', 'in:' . implode(',', array_keys(CompanyPaymentGateway::GATEWAYS))],
            'label'        => ['required', 'string', 'max:100'],
            'is_active'    => ['required', 'boolean'],
            'is_default'   => ['nullable', 'boolean'],
            'config'       => ['nullable', 'array'],
        ];
    }

    public static function gatewayUpdateRules(): array
    {
        return [
            'label'     => ['required', 'string', 'max:100'],
            'is_active' => ['required', 'boolean'],
            'config'    => ['nullable', 'array'],
        ];
    }

    public static function dealWorkflowRules(): array
    {
        return [
            'project_creation_trigger' => ['required', 'string', 'in:' . implode(',', array_keys(CompanyDealSettings::TRIGGERS))],
            'allow_admin_override'     => ['required', 'boolean'],
            // 'sometimes': an older client that only knows about the trigger
            // must not silently reset the payment policy to the default.
            'payment_policy'           => ['sometimes', 'string', 'in:' . implode(',', array_keys(CompanyDealSettings::PAYMENT_POLICIES))],
        ];
    }

    // ── Read payload ────────────────────────────────────────────────────────

    /**
     * Everything the Settings screen renders for one company. Reads the
     * company's OWN columns via invoicingProfile(), which is company-first
     * and only falls back to the tenant row for a field this company has
     * never had set.
     */
    public static function forCompany(Company $company): array
    {
        $profile = $company->invoicingProfile();

        return [
            'company' => [
                'name'     => $company->name,
                'industry' => $company->industry,
                'email'    => $company->email,
                'phone'    => $company->phone,
                'address'  => $company->address,
                'timezone' => $company->timezone ?: 'Asia/Karachi',
                'logo_url' => PublicFile::url($profile['logo_path']),
            ],
            'invoice' => [
                'prefix'        => $profile['invoice_prefix'],
                'tax_rate'      => (float) $profile['invoice_tax_rate'],
                'payment_terms' => (int) $profile['invoice_payment_terms'],
                'notes'         => $profile['invoice_notes'] ?? '',
            ],
            'bank' => [
                'bank_name'      => $profile['bank_name']           ?? '',
                'account_name'   => $profile['bank_account_name']   ?? '',
                'account_number' => $profile['bank_account_number'] ?? '',
                'iban'           => $profile['bank_iban']            ?? '',
                'swift'          => $profile['bank_swift']           ?? '',
            ],
            'gateways'      => self::gatewayList($company),
            'gateway_types' => CompanyPaymentGateway::GATEWAYS,
            // True when this company owns no gateway account of its own but is
            // still taking payments through the tenant-level fallback rows
            // resolveActiveGateways() drops back to. Without surfacing it the
            // Gateways tab reads "none configured" while the company's invoices
            // are demonstrably payable, which looks like a bug. Adding an
            // account here is what takes the company off the shared fallback.
            'gateways_inherited' => self::hasInheritedGateways($company),
        ];
    }

    private static function hasInheritedGateways(Company $company): bool
    {
        if (CompanyPaymentGateway::where('company_id', $company->id)->exists()) {
            return false;
        }

        return CompanyPaymentGateway::where('company_admin_id', $company->admin_id)
            ->whereNull('company_id')
            ->where('is_active', true)
            ->exists();
    }

    /**
     * This company's own gateway ACCOUNTS (a company can hold more than one
     * of the same gateway type, e.g. 2 Stripe accounts). Deliberately does
     * NOT include the tenant-level fallback rows resolveActiveGateways()
     * reads for a company with none of its own — letting one company's
     * Settings edit a row every other company is still reading would put the
     * shared-configuration bug straight back.
     */
    public static function gatewayList(Company $company): array
    {
        return CompanyPaymentGateway::ownedByCompany($company->id)
            ->map(fn ($row) => [
                'id'           => $row->id,
                'gateway_type' => $row->gateway,
                'label'        => $row->label ?: (CompanyPaymentGateway::GATEWAYS[$row->gateway] ?? $row->gateway),
                'mode'         => $row->config['mode'] ?? 'sandbox',
                'is_active'    => $row->is_active,
                'is_default'   => $row->is_default,
                'config'       => self::maskConfig($row->gateway, $row->config ?? []),
                'webhook_url'  => self::webhookUrl($row),
            ])
            ->values()
            ->toArray();
    }

    /**
     * Seeds a brand-new company's invoice defaults, bank details and logo from
     * a sibling company of the same tenant — a one-time COPY, after which the
     * two are fully independent.
     *
     * Only fields the "Add Company" form does not ask for are touched, so
     * anything the admin typed (name, industry, email, phone, address,
     * timezone, country) always wins. Called alongside the existing
     * copy-the-modules step in Api\Admin\ClientController::storeCompany().
     *
     * Without this, settings being per-company would mean every new company
     * started at the raw column defaults (prefix 'INV', 0% tax, 30-day terms,
     * no bank details, no logo) and the admin had to retype their whole
     * invoicing profile per company — the copy keeps the convenience of the
     * old shared configuration without restoring the shared WRITE that was
     * the actual bug.
     */
    public static function seedFromSibling(Company $company, Company $sibling): void
    {
        $profile = $sibling->invoicingProfile();

        $company->update([
            'logo_path'             => $profile['logo_path'],
            'invoice_prefix'        => $profile['invoice_prefix'],
            'invoice_tax_rate'      => $profile['invoice_tax_rate'],
            'invoice_payment_terms' => $profile['invoice_payment_terms'],
            'invoice_notes'         => $profile['invoice_notes'],
            'bank_name'             => $profile['bank_name'],
            'bank_account_name'     => $profile['bank_account_name'],
            'bank_account_number'   => $profile['bank_account_number'],
            'bank_iban'             => $profile['bank_iban'],
            'bank_swift'            => $profile['bank_swift'],
        ]);
    }

    /**
     * Replaces one company's logo, returning the new stored path.
     *
     * The old file is deleted only when NOTHING else still points at it. That
     * guard matters because 2026_09_11_100000_backfill_per_company_settings
     * copied the tenant's logo_path into every company row, so straight after
     * migrating, all of an admin's companies reference the SAME file — and a
     * plain "delete the old path" would have made uploading Company A's own
     * logo wipe the image Company B (and the tenant row) were still showing.
     */
    public static function replaceLogo(Company $company, \Illuminate\Http\UploadedFile $file): string
    {
        $old = $company->logo_path;

        $path = $file->store('companies/' . $company->id . '/logo', 'public');

        $company->update(['logo_path' => $path]);

        if ($old && $old !== $path && !self::logoStillReferenced($old, $company->id)) {
            // Storage::disk('public') explicitly: the file lives on the public
            // disk but FILESYSTEM_DISK defaults to 'local', so the bare
            // Storage::exists() this replaced checked a disk the logo was
            // never written to and no replaced logo was ever actually deleted.
            if (Storage::disk('public')->exists($old)) {
                Storage::disk('public')->delete($old);
            }
        }

        return $path;
    }

    private static function logoStillReferenced(string $path, int $exceptCompanyId): bool
    {
        $otherCompany = Company::where('logo_path', $path)
            ->where('id', '!=', $exceptCompanyId)
            ->exists();

        if ($otherCompany) {
            return true;
        }

        // The tenant row is still the fallback Company::invoicingProfile()
        // reads for a company that has no logo of its own, so a file it points
        // at is still in use even when no company row references it.
        return CompanyAdmin::where('logo_path', $path)->exists();
    }

    /**
     * The URL to paste into this account's gateway dashboard.
     *
     * Always the ACCOUNT-SCOPED 4-segment form. The shorter
     * /webhooks/{gateway}/{company_admin_id} form still works and is still
     * honoured for anything already pasted into a live dashboard, but it can
     * now name several accounts at once — one per company under the same
     * tenant — so it is no longer the URL to hand out. See
     * Api\Webhooks\PaymentWebhookController.
     *
     * Built here rather than in the frontend because the frontend has no way
     * to know company_admin_id: it used the logged-in account's own id, which
     * is the Company Admin's id on the admin screen but a plain staff user's
     * id on the staff screen, and would have produced a URL pointing at the
     * wrong tenant (or nothing at all).
     */
    public static function webhookUrl(CompanyPaymentGateway $row): string
    {
        return url("/api/webhooks/{$row->gateway}/{$row->company_admin_id}/{$row->id}");
    }

    public static function dealWorkflow(Company $company): array
    {
        $settings = CompanyDealSettings::forCompany($company->id);

        return [
            'project_creation_trigger' => $settings->project_creation_trigger,
            'allow_admin_override'     => (bool) $settings->allow_admin_override,
            'triggers'                 => CompanyDealSettings::TRIGGERS,
            // The Payment Policy — a separate question from the trigger above
            // (see CompanyDealSettings::PAYMENT_POLICIES): the trigger says
            // when a payment starts the project, this says whether a client
            // may part-pay at all.
            'payment_policy'           => $settings->payment_policy ?: 'partial_allowed',
            'payment_policies'         => CompanyDealSettings::PAYMENT_POLICIES,
        ];
    }

    // Mask secret fields so they are not sent to the frontend in plaintext.
    public static function maskConfig(string $gateway, array $config): array
    {
        $secretFields = [
            'paypal'        => ['client_secret'],
            'stripe'        => ['secret_key', 'webhook_secret'],
            'authorize_net' => ['transaction_key', 'signature_key'],
        ];

        foreach ($secretFields[$gateway] ?? [] as $field) {
            if (!empty($config[$field])) {
                $config[$field] = '••••••••';
            }
        }

        return $config;
    }

    // Merge config — don't overwrite secrets if the frontend sent back the
    // masked placeholder instead of a real value.
    public static function mergeConfig(array $oldConfig, array $newConfig): array
    {
        foreach ($newConfig as $k => $v) {
            if ($v === '••••••••') {
                $newConfig[$k] = $oldConfig[$k] ?? '';
            }
        }

        return $newConfig;
    }

    // ── Write mapping ───────────────────────────────────────────────────────

    public static function companyAttributes(array $validated): array
    {
        return [
            'industry' => $validated['industry'] ?? null,
            'email'    => $validated['email']    ?? null,
            'phone'    => $validated['phone']    ?? null,
            'address'  => $validated['address']  ?? null,
            'timezone' => $validated['timezone'] ?: 'Asia/Karachi',
        ];
    }

    public static function invoiceAttributes(array $validated): array
    {
        return [
            'invoice_prefix'        => strtoupper($validated['prefix']),
            'invoice_tax_rate'      => $validated['tax_rate'],
            'invoice_payment_terms' => $validated['payment_terms'],
            'invoice_notes'         => $validated['notes'] ?? null,
        ];
    }

    public static function bankAttributes(array $validated): array
    {
        return [
            'bank_name'           => $validated['bank_name']      ?? null,
            'bank_account_name'   => $validated['account_name']   ?? null,
            'bank_account_number' => $validated['account_number'] ?? null,
            'bank_iban'           => $validated['iban']           ?? null,
            'bank_swift'          => $validated['swift']          ?? null,
        ];
    }

    /**
     * Adds a gateway account to one company. company_admin_id is written
     * alongside company_id on purpose: it is what keeps the webhook URLs a
     * Company Admin has already pasted into a live gateway dashboard
     * (/webhooks/{gateway}/{company_admin_id}) resolving to this account. See
     * Api\Webhooks\PaymentWebhookController::candidateGatewayRows().
     */
    public static function createGateway(Company $company, array $validated): CompanyPaymentGateway
    {
        $row = CompanyPaymentGateway::create([
            'company_admin_id' => $company->admin_id,
            'company_id'       => $company->id,
            'gateway'          => $validated['gateway_type'],
            'label'            => $validated['label'],
            'is_active'        => $validated['is_active'],
            'is_default'       => false,
            'config'           => $validated['config'] ?? [],
        ]);

        // The first account ever added for a gateway type becomes that type's
        // default automatically — there's nothing else for it to compete
        // with, and requiring a separate "Set as Default" click for a
        // brand-new type would leave every gateway-resolving read path
        // (invoice auto-select, public pay page, webhook fallback) with no
        // default until the admin remembers to do that manually.
        $isFirstOfType = CompanyPaymentGateway::where('company_id', $company->id)
            ->where('gateway', $validated['gateway_type'])
            ->where('id', '!=', $row->id)
            ->doesntExist();

        if (!empty($validated['is_default']) || $isFirstOfType) {
            self::makeDefault($row);
        }

        return $row;
    }

    /**
     * Exactly one default per gateway type PER COMPANY. Scoped by company_id
     * rather than company_admin_id (which is what it was until gateways
     * became per-company) — otherwise promoting Company A's Stripe account
     * would demote Company B's, leaving Company B with no default at all and
     * its invoices/public pay page unable to resolve a gateway.
     */
    public static function makeDefault(CompanyPaymentGateway $row): void
    {
        CompanyPaymentGateway::where('company_id', $row->company_id)
            ->where('gateway', $row->gateway)
            ->where('id', '!=', $row->id)
            ->update(['is_default' => false]);

        $row->update(['is_default' => true]);
    }

    // ── Gateway credential test ─────────────────────────────────────────────

    /**
     * @return array{success: bool, message: string}
     */
    public static function testGateway(CompanyPaymentGateway $row): array
    {
        if (!$row->is_active) {
            return ['success' => false, 'message' => 'Gateway is not enabled. Enable it and save credentials first.'];
        }

        $config = $row->config ?? [];

        $required = [
            'stripe'        => ['publishable_key', 'secret_key'],
            'paypal'        => ['client_id', 'client_secret'],
            'authorize_net' => ['api_login_id', 'transaction_key'],
        ];

        foreach (($required[$row->gateway] ?? []) as $field) {
            if (empty($config[$field])) {
                return ['success' => false, 'message' => "Missing required field: {$field}. Save your credentials first."];
            }
        }

        return self::validateCredentialFormat($row->gateway, $config);
    }

    private static function validateCredentialFormat(string $gateway, array $config): array
    {
        $mode = $config['mode'] ?? 'sandbox';

        switch ($gateway) {
            case 'stripe':
                $pk = $config['publishable_key'] ?? '';
                $sk = $config['secret_key'] ?? '';
                if (!str_starts_with($pk, 'pk_')) {
                    return ['success' => false, 'message' => 'Invalid Publishable Key — must start with pk_test_ or pk_live_'];
                }
                if (!str_starts_with($sk, 'sk_')) {
                    return ['success' => false, 'message' => 'Invalid Secret Key — must start with sk_test_ or sk_live_'];
                }
                if ($mode === 'sandbox' && !str_starts_with($pk, 'pk_test_')) {
                    return ['success' => false, 'message' => 'Sandbox mode selected but key is not a test key (pk_test_...)'];
                }
                return ['success' => true, 'message' => 'Stripe credentials valid (' . $mode . ' mode)'];

            case 'paypal':
                if (strlen($config['client_id'] ?? '') < 10) {
                    return ['success' => false, 'message' => 'PayPal Client ID appears too short'];
                }
                return ['success' => true, 'message' => 'PayPal credentials valid (' . $mode . ' mode)'];

            case 'authorize_net':
                if (strlen($config['api_login_id'] ?? '') < 3) {
                    return ['success' => false, 'message' => 'API Login ID appears invalid'];
                }
                if (strlen($config['transaction_key'] ?? '') < 8) {
                    return ['success' => false, 'message' => 'Transaction Key appears invalid (expected 16 chars)'];
                }
                return ['success' => true, 'message' => 'Authorize.Net credentials valid (' . $mode . ' mode)'];
        }

        return ['success' => true, 'message' => 'Credentials look valid'];
    }
}
