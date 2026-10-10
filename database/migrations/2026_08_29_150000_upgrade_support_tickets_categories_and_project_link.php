<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_CATEGORIES = ['billing', 'technical', 'project', 'general'];

    private const NEW_CATEGORIES = [
        'project_support', 'billing_payments', 'technical_issue', 'general_inquiry',
        'account_login', 'file_document_request', 'revision_request', 'bug_report',
        'feature_request', 'other',
    ];

    // Old -> new mapping so existing tickets keep an equivalent category
    // instead of silently landing on a wrong one.
    private const CATEGORY_MAP = [
        'project'   => 'project_support',
        'billing'   => 'billing_payments',
        'technical' => 'technical_issue',
        'general'   => 'general_inquiry',
    ];

    public function up(): void
    {
        // Enum swap must widen first (old + new values both valid) so
        // existing rows never fail validation mid-migration, remap the data,
        // then shrink to the final 10 values only.
        $this->setCategoryEnum(array_unique(array_merge(self::OLD_CATEGORIES, self::NEW_CATEGORIES)), 'general_inquiry');

        foreach (self::CATEGORY_MAP as $old => $new) {
            DB::table('support_tickets')->where('category', $old)->update(['category' => $new]);
        }

        $this->setCategoryEnum(self::NEW_CATEGORIES, 'general_inquiry');

        DB::statement("ALTER TABLE support_tickets MODIFY status ENUM('open','in_progress','on_hold','resolved','closed') DEFAULT 'open'");

        if (!Schema::hasColumn('support_tickets', 'project_id')) {
            Schema::table('support_tickets', function (Blueprint $table) {
                $table->foreignId('project_id')->nullable()->after('category')->constrained('projects')->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('support_tickets', 'invoice_id')) {
            Schema::table('support_tickets', function (Blueprint $table) {
                $table->foreignId('invoice_id')->nullable()->after('project_id')->constrained('invoices')->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('support_tickets', 'payment_reference')) {
            Schema::table('support_tickets', function (Blueprint $table) {
                $table->string('payment_reference', 255)->nullable()->after('invoice_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('support_tickets', 'payment_reference')) {
            Schema::table('support_tickets', fn (Blueprint $table) => $table->dropColumn('payment_reference'));
        }
        if (Schema::hasColumn('support_tickets', 'invoice_id')) {
            Schema::table('support_tickets', function (Blueprint $table) {
                $table->dropForeign(['invoice_id']);
                $table->dropColumn('invoice_id');
            });
        }
        if (Schema::hasColumn('support_tickets', 'project_id')) {
            Schema::table('support_tickets', function (Blueprint $table) {
                $table->dropForeign(['project_id']);
                $table->dropColumn('project_id');
            });
        }

        DB::statement("ALTER TABLE support_tickets MODIFY status ENUM('open','in_progress','resolved','closed') DEFAULT 'open'");

        $reverseMap = array_flip(self::CATEGORY_MAP);
        $unmapped   = array_diff(self::NEW_CATEGORIES, array_keys($reverseMap));

        // Widen first so both the new (unmapped) and old values are valid
        // while remapping back down to the original 4.
        $this->setCategoryEnum(array_unique(array_merge(self::NEW_CATEGORIES, self::OLD_CATEGORIES)), 'general');

        foreach ($unmapped as $extra) {
            DB::table('support_tickets')->where('category', $extra)->update(['category' => 'general']);
        }
        foreach ($reverseMap as $new => $old) {
            DB::table('support_tickets')->where('category', $new)->update(['category' => $old]);
        }

        $this->setCategoryEnum(self::OLD_CATEGORIES, 'general');
    }

    private function setCategoryEnum(array $values, string $default): void
    {
        $list = implode(',', array_map(fn ($v) => "'{$v}'", $values));
        DB::statement("ALTER TABLE support_tickets MODIFY category ENUM({$list}) DEFAULT '{$default}'");
    }
};
