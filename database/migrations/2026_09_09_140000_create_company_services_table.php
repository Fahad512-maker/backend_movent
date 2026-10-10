<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The IT services one company offers its clients, shown in the "Grow Your
// Business With Us" section of the Client Portal.
//
// A row is written only when a Company Admin actually decides something about
// a service — enables it, renames it, prices it, reorders it. The 37 built-in
// services live in code (App\Services\ServiceCatalog), so this table holds
// decisions rather than 37 untouched defaults per tenant, and adding a new
// catalogue entry needs no backfill.
//
// slug: the catalogue key this row customises, or NULL for a service the admin
// invented themselves. Custom rows take no part in the recommendation rules,
// which are keyed on catalogue slugs.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('slug', 80)->nullable();
            $table->string('name', 150);
            $table->string('short_description', 500)->nullable();
            $table->string('icon_path', 500)->nullable();
            // Optional — "from PKR 50,000". Currency is the company's own (see
            // Company::invoicingProfile()), never stored per service: one
            // company does not price different services in different money.
            $table->decimal('starting_price', 12, 2)->nullable();
            // Only enabled services reach the Client Portal. Default false so
            // enabling is always a deliberate act — a company should never
            // find services it never reviewed being advertised to its clients.
            $table->boolean('is_enabled')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('display_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by_admin_id')->references('id')->on('company_admins')->nullOnDelete();

            // One row per catalogue service per company. Not unique on name:
            // a custom service may legitimately share a name with a renamed
            // catalogue one, and soft-deleted rows would block re-adding.
            $table->unique(['company_id', 'slug']);
            $table->index(['company_id', 'is_enabled', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_services');
    }
};
