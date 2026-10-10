<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Audit trail for Api\Admin\ClientController::transfer() — same shape as
// lead_transfers (2026_07_18_120000_create_lead_transfers_table.php), which
// this mirrors: a Company Admin reassigning a client's account_manager.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_transfers')) {
            return;
        }

        Schema::create('client_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('from_user_id')->nullable();
            $table->unsignedBigInteger('to_user_id');
            $table->unsignedBigInteger('transferred_by_user_id')->nullable();
            $table->string('transferred_by_admin_name')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_transfers');
    }
};
