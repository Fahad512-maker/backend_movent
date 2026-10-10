<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Whose name an invoice goes out under. 'company' is the tenant's own
// identity (Settings > Company — the existing behaviour, hence the default,
// so every invoice already raised keeps looking exactly as it does today).
// 'brand' bills under one of the company's Brands instead: its own name,
// logo and contact details replace the company's wherever the invoice is
// shown — detail screen, PDF, email, share link, payment page and the client
// portal (see Invoice::brandingProfile()).
//
// nullOnDelete on brand_id, and Brand rows are soft-deleted anyway, so an
// invoice raised under a brand that is later removed falls back to the
// company's branding rather than breaking.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'invoice_type')) {
                $table->enum('invoice_type', ['company', 'brand'])->default('company')->after('company_id');
            }
            if (!Schema::hasColumn('invoices', 'brand_id')) {
                $table->foreignId('brand_id')->nullable()->after('invoice_type')
                    ->constrained('brands')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'brand_id')) {
                $table->dropConstrainedForeignId('brand_id');
            }
            if (Schema::hasColumn('invoices', 'invoice_type')) {
                $table->dropColumn('invoice_type');
            }
        });
    }
};
