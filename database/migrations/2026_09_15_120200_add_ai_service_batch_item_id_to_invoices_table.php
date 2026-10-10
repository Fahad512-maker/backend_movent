<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fully additive — every existing invoice has this NULL and keeps working
// untouched. Set by the Admin/seller at invoice-creation time when the
// invoice is explicitly for one of a client's outstanding AI-suggested
// services (see App\Services\ClientAiServiceBatchService::markTakenIfPaid(),
// which flips that item to 'taken' once this invoice is paid/partially paid).
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('invoices', 'ai_service_batch_item_id')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->unsignedBigInteger('ai_service_batch_item_id')->nullable()->after('project_reference');
                $table->foreign('ai_service_batch_item_id')
                    ->references('id')->on('client_ai_service_batch_items')
                    ->nullOnDelete();
                $table->index('ai_service_batch_item_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'ai_service_batch_item_id')) {
                $table->dropForeign(['ai_service_batch_item_id']);
                $table->dropColumn('ai_service_batch_item_id');
            }
        });
    }
};
