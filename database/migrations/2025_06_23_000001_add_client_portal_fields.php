<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guarded per-column/table — some deployments' database was seeded
        // from a raw SQL snapshot that may already include some of these
        // before migrations tracking began.
        if (!Schema::hasColumn('documents', 'is_visible_to_client')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->boolean('is_visible_to_client')->default(false)->after('is_shared');
            });
        }

        if (!Schema::hasColumn('support_tickets', 'category')) {
            Schema::table('support_tickets', function (Blueprint $table) {
                $table->enum('category', ['billing', 'technical', 'project', 'general'])->default('general')->after('subject');
            });
        }

        if (!Schema::hasTable('support_ticket_replies')) {
            Schema::create('support_ticket_replies', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
                $table->foreignId('replied_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('message');
                $table->string('attachment_path', 600)->nullable();
                $table->string('attachment_name', 255)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_replies');
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn('category');
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('is_visible_to_client');
        });
    }
};
