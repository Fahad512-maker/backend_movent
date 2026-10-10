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
        if (Schema::hasTable('chat_threads')) return;

        Schema::create('chat_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->enum('thread_type', ['direct', 'group', 'client', 'project', 'support'])->default('direct');
            $table->string('title', 255)->nullable();
            $table->string('linked_to_type', 50)->nullable();
            $table->unsignedBigInteger('linked_to_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_threads');
    }
};
