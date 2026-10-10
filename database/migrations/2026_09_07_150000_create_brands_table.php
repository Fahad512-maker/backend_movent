<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Brands live under the Invoice module: a tenant that bills under more than
// one trading name keeps each one here (name, contact details, logo) instead
// of editing company Settings before every invoice.
//
// Scoped per COMPANY, not per Company Admin — an admin who owns several
// companies keeps a separate brand list for each, the same rule clients,
// projects and invoices already follow.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('name', 150);
            $table->string('email', 190)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('country', 100)->nullable();
            $table->text('address')->nullable();
            // Stored on the 'public' disk, same as the company logo in
            // Api\Admin\SettingsController::uploadLogo(). Optional.
            $table->string('logo_path')->nullable();

            // Inactive keeps the record and its history but takes the brand
            // out of every picker — deletion is for a brand raised by mistake.
            $table->boolean('is_active')->default(true);

            // Who raised it. A Company Admin isn't a `users` row, hence two
            // separate nullable columns rather than one polymorphic actor —
            // the same split leads/projects already use.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('created_by_admin_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Two brands in one company may not share a name — the name is
            // what appears on the invoice, so a duplicate is always a mistake.
            // Scoped to company_id so another company can still use it.
            $table->unique(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
