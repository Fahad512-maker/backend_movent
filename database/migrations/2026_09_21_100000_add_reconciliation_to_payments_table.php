<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Payment reconciliation — the Finance Area's "has a human checked this
// payment against its invoice yet?" state. Deliberately SEPARATE from
// payments.status:
//
//   status              what the MONEY did   (pending/confirmed/failed/refunded)
//   reconciliation_*    what FINANCE did     (has someone verified and signed off)
//
// Squeezing reconciliation into `status` would have lost one of the two every
// time they collide — a confirmed gateway charge is still unreconciled until
// finance ticks it off, and a refunded payment can itself be reconciled. Same
// reasoning as Invoice::paymentStatus() keeping payment state out of
// invoices.status.
//
// Two actor columns, not one, because this app has two guards: staff are
// `users` rows, a Company Admin is a `company_admins` row and can never be a
// users id. Exactly one is ever set — see App\Models\Payment::reconciledByName().
//
// Every existing payment lands on 'unreconciled', which is truthful: nobody
// has reconciled anything yet because the feature did not exist.
//
// recorded_by_admin_id rides along for the same two-guard reason at the OTHER
// end of a payment's life: payments.recorded_by is a `users` id, so every
// payment a Company Admin recorded by hand (Api\Admin\PaymentController::
// store()) has always left it null and the Finance list's "Recorded by"
// column had nothing to show. Existing rows stay null — we cannot know after
// the fact which admin recorded them — and the UI renders those as "System /
// Gateway", which is what they mostly are.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'recorded_by_admin_id')) {
                $table->unsignedBigInteger('recorded_by_admin_id')->nullable()->after('recorded_by');
            }
            if (!Schema::hasColumn('payments', 'reconciliation_status')) {
                $table->enum('reconciliation_status', ['unreconciled', 'reconciled', 'disputed'])
                    ->default('unreconciled')
                    ->after('status');
            }
            if (!Schema::hasColumn('payments', 'reconciled_at')) {
                $table->timestamp('reconciled_at')->nullable()->after('reconciliation_status');
            }
            if (!Schema::hasColumn('payments', 'reconciled_by')) {
                $table->unsignedBigInteger('reconciled_by')->nullable()->after('reconciled_at');
            }
            if (!Schema::hasColumn('payments', 'reconciled_by_admin_id')) {
                $table->unsignedBigInteger('reconciled_by_admin_id')->nullable()->after('reconciled_by');
            }
            if (!Schema::hasColumn('payments', 'reconciliation_note')) {
                $table->text('reconciliation_note')->nullable()->after('reconciled_by_admin_id');
            }
        });

        // Added separately from the column creation above: on a deployment
        // whose columns already exist (a re-run, or a snapshot-seeded DB) the
        // closure above adds nothing and these would otherwise be skipped too.
        Schema::table('payments', function (Blueprint $table) {
            foreach (self::foreignKeys() as $column => $references) {
                if (!self::hasForeignKey('payments', "payments_{$column}_foreign")) {
                    $table->foreign($column)->references('id')->on($references)->nullOnDelete();
                }
            }
        });

        // Index the one column every Finance list/report filters on.
        if (!self::hasIndex('payments', 'payments_reconciliation_status_index')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->index('reconciliation_status');
            });
        }
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            foreach (array_keys(self::foreignKeys()) as $column) {
                if (self::hasForeignKey('payments', "payments_{$column}_foreign")) {
                    $table->dropForeign("payments_{$column}_foreign");
                }
            }
        });

        if (self::hasIndex('payments', 'payments_reconciliation_status_index')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropIndex('payments_reconciliation_status_index');
            });
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'recorded_by_admin_id', 'reconciliation_status', 'reconciled_at',
                'reconciled_by', 'reconciled_by_admin_id', 'reconciliation_note',
            ]);
        });
    }

    /** @return array<string, string> column => referenced table */
    private static function foreignKeys(): array
    {
        return [
            'recorded_by_admin_id'   => 'company_admins',
            'reconciled_by'          => 'users',
            'reconciled_by_admin_id' => 'company_admins',
        ];
    }

    private static function hasForeignKey(string $table, string $name): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $name)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }

    private static function hasIndex(string $table, string $name): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $name)
            ->exists();
    }
};
