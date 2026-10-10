<?php

use App\Models\CompanyPaymentGateway;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Lets a tenant hold MULTIPLE accounts of the same gateway type (e.g. 2
// Stripe accounts) instead of today's exactly-one-row-per-type. Purely
// additive: existing rows are backfilled with a label + is_default=true so
// every currently-live single-account tenant behaves identically afterward
// (see CompanyPaymentGateway::defaultAccountForType() / the webhook route,
// both of which lean on is_default for backward compatibility).
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('company_payment_gateways')) {
            return;
        }

        if (!Schema::hasColumn('company_payment_gateways', 'label')) {
            Schema::table('company_payment_gateways', function (Blueprint $table) {
                $table->string('label')->nullable()->after('gateway');
            });
        }

        if (!Schema::hasColumn('company_payment_gateways', 'is_default')) {
            Schema::table('company_payment_gateways', function (Blueprint $table) {
                $table->boolean('is_default')->default(false)->after('is_active');
            });
        }

        $indexes = DB::select("SHOW INDEX FROM company_payment_gateways WHERE Key_name = 'company_payment_gateways_company_admin_id_gateway_unique'");
        if (!empty($indexes)) {
            // The composite unique index is the only index covering
            // company_admin_id, and that column carries a foreign key — MySQL
            // requires a supporting index on any FK column, so a plain index
            // must exist before the unique one can be dropped.
            $plainIndex = DB::select("SHOW INDEX FROM company_payment_gateways WHERE Key_name = 'company_payment_gateways_company_admin_id_index'");
            if (empty($plainIndex)) {
                Schema::table('company_payment_gateways', function (Blueprint $table) {
                    $table->index('company_admin_id');
                });
            }

            Schema::table('company_payment_gateways', function (Blueprint $table) {
                $table->dropUnique('company_payment_gateways_company_admin_id_gateway_unique');
            });
        }

        DB::table('company_payment_gateways')->whereNull('label')->orderBy('id')->get()->each(function ($row) {
            DB::table('company_payment_gateways')->where('id', $row->id)->update([
                'label'      => CompanyPaymentGateway::GATEWAYS[$row->gateway] ?? ucfirst($row->gateway),
                'is_default' => true,
            ]);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('company_payment_gateways')) {
            return;
        }

        if (Schema::hasColumn('company_payment_gateways', 'label')) {
            Schema::table('company_payment_gateways', function (Blueprint $table) {
                $table->dropColumn('label');
            });
        }

        if (Schema::hasColumn('company_payment_gateways', 'is_default')) {
            Schema::table('company_payment_gateways', function (Blueprint $table) {
                $table->dropColumn('is_default');
            });
        }
    }
};
