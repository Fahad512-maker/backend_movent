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
        if (Schema::hasTable('chat_messages')) return;

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('chat_threads')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('content')->nullable();
            $table->enum('message_type', ['text', 'file', 'image', 'system'])->default('text');
            $table->string('attachment_path', 600)->nullable();
            $table->string('attachment_name', 255)->nullable();
            $table->unsignedBigInteger('forwarded_from_id')->nullable();
            $table->tinyInteger('is_deleted')->default(0);
            $table->timestamp('sent_at')->nullable();

            $table->foreign('forwarded_from_id')->references('id')->on('chat_messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
