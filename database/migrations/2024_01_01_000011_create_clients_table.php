<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Some deployments' database was seeded from a raw SQL snapshot that
        // already included this table before migrations tracking began —
        // skip rather than error if it's already there.
        if (Schema::hasTable('clients')) return;

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->unsignedBigInteger('account_manager')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name', 150);
            $table->string('email', 255)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('company_name', 200)->nullable();
            $table->text('address')->nullable();
            $table->tinyInteger('portal_access')->default(0);
            $table->enum('status', ['active', 'inactive', 'blocked'])->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('account_manager')->references('id')->on('users')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
