<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A Seller's reply to the one internal comment they were tagged into must
// stay restricted to Company Admin/Project Manager — visible to neither the
// rest of the internal team nor the client, unlike the existing 'client'
// tier. parent_comment_id lets the frontend's "Reply" action link that reply
// back to the comment being answered (project_comments had no threading
// concept at all before this).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_comments', function (Blueprint $table) {
            if (!Schema::hasColumn('project_comments', 'parent_comment_id')) {
                $table->foreignId('parent_comment_id')->nullable()->after('deliverable_id')
                    ->constrained('project_comments')->nullOnDelete();
            }
        });

        // MySQL enums can't be widened via Blueprint::enum() without
        // redefining the whole column — raw ALTER mirrors the same approach
        // used for other enum columns in this app's migrations.
        DB::statement("ALTER TABLE project_comments MODIFY visibility ENUM('internal','client','seller_reply') DEFAULT 'internal'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE project_comments MODIFY visibility ENUM('internal','client') DEFAULT 'internal'");

        Schema::table('project_comments', function (Blueprint $table) {
            if (Schema::hasColumn('project_comments', 'parent_comment_id')) {
                $table->dropConstrainedForeignId('parent_comment_id');
            }
        });
    }
};
