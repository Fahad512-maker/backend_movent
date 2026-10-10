<?php

// Finance Area. ONE set of controllers serves both guards — a Company Admin
// on `auth:admin` and a staff member on `auth:sanctum` — because every
// difference between them (which companies are in scope, whether a
// permission check applies) is resolved by App\Services\Finance\
// FinanceContext rather than by shipping two near-identical controllers the
// way older modules here do. The route groups below are therefore the same
// list twice under different prefixes and different auth middleware, and
// nothing else differs.

use App\Http\Controllers\Api\Finance\DashboardController as FinanceDashboardController;
use App\Http\Controllers\Api\Finance\InvoiceController as FinanceInvoiceController;
use App\Http\Controllers\Api\Finance\InvoiceReminderController as FinanceReminderController;
use App\Http\Controllers\Api\Finance\PaymentController as FinancePaymentController;
use App\Http\Controllers\Api\Finance\PaymentDetailController as FinancePaymentDetailController;
use App\Http\Controllers\Api\Finance\ReportController as FinanceReportController;
use App\Http\Controllers\Api\Admin\ClientController as AdminClientController;
use App\Http\Controllers\Api\Admin\NotificationController as AdminNotificationController;
use App\Http\Controllers\Api\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Api\Admin\BrandController as AdminBrandController;
use App\Http\Controllers\Api\Admin\InvoiceController as AdminInvoiceController;
use App\Http\Controllers\Api\Admin\FollowUpController as AdminFollowUpController;
use App\Http\Controllers\Api\Admin\LeadController as AdminLeadController;
use App\Http\Controllers\Api\Admin\SalesDashboardController as AdminSalesDashboardController;
use App\Http\Controllers\Api\Admin\SalesTargetController as AdminSalesTargetController;
use App\Http\Controllers\Api\Admin\SalesReportController as AdminSalesReportController;
use App\Http\Controllers\Api\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Api\Admin\ProjectController as AdminProjectController;
use App\Http\Controllers\Api\Admin\ComplianceController as AdminComplianceController;
use App\Http\Controllers\Api\Admin\TaskController as AdminTaskController;
use App\Http\Controllers\Api\Admin\TimesheetController as AdminTimesheetController;
use App\Http\Controllers\Api\Admin\ProjectDocumentController as AdminProjectDocumentController;
use App\Http\Controllers\Api\Admin\SupportController as AdminSupportController;
use App\Http\Controllers\Api\User\SupportController as UserSupportController;
use App\Http\Controllers\Api\Admin\ProjectAttachmentController as AdminProjectAttachmentController;
use App\Http\Controllers\Api\Admin\TaskAttachmentController as AdminTaskAttachmentController;
use App\Http\Controllers\Api\Admin\ProjectChatController as AdminProjectChatController;
use App\Http\Controllers\Api\Admin\ProjectMessengerController as AdminProjectMessengerController;
use App\Http\Controllers\Api\Admin\SalesChatController as AdminSalesChatController;
use App\Http\Controllers\Api\Admin\GeneralChatController as AdminGeneralChatController;
use App\Http\Controllers\Api\Admin\DiagnosticsController as AdminDiagnosticsController;
use App\Http\Controllers\Api\Admin\ProfileController as AdminProfileController;
use App\Http\Controllers\Api\Admin\ProjectCommentController as AdminProjectCommentController;
use App\Http\Controllers\Api\Admin\ProjectCommentAttachmentController as AdminProjectCommentAttachmentController;
use App\Http\Controllers\Api\Admin\ProjectReportController as AdminProjectReportController;
use App\Http\Controllers\Api\Admin\ProductionController as AdminProductionController;
use App\Http\Controllers\Api\Admin\ModulePurchaseController as AdminModulePurchaseController;
use App\Http\Controllers\Api\Admin\SeatPurchaseController as AdminSeatPurchaseController;
use App\Http\Controllers\Api\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Api\Admin\SubscriptionPaymentController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\Admin\EmployeeController as AdminEmployeeController;
use App\Http\Controllers\Api\Admin\DepartmentController as AdminDepartmentController;
use App\Http\Controllers\Api\Admin\ShiftController as AdminShiftController;
use App\Http\Controllers\Api\Admin\AttendanceController as AdminAttendanceController;
use App\Http\Controllers\Api\Admin\LeaveController as AdminLeaveController;
use App\Http\Controllers\Api\Admin\PayrollController as AdminPayrollController;
use App\Http\Controllers\Api\Admin\AttendancePolicyController as AdminAttendancePolicyController;
use App\Http\Controllers\Api\Admin\RecruitmentController as AdminRecruitmentController;
use App\Http\Controllers\Api\Admin\HrDashboardController as AdminHrDashboardController;
use App\Http\Controllers\Api\Admin\HrReportController as AdminHrReportController;
use App\Http\Controllers\Api\User\EmployeeController as UserEmployeeController;
use App\Http\Controllers\Api\User\DepartmentController as UserDepartmentController;
use App\Http\Controllers\Api\User\ShiftController as UserShiftController;
use App\Http\Controllers\Api\User\AttendanceController as UserAttendanceController;
use App\Http\Controllers\Api\User\LeaveController as UserLeaveController;
use App\Http\Controllers\Api\User\PayrollController as UserPayrollController;
use App\Http\Controllers\Api\User\AttendancePolicyController as UserAttendancePolicyController;
use App\Http\Controllers\Api\User\RecruitmentController as UserRecruitmentController;
use App\Http\Controllers\Api\User\HrDashboardController as UserHrDashboardController;
use App\Http\Controllers\Auth\AdminAuthController;
use App\Http\Controllers\Auth\AdminForgotPasswordController;
use App\Http\Controllers\Auth\AdminGoogleAuthController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\UserAuthController;
use App\Http\Controllers\Api\Auth\SuperAdminAuthController;
use App\Http\Controllers\Api\InviteController;
use App\Http\Controllers\Api\User\ClientController as UserClientController;
use App\Http\Controllers\Api\User\BrandController as UserBrandController;
use App\Http\Controllers\Api\User\InvoiceController as UserInvoiceController;
use App\Http\Controllers\Api\User\LeadController as UserLeadController;
use App\Http\Controllers\Api\User\SalesDashboardController as UserSalesDashboardController;
use App\Http\Controllers\Api\User\SalesReportController as UserSalesReportController;
use App\Http\Controllers\Api\User\SalesTargetController as UserSalesTargetController;
use App\Http\Controllers\Api\User\FollowUpController as UserFollowUpController;
use App\Http\Controllers\Api\User\ProjectController as UserProjectController;
use App\Http\Controllers\Api\User\ComplianceController as UserComplianceController;
use App\Http\Controllers\Api\User\ProjectReportController as UserProjectReportController;
use App\Http\Controllers\Api\User\TaskController as UserTaskController;
use App\Http\Controllers\Api\User\TimesheetController as UserTimesheetController;
use App\Http\Controllers\Api\User\ProjectChatController as UserProjectChatController;
use App\Http\Controllers\Api\User\ProjectMessengerController as UserProjectMessengerController;
use App\Http\Controllers\Api\User\SalesChatController as UserSalesChatController;
use App\Http\Controllers\Api\User\GeneralChatController as UserGeneralChatController;
use App\Http\Controllers\Api\User\ProfileController as UserProfileController;
use App\Http\Controllers\Api\User\SettingsController as UserSettingsController;
use App\Http\Controllers\Api\User\ProjectAttachmentController as UserProjectAttachmentController;
use App\Http\Controllers\Api\User\TaskAttachmentController as UserTaskAttachmentController;
use App\Http\Controllers\Api\User\ProductionController as UserProductionController;
use App\Http\Controllers\Api\User\UserManagementController;
use App\Http\Controllers\Api\User\ProjectCommentController as UserProjectCommentController;
use App\Http\Controllers\Api\User\ProjectCommentAttachmentController as UserProjectCommentAttachmentController;
    use App\Http\Controllers\Api\User\NotificationController as UserNotificationController;
    use App\Http\Controllers\Api\PublicController;
    use App\Http\Controllers\Api\PublicInvoiceChatController;
    use App\Http\Controllers\Api\PublicLeadChatController;
    use App\Http\Controllers\Api\PublicInvoiceController;
    use App\Http\Controllers\Api\SuperAdmin\CompanyAdminController;
    use App\Http\Controllers\Api\SuperAdmin\CompanyController;
    use App\Http\Controllers\Api\SuperAdmin\DashboardController;
    use App\Http\Controllers\Api\SuperAdmin\ModuleController;
    use App\Http\Controllers\Api\SuperAdmin\PackageController;
    use App\Http\Controllers\Api\SuperAdmin\PaymentGatewayController;
    use App\Http\Controllers\Api\SuperAdmin\NotificationController as SuperAdminNotificationController;
    use App\Http\Controllers\Api\SuperAdmin\ProfileController as SuperAdminProfileController;
    use App\Http\Controllers\Api\SuperAdmin\SubscriptionPolicyController;
    use App\Http\Controllers\Api\SuperAdmin\BillingTermController;
    use Illuminate\Support\Facades\Route;

// TEMPORARY — no-SSH deploy helper. Visit once after uploading .env with the
// correct DB credentials, then DELETE this route. Guarded by a random secret
// so it can't be triggered by guessing the URL. Lives under /api/ (not
// web.php) because this host's proxy only forwards /public/api/* to Laravel.
Route::get('deploy-setup-5f19514504e41e83c743', function () {
    if (request('key') !== '5f19514504e41e83c743eb8e580a121dae3ada538e1f7395') {
        abort(403);
    }

    // On a database restored from a SQL dump (empty `migrations` table) the
    // loop below steps through all ~230 migrations one at a time, which
    // comfortably outruns a stock 30s max_execution_time and would otherwise
    // die half-way, leaving history partially recorded.
    @set_time_limit(600);

    $output = '';

    // PHP's opcache caches compiled bytecode of bootstrap/cache/*.php across
    // requests, independent of whether the file on disk has been deleted or
    // rewritten — on hosts where opcache.validate_timestamps is off (common
    // in production-tuned php.ini), deleting/regenerating the cache file
    // below has no effect on what's actually served until opcache itself is
    // told to drop it. This must run before anything else touches config.
    if (function_exists('opcache_reset')) {
        opcache_reset();
        $output .= "opcache_reset()\n\n";
    }

    // If bootstrap/cache/config.php already existed (e.g. baked in before the
    // real .env was uploaded, or left over from a previous deploy), THIS
    // request already booted with those stale values — Artisan::call('config:clear')
    // below only deletes the file for the NEXT request, which is why the very
    // first run of this endpoint fails with the old/default DB credentials
    // and a plain reload then succeeds. Force a fresh .env read and re-point
    // the mysql connection at it right now, before migrate touches the
    // database, so this endpoint is correct on the very first hit.
    $cachedConfig = base_path('bootstrap/cache/config.php');
    if (is_file($cachedConfig)) {
        @unlink($cachedConfig);
    }
    if (is_file(base_path('.env'))) {
        \Dotenv\Dotenv::createMutable(base_path())->load();
        config([
            'database.connections.mysql.host'     => env('DB_HOST', '127.0.0.1'),
            'database.connections.mysql.port'     => env('DB_PORT', '3306'),
            'database.connections.mysql.database' => env('DB_DATABASE'),
            'database.connections.mysql.username' => env('DB_USERNAME'),
            'database.connections.mysql.password' => env('DB_PASSWORD'),
        ]);
        \Illuminate\Support\Facades\DB::purge('mysql');
        $output .= "reloaded .env and reconnected to database\n\n";
    }

    \Illuminate\Support\Facades\Artisan::call('config:clear');
    $output .= "config:clear\n" . \Illuminate\Support\Facades\Artisan::output() . "\n";

    \Illuminate\Support\Facades\Artisan::call('route:clear');
    $output .= "route:clear\n" . \Illuminate\Support\Facades\Artisan::output() . "\n";

    // Must be checked BEFORE migrate runs — migrate immediately populates the
    // `migrations` table, so this is the only point where "every table is
    // empty" still means "this is a brand-new database".
    if (request()->boolean('fresh')) {
    
    \Illuminate\Support\Facades\Artisan::call('migrate:fresh', [
        '--force' => true,
    ]);

    $output .= "migrate:fresh --force\n";
    $output .= \Illuminate\Support\Facades\Artisan::output() . "\n";

    // migrate:fresh drops and recreates EVERY table, so the database is
    // guaranteed empty at this point — seeding is unconditionally correct
    // here and needs none of the "is it empty?" probing the other branch
    // does. Without this the ?fresh=1 path left a schema with no packages,
    // no modules and no super-admin, i.e. an app nobody could log into.
    \Illuminate\Support\Facades\Artisan::call('db:seed', ['--force' => true]);
    $output .= "db:seed --force (after migrate:fresh)\n"
        . \Illuminate\Support\Facades\Artisan::output() . "\n";

    }
     else {


    $isFreshDatabase = true;
    try {
        $tables = \Illuminate\Support\Facades\DB::select('SHOW TABLES');
        $key = 'Tables_in_' . \Illuminate\Support\Facades\DB::getDatabaseName();
        foreach ($tables as $table) {
            $tableName = $table->$key;

            // `migrations` always has rows once migrate has run at least once
            // on this host — it tracks schema history, not application data,
            // so it must be excluded or the database would never look "fresh"
            // again even when every real table is empty.
            if ($tableName === 'migrations') {
                continue;
            }

            if (\Illuminate\Support\Facades\DB::table($tableName)->count() > 0) {
                $isFreshDatabase = false;
                break;
            }
        }
    } catch (\Throwable) {
        $isFreshDatabase = false; // unsure — safer to skip seeding than to reseed live data
    }

    // Migrations are run ONE AT A TIME rather than via a single
    // `migrate --force`, so that a migration which fails purely because its
    // change is ALREADY PRESENT can be recorded as applied and stepped over
    // instead of aborting the whole deploy.
    //
    // Why this is needed: a database restored from a raw SQL dump arrives
    // with every table already built but an EMPTY `migrations` table, so
    // Laravel re-runs the whole history from the start and dies on the first
    // CREATE TABLE ("1050 Table already exists"). ~57 migrations here already
    // carry their own Schema::hasTable()/hasColumn() guard for exactly this
    // reason — this handles the ~46 that don't, without needing a guard added
    // to each one by hand.
    //
    // Only "the thing is already there" errors are tolerated (1050 table
    // exists, 1060 duplicate column, 1061 duplicate key, 1091 no such
    // column/key to drop). Any other failure still aborts loudly, so a real
    // broken migration is never silently swallowed.
    if (!\Illuminate\Support\Facades\Schema::hasTable('migrations')) {
        \Illuminate\Support\Facades\Artisan::call('migrate:install');
        $output .= "migrate:install\n" . \Illuminate\Support\Facades\Artisan::output() . "\n";
    }

    // Returns a human reason when this failure means "this migration's work
    // is already done / no longer applicable", or null when it's a genuine
    // error that must abort the deploy.
    $skipReason = static function (\Throwable $e): ?string {
        for ($ex = $e; $ex !== null; $ex = $ex->getPrevious()) {
            if (!($ex instanceof \Illuminate\Database\QueryException || $ex instanceof \PDOException)) {
                continue;
            }

            $errno = (int) ($ex->errorInfo[1] ?? 0);

            // The object it wants to create is already there.
            if (in_array($errno, [1050, 1060, 1061, 1091], true)) {
                return 'already present in database';
            }

            // 1265 "Data truncated" on an ALTER ... MODIFY: this migration is
            // pinning a column to the value-set that was current on the day it
            // was written, but the live data has since moved on (e.g. an old
            // lead_activities.type ENUM being replayed after later migrations
            // added 'deal_created'/'company_changed'). That means a LATER
            // migration already superseded this one, so re-applying it would
            // only narrow the column back and destroy data. Restricted to
            // schema-shape statements — a 1265 from an INSERT/UPDATE is real
            // data loss and still aborts.
            if ($errno === 1265) {
                $sql = $ex instanceof \Illuminate\Database\QueryException ? $ex->getSql() : ($ex->getMessage() ?? '');
                if (preg_match('/^\s*ALTER\s+TABLE\b.*\bMODIFY\b/is', $sql)) {
                    return 'superseded by a later migration (column since widened)';
                }
            }
        }

        return null;
    };

    $applied = \Illuminate\Support\Facades\DB::table('migrations')->pluck('migration')->all();
    $pending = collect(glob(database_path('migrations/*.php')))
        ->map(fn ($p) => basename($p, '.php'))
        ->sort()
        ->values()
        ->reject(fn ($m) => in_array($m, $applied, true));

    $batch   = (int) \Illuminate\Support\Facades\DB::table('migrations')->max('batch') + 1;
    $ran     = 0;
    $skipped = [];

    $output .= "migrate (one at a time) — " . $pending->count() . " pending\n";

    foreach ($pending as $name) {
        try {
            \Illuminate\Support\Facades\Artisan::call('migrate', [
                '--path'  => "database/migrations/{$name}.php",
                '--force' => true,
            ]);
            $ran++;
            $output .= "  RAN     {$name}\n";
        } catch (\Throwable $e) {
            $reason = $skipReason($e);

            if ($reason === null) {
                $output .= "  FAILED  {$name}\n          " . $e->getMessage() . "\n";
                return response($output . "\nAborted — fix the migration above, then re-run this URL.\n")
                    ->header('Content-Type', 'text/plain');
            }

            // Its work is already done (or has been superseded) — record it as
            // applied so it is never retried, and carry on.
            \Illuminate\Support\Facades\DB::table('migrations')->insert([
                'migration' => $name,
                'batch'     => $batch,
            ]);
            $skipped[] = $name;
            $output .= "  SKIPPED {$name} ({$reason})\n";
        }
    }

    $output .= "\n{$ran} migration(s) run, " . count($skipped) . " skipped as already-done/superseded.\n\n";

    if ($isFreshDatabase) {
        \Illuminate\Support\Facades\Artisan::call('db:seed', ['--force' => true]);
        $output .= "db:seed --force (empty database detected)\n" . \Illuminate\Support\Facades\Artisan::output() . "\n";
    } else {
        $output .= "db:seed skipped (database already has data)\n\n";
    }
     }

    // Applies any permission newly added to RoleDefaultPermissions::MAP to
    // EXISTING users, not just newly-created ones — the thing that used to
    // need a hand-written backfill migration per key. Additive only, and the
    // permission_rollouts ledger makes it a no-op once a key has been rolled
    // out, so running it on every deploy is safe.
    //
    // ?sync_preview=1 reports what it WOULD do and writes nothing — worth
    // using on the first run here, since production's users differ from local.
    $syncPreview = request()->boolean('sync_preview');
    \Illuminate\Support\Facades\Artisan::call(
        'permissions:sync',
        $syncPreview ? ['--dry-run' => true] : []
    );
    $output .= 'permissions:sync' . ($syncPreview ? ' --dry-run (PREVIEW — nothing written)' : '') . "\n"
        . \Illuminate\Support\Facades\Artisan::output() . "\n";

    // Creates the public/storage -> storage/app/public symlink this host has
    // no SSH access to run `php artisan storage:link` for directly — without
    // it, an upload (profile avatar, chat attachment, etc.) saves fine to
    // storage/app/public/... but its public URL (/storage/...) 404s, since
    // nothing on disk actually serves that path. artisan's own Link facade
    // no-ops safely if the link already exists, so this is safe to re-run on
    // every deploy-helper hit.
    \Illuminate\Support\Facades\Artisan::call('config:cache');
    $output .= "config:cache\n" . \Illuminate\Support\Facades\Artisan::output() . "\n";

    \Illuminate\Support\Facades\Artisan::call('route:cache');
    $output .= "route:cache\n" . \Illuminate\Support\Facades\Artisan::output() . "\n";

    // Reset again so the freshly regenerated cache files are compiled fresh
    // on the very next request too, instead of opcache re-serving whatever
    // (possibly still stale) bytecode it holds for those same file paths.
    if (function_exists('opcache_reset')) {
        opcache_reset();
        $output .= "opcache_reset() (post-cache)\n";
    }

    return response('<pre>' . htmlspecialchars($output) . '</pre>');
});

// TEMPORARY — OpenRouter self-test. Answers one question in a browser:
// is the AI service catalog actually being generated by the gateway right now,
// or is the Client Portal quietly serving its static fallback? Delete this
// route once the integration is settled.
//
// Guarded by a random secret the same way the deploy helper above is: it
// reaches out to a paid API and reports account state, so it must not be
// triggerable by guessing the URL. It only ever READS — no cache is written,
// no config changed — and the API key is masked in the output.
Route::get('ai-selftest-3656cd44ef9001aa', function () {
    if (request('key') !== 'f397e63c6217b5805f7176b13b291591ce384771') {
        abort(403);
    }

    @set_time_limit(120);

    $http = fn () => \Illuminate\Support\Facades\Http::timeout(30)->acceptJson();
    $key      = (string) config('services.openrouter.key');
    $base     = rtrim((string) config('services.openrouter.base_url'), '/');
    $model    = (string) config('services.openrouter.model');
    $verdict  = null;
    $advice   = [];
    $out      = "OPENROUTER SELF-TEST — " . now()->toDateTimeString() . " (" . config('app.env') . ")\n"
              . str_repeat('=', 72) . "\n\n";

    // 1. Configuration — the key is only ever shown masked.
    $masked = $key === '' ? '(NOT SET)'
        : substr($key, 0, 8) . str_repeat('.', 6) . substr($key, -4) . '  [' . strlen($key) . ' chars]';
    $out .= "1. CONFIG\n"
          . "   base_url : {$base}\n"
          . "   model    : {$model}\n"
          . "   api key  : {$masked}\n\n";

    if ($key === '') {
        $out .= "VERDICT: NOT WORKING — OPENROUTER_API_KEY is not set in .env.\n"
              . "         Set it, then run `php artisan config:clear`.\n";

        return response('<pre>' . htmlspecialchars($out) . '</pre>');
    }

    // 2. Does the gateway accept this key at all? /key is the endpoint to ask
    //    with — /models is PUBLIC and answers 200 even for a bogus key, so it
    //    proves nothing about authentication.
    $keyInfo = $http()->withToken($key)->get("{$base}/key");
    $out .= "2. AUTH — GET /key\n"
          . "   HTTP {$keyInfo->status()}"
          . ($keyInfo->successful()
              ? "  OK — key accepted (label " . $keyInfo->json('data.label') . ", "
                . ($keyInfo->json('data.is_free_tier') ? 'free tier' : 'paid tier') . ")\n\n"
              : "  " . ($keyInfo->json('error.message') ?? $keyInfo->body()) . "\n\n");

    if (!$keyInfo->successful()) {
        $verdict = 'NOT WORKING — the gateway rejected the key.';
        $advice[] = 'Create a key at https://openrouter.ai/settings/keys, put it in .env as OPENROUTER_API_KEY, run `php artisan config:clear`.';
    }

    // Does the configured model id even exist? Wrong ids are the other common
    // cause of a working key that still cannot generate anything.
    $catalog = $http()->get("{$base}/models");
    if ($catalog->successful()) {
        $ids     = collect($catalog->json('data') ?? [])->pluck('id');
        $listed  = $ids->contains($model);
        $out .= "   model \"{$model}\" exists in the catalog: " . ($listed ? 'yes' : 'NO') . "\n"
              . "   (" . $ids->count() . " models offered, " . $ids->filter(fn ($id) => str_ends_with($id, ':free'))->count() . " of them :free)\n\n";
        if (!$listed) {
            $advice[] = "\"{$model}\" is not a valid model id — pick one from https://openrouter.ai/models and set OPENROUTER_MODEL.";
        }
    }

    // 3. Can the account actually spend? A valid key with no credits still
    //    cannot call a model, which is the difference this section exposes.
    if ($keyInfo->successful()) {
        $credits = $http()->withToken($key)->get("{$base}/credits");
        $out .= "3. BILLING — GET /credits\n"
              . "   HTTP {$credits->status()}"
              . ($credits->successful()
                  ? sprintf(
                      "  purchased: %.2f   used: %.2f   remaining: %.2f\n\n",
                      (float) $credits->json('data.total_credits'),
                      (float) $credits->json('data.total_usage'),
                      (float) $credits->json('data.total_credits') - (float) $credits->json('data.total_usage')
                  )
                  : "\n\n");
    }

    // 4. The real thing — the same request the Client Portal makes.
    $out .= "4. LIVE CALL — POST /v1/chat/completions\n";
    $started = microtime(true);
    $call = $http()->withToken($key)->post("{$base}/chat/completions", [
        'model' => $model,
        'max_tokens' => 20,
        'messages' => [['role' => 'user', 'content' => 'Reply with exactly: ok']],
    ]);
    $elapsed = microtime(true) - $started;
    $out .= sprintf("   HTTP %d  (%.1fs)\n", $call->status(), $elapsed);

    if ($call->successful()) {
        $out .= "   reply : " . trim((string) $call->json('choices.0.message.content')) . "\n"
              . "   cost  : " . ($call->json('usage.cost') ?? 'n/a') . "\n\n";
    } else {
        // OpenRouter reports its own code as a NUMBER inside the error object
        // (402, 429, …), separate from the HTTP status, and puts the useful
        // "what to do" text in metadata.remedy_hint.
        $code   = (string) ($call->json('error.code') ?? $call->status());
        $reason = (string) ($call->json('error.message') ?? $call->body());
        $out .= "   code  : {$code}\n"
              . "   error : {$reason}\n"
              . ($call->json('error.metadata.remedy_hint') ? "   hint  : " . $call->json('error.metadata.remedy_hint') . "\n" : '')
              . "\n";

        $verdict ??= "NOT WORKING — the key is accepted but the call was refused ({$code}).";

        if ($code === '402' || str_contains(strtolower($reason), 'credit')) {
            $advice[] = 'Buy credits at https://openrouter.ai/settings/credits';
            $advice[] = 'Or add your own vendor key (BYOK) at https://openrouter.ai/settings/integrations — that bills the vendor directly instead of OpenRouter credits.';
        }
        if ($code === '429' || $call->status() === 429) {
            $advice[] = 'Rate limited — a ":free" model shares a pool with everyone. Set a paid OPENROUTER_MODEL, or retry later.';
        }
        if ($code === '404' || str_contains(strtolower($reason), 'not a valid model')) {
            $advice[] = 'Model id is wrong. Pick one from https://openrouter.ai/models and set OPENROUTER_MODEL in .env.';
        }
    }

    // 5. End to end, through the app's own service — cache deliberately
    //    bypassed so this reports what is true NOW, not what was cached
    //    up to six hours ago.
    $out .= "5. CLIENT PORTAL CATALOG — AiServiceCatalogService (cache bypassed)\n";
    $generate = new ReflectionMethod(\App\Services\AiServiceCatalogService::class, 'generate');
    $generate->setAccessible(true);
    $services = $generate->invoke(null);

    if ($services) {
        $out .= "   " . count($services) . " service(s) generated by the model:\n";
        foreach ($services as $i => $s) {
            $out .= sprintf("     %d. %s  [%s] — %s\n", $i + 1, $s['name'], $s['category'], $s['timeline']);
        }
        $out .= "\n";
        $verdict = 'WORKING — OpenRouter generated the Client Portal catalog.';
        $advice  = ['Nothing to do. The portal will show this list (cached for 6 hours).'];
    } else {
        $out .= "   0 generated — the portal is serving its built-in static list.\n\n";
        $verdict ??= 'NOT WORKING — no catalog came back.';
    }

    $out .= str_repeat('=', 72) . "\n"
          . "VERDICT: {$verdict}\n";
    foreach ($advice as $line) {
        $out .= "         - {$line}\n";
    }

    return response('<pre>' . htmlspecialchars($out) . '</pre>');
});

/*
|--------------------------------------------------------------------------
| Public Routes  (no auth required)
|--------------------------------------------------------------------------
*/

// Uploaded files (avatars, company logos, support-ticket attachments) served
// straight from the `public` disk — see App\Support\PublicFile for why these
// no longer go through /storage. Deliberately NOT inside the prefix('public')
// group below: /api/files/... is the URL PublicFile builds, and it must not
// become /api/public/files/....
//
// The where() forbids a literal '..' in the path; PublicFileController
// re-checks after url-decoding and confirms the resolved path is inside the
// disk root, since this route is what stands between the internet and .env.
Route::get('files/{path}', [\App\Http\Controllers\Api\PublicFileController::class, 'show'])
    ->where('path', '^(?!.*\.\.).*$');

Route::prefix('public')->group(function () {
    Route::get('packages',          [PublicController::class, 'packages']);
    Route::get('modules',           [PublicController::class, 'modules']);
    Route::post('check-email',      [PublicController::class, 'checkEmail']);
    Route::post('check-company-name', [PublicController::class, 'checkCompanyName']);
    Route::post('register',         [PublicController::class, 'register']);
    Route::get('payment-gateways',  [PublicController::class, 'activeGateways']);

    // "Complete Payment" link on the admin login screen — for an account
    // stuck in pending_payment (blocked from AdminAuthController::login()),
    // this re-verifies credentials and issues a token that can only reach
    // the subscription-payment endpoints (see 'subscription.active' middleware).
    Route::post('resume-payment',   [PublicController::class, 'resumePayment']);

    // Public invoice payment (token-based, no auth)
    Route::get('invoices/{token}',      [PublicInvoiceController::class, 'show']);
    Route::post('invoices/{token}/pay', [PublicInvoiceController::class, 'pay']);

    // Guest-customer delivery download — the counterpart to Client\
    // ProjectController::downloadDelivery() for an invoice with no client
    // portal account at all (see PublicInvoiceController::downloadDelivery()).
    Route::get('invoices/{token}/delivery/download', [PublicInvoiceController::class, 'downloadDelivery']);

    // Same, but for any OLD/other project in the guest's Project History
    // (see PublicInvoiceController::downloadProjectDelivery()) — not just
    // the one this particular invoice is directly linked to.
    Route::get('invoices/{token}/projects/{project}/delivery/download', [PublicInvoiceController::class, 'downloadProjectDelivery']);

    // One specific past delivery (a project can have several files sent over
    // time — see PublicInvoiceController::downloadDeliverySubmission()),
    // not just a project's single latest file.
    Route::get('invoices/{token}/deliveries/{delivery}/download', [PublicInvoiceController::class, 'downloadDeliverySubmission']);

    // Real gateway checkout (Stripe/PayPal/Authorize.net hosted pages) —
    // finalization itself happens via the gateway's webhook, not here.
    // Superseded by the inline endpoints below for the public pay page's own
    // UI, but left registered/untouched for any other consumer.
    Route::post('invoices/{token}/gateways/{gateway}/initiate', [PublicInvoiceController::class, 'initiate']);
    Route::get('invoices/{token}/return/{gateway}',             [PublicInvoiceController::class, 'returnFromGateway']);

    // Inline gateway payment (Stripe Elements / PayPal Buttons / Authorize.net
    // Accept.js, all mounted directly on the pay page) — charged and finalized
    // synchronously within chargeGateway()'s own request, no webhook involved.
    Route::get('invoices/{token}/gateways/{gateway}/init',       [PublicInvoiceController::class, 'initGateway']);
    Route::post('invoices/{token}/gateways/paypal/create-order', [PublicInvoiceController::class, 'createPaypalOrder']);
    Route::post('invoices/{token}/gateways/{gateway}/charge',    [PublicInvoiceController::class, 'chargeGateway']);

    Route::get('invoices/{token}/chat',           [PublicInvoiceChatController::class, 'show']);
    Route::post('invoices/{token}/chat/messages', [PublicInvoiceChatController::class, 'store'])->middleware('throttle:20,1');
    Route::get('invoices/{token}/chat/messages/{messageId}/attachment', [PublicInvoiceChatController::class, 'downloadAttachment']);
    Route::delete('invoices/{token}/chat/messages/{messageId}', [PublicInvoiceChatController::class, 'deleteMessage'])->middleware('throttle:20,1');

    // Lead-facing Sales Chat — the page behind the "Start Conversation" button
    // in App\Mail\LeadSalesChatInviteMail. Authenticated purely by
    // leads.chat_token, which is the ONLY thing the URL carries: no lead id is
    // ever exposed, so there is nothing to enumerate. See
    // Api\PublicLeadChatController for the full scoping rules.
    //
    // show() is throttled too, unlike its invoice counterpart: the token is a
    // bearer credential and this is the endpoint an attacker would hammer to
    // guess one, so it gets a rate limit even though it only reads.
    Route::get('sales-chat/{token}',                                     [PublicLeadChatController::class, 'show'])->middleware('throttle:60,1');
    Route::post('sales-chat/{token}/messages',                           [PublicLeadChatController::class, 'store'])->middleware('throttle:20,1');
    Route::get('sales-chat/{token}/messages/{messageId}/attachment',     [PublicLeadChatController::class, 'downloadAttachment'])->middleware('throttle:60,1');
    Route::delete('sales-chat/{token}/messages/{messageId}',             [PublicLeadChatController::class, 'deleteMessage'])->middleware('throttle:20,1');
});

// Invite accept (no auth — accessed via token link)
Route::get('invite/{token}',  [InviteController::class, 'show']);
Route::post('invite/{token}', [InviteController::class, 'accept']);

// Payment gateway webhooks (Stripe/PayPal/Authorize.net call these directly,
// no auth — each request is verified by gateway-specific signature checking,
// see Api\Webhooks\PaymentWebhookController). {company_admin_id} identifies
// which tenant's credentials to verify against; each Company Admin registers
// their own webhook URL (with their own id) against their own gateway account.
// The optional {companyGatewayId} segment disambiguates when a tenant has
// more than one account of the same gateway type — omitted, it resolves to
// that type's default account (kept working for URLs registered before
// multi-account support existed).
Route::post('webhooks/{gateway}/{companyAdminId}', [\App\Http\Controllers\Api\Webhooks\PaymentWebhookController::class, 'handle']);
Route::post('webhooks/{gateway}/{companyAdminId}/{companyGatewayId}', [\App\Http\Controllers\Api\Webhooks\PaymentWebhookController::class, 'handle']);

// Unified login — the /login frontend page's single form for both Company
// Admin and staff/sub-users. Tries CompanyAdmin credentials, then User
// credentials (see UnifiedLoginController); never touches Super Admin.
Route::post('login', [\App\Http\Controllers\Auth\UnifiedLoginController::class, 'login']);

// Unified "send me a reset link" — same admin-first precedence as the login
// route above. The actual reset step stays on the tier-specific
// /admin/reset-password or /reset-password page, since the emailed link
// itself already encodes which one to use.
Route::post('forgot-password', [\App\Http\Controllers\Auth\UnifiedForgotPasswordController::class, 'sendResetLink']);

/*
|--------------------------------------------------------------------------
| Finance Area  (shared route list, registered under BOTH guards)
|--------------------------------------------------------------------------
|
| Defined once as a closure and invoked inside the admin group and the user
| group below, so /admin/finance/* and /user/finance/* can never drift apart
| — a route added for one is a route added for both, by construction. The
| controllers behind them are guard-agnostic (see the import block at the top
| of this file).
|
| THE WHOLE AREA rides on `finance_dashboard`, applied as an outer gate where
| this closure is invoked (see both call sites below). That one key is what
| makes the Finance module a single switch:
|
|   • Super Admin deactivates the Finance module → CheckCompanyModule's
|     `$disabledGlobally` check matches finance_dashboard (it is one of the
|     `finance` row's sub_modules, see database/seeders/ModuleSeeder.php) and
|     every Finance endpoint 403s — including the invoice/payment sections,
|     which would otherwise stay open because they are gated on the INVOICE
|     module's own keys.
|   • A company that never bought Finance has no finance_dashboard row, so the
|     area is closed to it for the same reason.
|
| Neither is true of the Invoice module's own screens (/invoices, /payments):
| those belong to Invoice and are unaffected by Finance being switched off,
| which is the intended split.
|
| Each section then ALSO carries the granular company_modules key it belongs
| to, so a company whose module rows have been edited by hand still gets the
| right subset:
|
|   finance_reports     Reports > Finance    (Finance module purchase)
|   revenue_reports     Reports > Revenue    (Finance module purchase)
|   payments_report     Reports > Payments   (Finance module purchase)
|   invoices            Finance > Invoices   (Invoice module purchase)
|   payments            Finance > Payments   (Invoice module purchase)
|   payment_details     Finance > Payment Details
|   invoice_reminders   Finance > Invoice Reminders
|
| Those are the existing keys (see ModuleCatalog::dbKeyToCatalog()); none were
| invented for this. The Finance catalog module already `requires` Invoice, so
| a company holding Finance normally holds all eight.
|
| Static segments are declared before their {id} siblings throughout, so
| /finance/invoices/export is not read as an invoice with the id "export" —
| the same ordering rule the rest of this file follows.
*/
$financeRoutes = function () {
    // No permission gate — it answers "what may I do in Finance?", and the
    // honest answer for someone holding nothing is a map of false, so the UI
    // can tell "no access" from "server error". It still sits behind the
    // outer module gate: when the area itself is switched off there is
    // nothing to answer.
    Route::get('finance/capabilities', [FinanceDashboardController::class, 'capabilitiesIndex']);

    // No module middleware of its own — the outer finance_dashboard gate IS
    // this section's gate.
    Route::get('finance/dashboard', [FinanceDashboardController::class, 'index']);

    Route::middleware('module:invoices')->group(function () {
        Route::get('finance/invoices/export', [FinanceInvoiceController::class, 'export']);
        Route::get('finance/invoices',        [FinanceInvoiceController::class, 'index']);
        Route::get('finance/invoices/{id}',   [FinanceInvoiceController::class, 'show'])->whereNumber('id');
    });

    Route::middleware('module:payments')->group(function () {
        Route::get('finance/payments/export',            [FinancePaymentController::class, 'export']);
        Route::get('finance/payments',                   [FinancePaymentController::class, 'index']);
        Route::get('finance/payments/{id}',              [FinancePaymentController::class, 'show'])->whereNumber('id');
        Route::patch('finance/payments/{id}/reconcile',  [FinancePaymentController::class, 'reconcile'])->whereNumber('id');
        Route::delete('finance/payments/{id}/reconcile', [FinancePaymentController::class, 'unreconcile'])->whereNumber('id');
        // Record a payment received off-platform. Goes through
        // InvoicePaymentService::record(), the same method
        // Api\Admin\PaymentController::store() calls — one implementation.
        Route::post('finance/invoices/{invoice}/payments', [FinancePaymentController::class, 'record'])->whereNumber('invoice');
    });

    Route::middleware('module:payment_details')->group(function () {
        Route::get('finance/payment-details/export',           [FinancePaymentDetailController::class, 'export']);
        Route::get('finance/payment-details',                  [FinancePaymentDetailController::class, 'index']);
        Route::get('finance/payment-details/{id}',             [FinancePaymentDetailController::class, 'show'])->whereNumber('id');
        Route::patch('finance/payment-details/{id}/reconcile', [FinancePaymentDetailController::class, 'reconcile'])->whereNumber('id');
    });

    Route::middleware('module:invoice_reminders')->group(function () {
        Route::get('finance/invoice-reminders/verify',      [FinanceReminderController::class, 'verify']);
        Route::get('finance/invoice-reminders',             [FinanceReminderController::class, 'index']);
        Route::post('finance/invoice-reminders',            [FinanceReminderController::class, 'store']);
        Route::post('finance/invoice-reminders/{id}/send',  [FinanceReminderController::class, 'send'])->whereNumber('id');
        Route::patch('finance/invoice-reminders/{id}/cancel', [FinanceReminderController::class, 'cancel'])->whereNumber('id');
    });

    Route::middleware('module:finance_reports')->group(function () {
        Route::get('finance/reports/finance',        [FinanceReportController::class, 'finance']);
        Route::get('finance/reports/finance/export', [FinanceReportController::class, 'export'])->defaults('report', 'finance');
    });

    Route::middleware('module:revenue_reports')->group(function () {
        Route::get('finance/reports/revenue',        [FinanceReportController::class, 'revenue']);
        Route::get('finance/reports/revenue/export', [FinanceReportController::class, 'export'])->defaults('report', 'revenue');
    });

    Route::middleware('module:payments_report')->group(function () {
        Route::get('finance/reports/payments',        [FinanceReportController::class, 'payments']);
        Route::get('finance/reports/payments/export', [FinanceReportController::class, 'export'])->defaults('report', 'payments');
    });
};

/*
|--------------------------------------------------------------------------
| Admin Auth Routes  (guard: admin → CompanyAdmin model)
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->group(function () use ($financeRoutes) {
    Route::post('login', [AdminAuthController::class, 'login']);
    Route::post('forgot-password', [AdminForgotPasswordController::class, 'sendResetLink']);
    Route::post('reset-password',  [AdminForgotPasswordController::class, 'reset']);

    // Google OAuth (Company Admin login only) — unauthenticated by design.
    Route::get('auth/google/redirect',  [AdminGoogleAuthController::class, 'redirect']);
    Route::get('auth/google/callback',  [AdminGoogleAuthController::class, 'callback']);
    Route::post('auth/google/exchange', [AdminGoogleAuthController::class, 'exchange']);

    // Google Drive company-storage callback — a SEPARATE OAuth flow/client
    // from the login one immediately above (see config('services.
    // google_drive') and Auth\GoogleDriveConnectionController). Also
    // necessarily unauthenticated: Google redirects the bare browser here,
    // and the initiating company is recovered from the one-time `state`
    // token Api\Admin\StorageController::connectUrl() stashed, not from any
    // session on this request.
    Route::get('google-drive/callback', [\App\Http\Controllers\Auth\GoogleDriveConnectionController::class, 'callback']);

    // 'subscription.active' blocks every route below for a still-pending_payment
    // admin, EXCEPT the ones explicitly marked ->withoutMiddleware(...) further
    // down (logout, me, and the subscription-payment endpoints themselves) —
    // that's the only way such an admin can ever resolve out of this state.
    // 'tenant.active' sits FIRST, ahead of both gates below: a tenant the
    // Super Admin has deactivated is out regardless of what its subscription
    // or any individual company is doing, and unlike those two it has no
    // in-app way back — see Http\Middleware\EnsureTenantActive. `me` is NOT
    // exempted from it (a deactivated admin has no session to report on);
    // only `logout` is, so a client still holding a token can hand it back.
    Route::middleware(['auth:admin', 'tenant.active', 'subscription.active', 'company.not_suspended'])->group(function () use ($financeRoutes) {
        Route::post('logout', [AdminAuthController::class, 'logout'])
            ->withoutMiddleware([\App\Http\Middleware\EnsureTenantActive::class, \App\Http\Middleware\EnsureSubscriptionActive::class, \App\Http\Middleware\EnsureCompanyNotSuspended::class]);
        Route::get('me',     [AdminAuthController::class, 'me'])
            ->withoutMiddleware([\App\Http\Middleware\EnsureSubscriptionActive::class, \App\Http\Middleware\EnsureCompanyNotSuspended::class, \App\Http\Middleware\EnsureCompanyAdminActive::class]);

        // Finance Area — Company Admin side. Same list the staff group
        // registers; see the $financeRoutes definition above. The
        // `module:finance_dashboard` wrapper is the single switch that closes
        // the whole area when the Super Admin deactivates Finance, or when
        // this company never bought it.
        Route::middleware('module:finance_dashboard')->group($financeRoutes);

        // Mail/link/queue diagnostics. These mirror the mail:doctor and
        // frontend:url console commands, which are unreachable on a host with
        // no shell — see Api\Admin\DiagnosticsController. withoutMiddleware
        // on the subscription gate is deliberate: an admin whose subscription
        // lapsed still needs to be able to see WHY their mail stopped.
        Route::get('diagnostics/mail',           [AdminDiagnosticsController::class, 'mail'])->withoutMiddleware(\App\Http\Middleware\EnsureSubscriptionActive::class);
        Route::post('diagnostics/mail/test',     [AdminDiagnosticsController::class, 'sendTestMail'])->withoutMiddleware(\App\Http\Middleware\EnsureSubscriptionActive::class);
        Route::post('diagnostics/run-scheduled', [AdminDiagnosticsController::class, 'runScheduled']);

        // My Profile — this admin's own name/phone/avatar/password, distinct
        // from the tenant-wide business Settings (Api\Admin\SettingsController).
        Route::get('profile',            [AdminProfileController::class, 'show']);
        Route::put('profile',            [AdminProfileController::class, 'update']);
        Route::post('profile/avatar',    [AdminProfileController::class, 'uploadAvatar']);
        Route::put('profile/password',   [AdminProfileController::class, 'changePassword']);

        // Notifications — merges the company-wide SystemAuditLog-based
        // activity feed with real `notifications` rows (recipient_admin_id)
        // written via NotificationService for specific events (task blocked,
        // deliverable submitted, invoice paid, etc.) — see NotificationController.
        Route::get('notifications',                    [AdminNotificationController::class, 'index']);
        Route::get('notifications/unread-counts',      [AdminNotificationController::class, 'unreadCounts']);
        Route::patch('notifications/mark-category-read', [AdminNotificationController::class, 'markCategoryRead']);
        Route::patch('notifications/read-all',         [AdminNotificationController::class, 'markAllRead']);
        Route::patch('notifications/{id}/read',        [AdminNotificationController::class, 'markRead']);
        Route::delete('notifications/{id}',            [AdminNotificationController::class, 'clear']);
        Route::delete('notifications',                 [AdminNotificationController::class, 'clearAll']);

        // General Chat — company-wide oversight of every direct/group thread
        // (not module-gated, same reasoning as the User side).
        Route::get('chat',                                [AdminGeneralChatController::class, 'index']);
        Route::get('chat/eligible-users',                 [AdminGeneralChatController::class, 'eligibleUsers']);
        Route::post('chat/direct',                        [AdminGeneralChatController::class, 'createDirect']);
        Route::post('chat/group',                         [AdminGeneralChatController::class, 'createGroup']);
        Route::delete('chat/{threadId}/participants/{userId}', [AdminGeneralChatController::class, 'removeParticipant']);
        Route::get('chat/{threadId}/messages',            [AdminGeneralChatController::class, 'messages']);
        Route::post('chat/{threadId}/messages',           [AdminGeneralChatController::class, 'send']);
        Route::patch('chat/{threadId}/messages/{messageId}', [AdminGeneralChatController::class, 'updateMessage']);
        Route::delete('chat/{threadId}/messages/{messageId}', [AdminGeneralChatController::class, 'deleteMessage']);
        Route::get('chat/{threadId}/messages/{messageId}/attachment', [AdminGeneralChatController::class, 'downloadAttachment']);

        // AI Assistance (Company Admin) — Client Messages. One read-only
        // endpoint: which clients have started a conversation in a company
        // THIS Admin owns. `visible:false` means the widget does not render at
        // all, which is how it stays off every screen until a client actually
        // reaches out. Reading and replying go through the direct-chat routes
        // below, so there is no second inbox with its own rules.
        Route::get('assistant/conversations', [\App\Http\Controllers\Api\Admin\AssistantController::class, 'conversations'])
            ->middleware('throttle:60,1');

        Route::get('dashboard',                          [AdminDashboardController::class, 'index']);

        // Admin's own companies list + create + edit. Every one of these
        // (including manage/suspend/reactivate) is deliberately exempt from
        // 'company.not_suspended' — this IS the screen a Company Admin must
        // still be able to reach to see WHY a company is suspended and
        // reactivate it (see EnsureCompanyNotSuspended's docblock).
        Route::get('companies',                          [AdminClientController::class, 'companies'])
            ->withoutMiddleware(\App\Http\Middleware\EnsureCompanyNotSuspended::class);
        Route::get('companies/manage',                   [AdminClientController::class, 'manageCompanies'])
            ->withoutMiddleware(\App\Http\Middleware\EnsureCompanyNotSuspended::class);
        Route::post('companies',                         [AdminClientController::class, 'storeCompany'])
            ->withoutMiddleware(\App\Http\Middleware\EnsureCompanyNotSuspended::class);
        Route::patch('companies/{id}/suspend',           [AdminClientController::class, 'suspendCompany'])
            ->withoutMiddleware(\App\Http\Middleware\EnsureCompanyNotSuspended::class);
        Route::patch('companies/{id}/reactivate',        [AdminClientController::class, 'reactivateCompany'])
            ->withoutMiddleware(\App\Http\Middleware\EnsureCompanyNotSuspended::class);
        Route::get('companies/{id}/history',             [AdminClientController::class, 'companyHistory'])
            ->withoutMiddleware(\App\Http\Middleware\EnsureCompanyNotSuspended::class);
        Route::get('companies/{id}',                     [AdminClientController::class, 'showCompany'])
            ->withoutMiddleware(\App\Http\Middleware\EnsureCompanyNotSuspended::class);
        Route::put('companies/{id}',                     [AdminClientController::class, 'updateCompany'])
            ->withoutMiddleware(\App\Http\Middleware\EnsureCompanyNotSuspended::class);
        // Deliberately reachable while blocked (trial_expired/suspended/etc.)
        // — this is the endpoint /admin/plan reads to SHOW the admin why
        // they're blocked and what to do about it.
        Route::get('usage',                              [AdminClientController::class, 'usage'])->withoutMiddleware(\App\Http\Middleware\EnsureSubscriptionActive::class);

        // User (staff) management
        Route::prefix('users')->group(function () {
            Route::get('/',                                                   [AdminUserController::class, 'index']);
            Route::post('/',                                                  [AdminUserController::class, 'store']);
            Route::post('/invite',                                            [AdminUserController::class, 'invite']);
            Route::get('/company-options',                                    [AdminUserController::class, 'companyOptions']);
            Route::get('/check-email',                                        [AdminUserController::class, 'checkEmail']);
            Route::get('/roles',                                              [AdminUserController::class, 'roleOptions']);
            Route::get('/role-defaults',                                      [AdminUserController::class, 'roleDefaults']);
            Route::get('/{user}',                                             [AdminUserController::class, 'show']);
            Route::put('/{user}',                                             [AdminUserController::class, 'update']);
            Route::delete('/{user}',                                          [AdminUserController::class, 'destroy']);
            // Impact Summary modal: what this person holds, handing it over,
            // and the permanent account delete that destroy() above is not.
            Route::get('/{user}/company-workload',                            [AdminUserController::class, 'companyWorkload']);
            Route::get('/{user}/delete-impact',                               [AdminUserController::class, 'deleteImpact']);
            Route::post('/{user}/reassign',                                   [AdminUserController::class, 'reassignWorkload']);
            Route::delete('/{user}/permanent',                                [AdminUserController::class, 'destroyPermanently']);
            Route::put('/{user}/permissions',                                 [AdminUserController::class, 'syncPermissions']);
            Route::post('/{user}/resend-invite',                              [AdminUserController::class, 'resendInvite']);
            Route::post('/{user}/reset-password',                             [AdminUserController::class, 'resetPassword']);
            Route::patch('/{user}/toggle-status',                             [AdminUserController::class, 'toggleStatus']);
            Route::get('/{user}/activity',                                    [AdminUserController::class, 'activity']);
            Route::get('/{user}/company-permissions/{companyId}',             [AdminUserController::class, 'getCompanyPermissions']);
            Route::put('/{user}/company-permissions/{companyId}',             [AdminUserController::class, 'updateCompanyPermissions']);
        });

        // Sales — requires leads module
        Route::middleware('module:leads')->group(function () {
            // Sales dashboard
            Route::get('sales/dashboard',                    [AdminSalesDashboardController::class, 'index']);
            // Sales reports — company-wide, not scoped to "assigned to me"
            // (see Api\Admin\SalesReportController for why this couldn't
            // just reuse the User-guard /user/sales/reports/* endpoints).
            Route::get('sales/reports/leads',                [AdminSalesReportController::class, 'leadReport']);
            Route::get('sales/reports/conversion',           [AdminSalesReportController::class, 'conversionReport']);
            Route::get('sales/reports/performance',          [AdminSalesReportController::class, 'performanceReport']);
            Route::get('sales/reports/leads/export',         [AdminSalesReportController::class, 'exportLeadReport']);
            // Pipeline
            Route::get('leads/pipeline',                     [AdminLeadController::class, 'pipeline']);
            Route::get('leads/company-users',                [AdminLeadController::class, 'companyUsers']);
            // Lead CRUD
            Route::get('leads',                              [AdminLeadController::class, 'index']);
            Route::post('leads',                             [AdminLeadController::class, 'store']);
            Route::get('leads/{id}',                         [AdminLeadController::class, 'show']);
            Route::put('leads/{id}',                         [AdminLeadController::class, 'update']);
            Route::delete('leads/{id}',                      [AdminLeadController::class, 'destroy']);
            Route::patch('leads/{id}/status',                [AdminLeadController::class, 'updateStatus']);
            Route::patch('leads/{id}/company',               [AdminLeadController::class, 'updateCompany']);
            Route::post('leads/{id}/transfer',               [AdminLeadController::class, 'transfer']);
            Route::post('leads/{id}/convert',                [AdminLeadController::class, 'convert']);
            Route::get('leads/{id}/project-eligibility',     [AdminLeadController::class, 'projectEligibility']);
            // Sales Chat — Seller<->Lead conversation, separate from Project Chat
            Route::get('leads/{id}/chat',                    [AdminSalesChatController::class, 'leadMessages']);
            Route::post('leads/{id}/chat',                   [AdminSalesChatController::class, 'sendLeadMessage']);
            Route::get('leads/{id}/chat/{messageId}/attachment', [AdminSalesChatController::class, 'downloadLeadAttachment']);
            // Generic by message id — covers Lead/Client/Invoice-anchored
            // Sales Chat threads alike, since Company Admin is unrestricted
            // across all three.
            Route::delete('sales-chat/messages/{messageId}', [AdminSalesChatController::class, 'deleteMessage']);

            // The Lead's own no-login chat link: resend the invite email, or
            // kill the link that is already out there. See
            // App\Services\LeadChatInviteService.
            // AI-suggested Project Title, read strictly out of this lead's
            // Sales Chat (App\Services\LeadProjectTitleService). Consumed by
            // the Project Title field on Create Invoice as a SUGGESTION — the
            // user accepts, edits or ignores it. Returns title:null when the
            // conversation does not say what is being built.
            Route::get('leads/{id}/ai-project-title',        [AdminLeadController::class, 'aiProjectTitle']);
            Route::get('leads/{id}/chat-invite',             [AdminLeadController::class, 'chatInvite']);
            Route::post('leads/{id}/chat-invite',            [AdminLeadController::class, 'sendChatInvite']);
            Route::delete('leads/{id}/chat-invite',          [AdminLeadController::class, 'revokeChatInvite']);
            // Follow-ups per lead
            Route::get('leads/{leadId}/follow-ups',          [AdminFollowUpController::class, 'index']);
            Route::post('leads/{leadId}/follow-ups',         [AdminFollowUpController::class, 'store']);
            // Follow-up queue + actions
            Route::get('follow-ups',                         [AdminFollowUpController::class, 'queue']);
            Route::put('follow-ups/{id}',                    [AdminFollowUpController::class, 'update']);
            Route::patch('follow-ups/{id}/complete',         [AdminFollowUpController::class, 'complete']);
            Route::patch('follow-ups/{id}/miss',             [AdminFollowUpController::class, 'miss']);
            Route::patch('follow-ups/{id}/cancel',           [AdminFollowUpController::class, 'cancel']);
            Route::delete('follow-ups/{id}',                 [AdminFollowUpController::class, 'destroy']);
            // Sales Targets — every Seller/Lead Manager's current-month target
            // + progress, and the ability to set/adjust any of them (see
            // Api\Admin\SalesTargetController). Gated behind the Sales module
            // like every other route in this group — an admin who only
            // purchased e.g. Invoice must not see or reach Sales Targets.
            Route::get('sales/targets',            [AdminSalesTargetController::class, 'index']);
            Route::put('sales/targets/{user}',     [AdminSalesTargetController::class, 'update']);
        });

        // Sales Chat for clients — NOT wrapped in `module:clients` (same
        // rationale as the sub-user side's ungated `clients` routes below):
        // "own clients" reached via Sales should chat even on a Sales-only
        // company with no Client module purchased.
        Route::get('clients/{id}/chat',                      [AdminSalesChatController::class, 'clientMessages']);
        Route::post('clients/{id}/chat',                     [AdminSalesChatController::class, 'sendClientMessage']);
        Route::get('clients/{id}/chat/{messageId}/attachment', [AdminSalesChatController::class, 'downloadClientAttachment']);

        // "Client Messages" — the client's own restricted Direct Chat (see
        // Api\Client\ChatController), distinct from Sales Chat above (which
        // has no actual client party). Client Messages tab on the Client
        // detail page.
        Route::get('clients/{id}/direct-chat',                     [\App\Http\Controllers\Api\Admin\ClientChatController::class, 'index']);
        Route::post('clients/{id}/direct-chat/start',              [\App\Http\Controllers\Api\Admin\ClientChatController::class, 'startChat']);
        Route::get('clients/{id}/direct-chat/{threadId}/messages', [\App\Http\Controllers\Api\Admin\ClientChatController::class, 'messages']);
        Route::post('clients/{id}/direct-chat/{threadId}/reply',   [\App\Http\Controllers\Api\Admin\ClientChatController::class, 'reply']);
        Route::post('clients/{id}/direct-chat/{threadId}/participants',            [\App\Http\Controllers\Api\Admin\ClientChatController::class, 'addParticipant']);
        Route::delete('clients/{id}/direct-chat/{threadId}/participants/{userId}', [\App\Http\Controllers\Api\Admin\ClientChatController::class, 'removeParticipant']);

        // Basic client record management — NOT wrapped in module:client_portal,
        // matching the User-guard's equivalent routes below: Company Admin is
        // always privileged everywhere else in this app, and Convert-to-Client
        // (Api\Admin\LeadController::convert()) already creates a basic Client
        // row unconditionally, with no module check — Admin must also be able
        // to view/manage that same record afterward regardless of whether the
        // real Client Portal module is purchased. Portal login/permissions and
        // Support Tickets below ARE genuinely portal-specific features, so
        // those stay gated.
        Route::get('clients',                            [AdminClientController::class, 'index']);
        Route::post('clients',                           [AdminClientController::class, 'store']);
        // Picker list for the Transfer Client modal — must be registered
        // before clients/{id} below, same ordering reason as leads/company-users.
        Route::get('clients/company-users',              [AdminClientController::class, 'companyUsers']);
        Route::get('clients/{id}',                       [AdminClientController::class, 'show']);
        Route::put('clients/{id}',                       [AdminClientController::class, 'update']);
        Route::post('clients/{id}/transfer',             [AdminClientController::class, 'transfer']);
        // "Link to AI-Suggested Service" dropdown on Create Invoice — see
        // App\Services\ClientAiServiceBatchService.
        Route::get('clients/{id}/ai-service-batch-items', [AdminClientController::class, 'aiServiceBatchItems']);
        // Blast radius of a delete — the confirmation modal reads this first.
        Route::get('clients/{id}/delete-summary',        [AdminClientController::class, 'deleteSummary']);
        Route::delete('clients/{id}',                    [AdminClientController::class, 'destroy']);
        Route::get('clients/{client}/invoices',          [AdminInvoiceController::class, 'forClient']);

        // Invoice Receipts tab — the receipts generated for this client's
        // confirmed payments. Read-only: receipts are produced by the payment
        // pipeline (InvoicePaymentService::applyToInvoice → PaymentReceiptService),
        // never here. Both routes resolve the client through the companies this
        // admin owns and then pin the query to that client's own company_id, so
        // the receipt id in the URL can never reach another client's or another
        // company's receipt. Sits alongside clients/{client}/invoices above
        // rather than behind the client_portal module gate: a receipt documents
        // a payment, which exists whether or not the portal was bought.
        Route::get('clients/{id}/invoice-receipts',                  [\App\Http\Controllers\Api\Admin\ClientInvoiceReceiptController::class, 'index']);
        Route::get('clients/{id}/invoice-receipts/{receiptId}/image', [\App\Http\Controllers\Api\Admin\ClientInvoiceReceiptController::class, 'image']);

        // Portal login/permissions + Support Tickets — requires the real
        // Client Portal module. The real, purchasable module_key is
        // 'client_portal' (see ModuleSeeder.php's deliberate "clients is NOT
        // a purchasable module" comment) — 'clients' was never a real
        // CompanyModule row for any company, so this gate 403'd
        // unconditionally regardless of purchase until fixed.
        Route::middleware('module:client_portal')->group(function () {
            Route::put('clients/{id}/permissions',           [AdminClientController::class, 'updatePermissions']);
            Route::post('clients/{id}/enable-portal',        [AdminClientController::class, 'enablePortal']);
            Route::post('clients/{id}/disable-portal',       [AdminClientController::class, 'disablePortal']);

            Route::get('support',                            [AdminSupportController::class, 'index']);
            Route::get('support/{id}',                       [AdminSupportController::class, 'show']);
            Route::get('support/{id}/attachment',            [AdminSupportController::class, 'downloadAttachment']);
            Route::get('support/{id}/replies/{replyId}/attachment', [AdminSupportController::class, 'downloadReplyAttachment']);
            Route::post('support/{id}/reply',                [AdminSupportController::class, 'reply']);
            Route::patch('support/{id}/assign',              [AdminSupportController::class, 'assign']);
            Route::patch('support/{id}/status',              [AdminSupportController::class, 'updateStatus']);
        });

        // Invoice management — requires invoices module
        Route::middleware('module:invoices')->group(function () {
            Route::get('invoices',                           [AdminInvoiceController::class, 'index']);
            Route::post('invoices',                          [AdminInvoiceController::class, 'store']);
            // Brand Invoice dropdown — declared before /{invoice} so 'brands'
            // isn't swallowed by the model binding.
            Route::get('invoices/brands',                    [AdminInvoiceController::class, 'brands']);
            // How much of a Lead's budget its invoices have already used, plus
            // the tenant's Payment Policy — read by the invoice form so it can
            // warn before saving. Declared with the other static paths, ahead
            // of /{invoice}.
            Route::get('invoices/lead-budget',               [AdminInvoiceController::class, 'leadBudget']);
            Route::get('invoices/{invoice}',                 [AdminInvoiceController::class, 'show']);
            Route::put('invoices/{invoice}',                 [AdminInvoiceController::class, 'update']);
            Route::delete('invoices/{invoice}',              [AdminInvoiceController::class, 'destroy']);
            Route::patch('invoices/{invoice}/send',          [AdminInvoiceController::class, 'send']);
            Route::patch('invoices/{invoice}/cancel',        [AdminInvoiceController::class, 'cancel']);
            Route::post('invoices/{invoice}/send-email',     [AdminInvoiceController::class, 'sendEmail']);
            Route::post('invoices/{invoice}/generate-link',  [AdminInvoiceController::class, 'generateLink']);
            Route::delete('invoices/{invoice}/generate-link',[AdminInvoiceController::class, 'revokeLink']);

            // Sales Chat for a fully guest invoice (no Lead/Client) — see
            // Api\Admin\SalesChatController::invoiceMessages().
            Route::get('invoices/{id}/chat',                      [AdminSalesChatController::class, 'invoiceMessages']);
            Route::post('invoices/{id}/chat',                     [AdminSalesChatController::class, 'sendInvoiceMessage']);
            Route::get('invoices/{id}/chat/{messageId}/attachment', [AdminSalesChatController::class, 'downloadInvoiceAttachment']);

            // Brands — trading names this company bills under. Company Admin
            // needs no permission of their own (the admin guard is the gate);
            // the staff equivalent below is gated per key. Static segments
            // first so /company-options isn't swallowed by /{id}.
            // Services the company offers its portal clients. Company Admin
            // only — what a company sells is the owner's call, so unlike
            // brands there is no staff counterpart. Static paths first so
            // 'reorder' isn't read as an id.
            Route::get('services',                     [\App\Http\Controllers\Api\Admin\CompanyServiceController::class, 'index']);
            Route::post('services',                    [\App\Http\Controllers\Api\Admin\CompanyServiceController::class, 'store']);
            Route::put('services/reorder',             [\App\Http\Controllers\Api\Admin\CompanyServiceController::class, 'reorder']);
            // POST, not PUT: the icon is multipart and PHP does not parse
            // multipart bodies on PUT (same as brands).
            Route::post('services/{id}',               [\App\Http\Controllers\Api\Admin\CompanyServiceController::class, 'update']);
            Route::patch('services/{id}/toggle-status',[\App\Http\Controllers\Api\Admin\CompanyServiceController::class, 'toggleStatus']);
            Route::delete('services/{id}',             [\App\Http\Controllers\Api\Admin\CompanyServiceController::class, 'destroy']);
            // What clients have asked for from the portal.
            Route::get('service-requests',             [\App\Http\Controllers\Api\Admin\CompanyServiceController::class, 'requests']);
            Route::patch('service-requests/{id}',      [\App\Http\Controllers\Api\Admin\CompanyServiceController::class, 'updateRequest']);

            Route::get('brands',                       [AdminBrandController::class, 'index']);
            Route::post('brands',                      [AdminBrandController::class, 'store']);
            Route::get('brands/company-options',       [AdminBrandController::class, 'companyOptions']);
            Route::get('brands/assignable-users',      [AdminBrandController::class, 'assignableUsers']);
            Route::patch('brands/{id}/assign',         [AdminBrandController::class, 'assign']);
            // Moving a brand between the admin's own companies — Company
            // Admin only, so there is no staff counterpart below.
            Route::patch('brands/{id}/transfer',       [AdminBrandController::class, 'transfer']);
            Route::get('brands/{id}',                  [AdminBrandController::class, 'show']);
            // POST rather than PUT for update: the logo arrives as multipart
            // form-data (with _method=PUT), which PHP does not parse on PUT.
            Route::post('brands/{id}',                 [AdminBrandController::class, 'update']);
            Route::patch('brands/{id}/toggle-status',  [AdminBrandController::class, 'toggleStatus']);
            Route::delete('brands/{id}',               [AdminBrandController::class, 'destroy']);
        });

        // Payment management — requires payments module
        Route::middleware('module:payments')->group(function () {
            Route::get('payments',                           [AdminPaymentController::class, 'index']);
            Route::post('invoices/{invoice}/payments',       [AdminPaymentController::class, 'store']);
            Route::patch('payments/{payment}/confirm',       [AdminPaymentController::class, 'confirm']);
            Route::patch('payments/{payment}/reject',        [AdminPaymentController::class, 'reject']);
            Route::delete('payments/{payment}',              [AdminPaymentController::class, 'destroy']);
        });

        // Project Management — requires projects module
        Route::middleware('module:projects')->group(function () {
            Route::get('companies/{companyId}/project-users',      [AdminProjectController::class, 'projectUsers']);
            Route::get('projects/dashboard',                        [AdminProjectController::class, 'dashboard']);
            // Reports registered before the {id} wildcard so "reports" isn't swallowed as a project id.
            Route::get('projects/reports/status',                   [AdminProjectReportController::class, 'statusReport']);
            Route::get('projects/reports/task-status',              [AdminProjectReportController::class, 'taskStatusReport']);
            Route::get('projects/reports/workload',                 [AdminProjectReportController::class, 'workloadReport']);
            Route::get('projects/reports/timesheets',               [AdminProjectReportController::class, 'timesheetReport']);
            Route::get('projects/reports/overdue',                  [AdminProjectReportController::class, 'overdueReport']);
            Route::get('projects/reports/completed',                [AdminProjectReportController::class, 'completedProjectsReport']);
            Route::get('projects',                                  [AdminProjectController::class, 'index']);
            Route::post('projects',                                 [AdminProjectController::class, 'store']);
            Route::get('projects/{id}',                             [AdminProjectController::class, 'show']);
            Route::put('projects/{id}',                             [AdminProjectController::class, 'update']);
            Route::patch('projects/{id}/seller',                    [AdminProjectController::class, 'assignSeller']);
            Route::delete('projects/{id}',                          [AdminProjectController::class, 'destroy']);
            Route::put('projects/{id}/team',                        [AdminProjectController::class, 'assignTeam']);
            Route::patch('projects/{id}/team/{memberId}',           [AdminProjectController::class, 'updateMemberRole']);
            Route::delete('projects/{id}/team/{memberId}',          [AdminProjectController::class, 'removeTeamMember']);
            Route::get('projects/{id}/activity',                    [AdminProjectController::class, 'activity']);
            Route::get('projects/{id}/completion-status',           [AdminProjectController::class, 'completionStatus']);
            Route::post('projects/{id}/activate',                   [AdminProjectController::class, 'activate']);
            Route::post('projects/{id}/complete',                   [AdminProjectController::class, 'complete']);
            Route::post('projects/{id}/approve-completion',         [AdminProjectController::class, 'approveCompletion']);
            Route::get('projects/{id}/delivery/download',           [AdminProjectController::class, 'downloadDelivery']);
            Route::post('projects/{id}/approve-delivery',           [AdminProjectController::class, 'approveDelivery']);
            Route::post('projects/{id}/deliver-to-client',          [AdminProjectController::class, 'deliverToClient']);
            Route::post('projects/{id}/upload-and-deliver',         [AdminProjectController::class, 'uploadAndDeliver']);
            Route::get('projects/{id}/deliveries',                  [AdminProjectController::class, 'deliveryHistory']);
            Route::get('projects/{id}/deliveries/{deliveryId}/download', [AdminProjectController::class, 'downloadDeliverySubmission']);
            Route::post('projects/{id}/close',                      [AdminProjectController::class, 'close']);
            Route::post('projects/{id}/reopen',                     [AdminProjectController::class, 'reopen']);
            Route::post('projects/{id}/invoices',                   [AdminProjectController::class, 'createInvoice']);
            Route::post('projects/{id}/invoices/link',              [AdminProjectController::class, 'linkInvoice']);
            Route::delete('projects/{id}/invoices/{invoiceId}',     [AdminProjectController::class, 'unlinkInvoice']);

            Route::get('projects/{projectId}/documents',            [AdminProjectDocumentController::class, 'index']);
            Route::post('projects/{projectId}/documents',           [AdminProjectDocumentController::class, 'store']);
            Route::get('projects/{projectId}/documents/{id}/download', [AdminProjectDocumentController::class, 'download']);
            Route::delete('projects/{projectId}/documents/{id}',    [AdminProjectDocumentController::class, 'destroy']);

            Route::get('projects/{projectId}/attachments',              [AdminProjectAttachmentController::class, 'index']);
            Route::post('projects/{projectId}/attachments',             [AdminProjectAttachmentController::class, 'store']);
            Route::get('projects/{projectId}/attachments/{id}/download', [AdminProjectAttachmentController::class, 'download']);
            Route::delete('projects/{projectId}/attachments/{id}',      [AdminProjectAttachmentController::class, 'destroy']);

            Route::get('projects/{projectId}/comments',             [AdminProjectCommentController::class, 'index']);
            Route::post('projects/{projectId}/comments',            [AdminProjectCommentController::class, 'store']);
            Route::patch('projects/{projectId}/comments/{commentId}', [AdminProjectCommentController::class, 'update']);
            Route::delete('projects/{projectId}/comments/{commentId}', [AdminProjectCommentController::class, 'destroy']);
            Route::post('projects/{projectId}/comments/{commentId}/like', [AdminProjectCommentController::class, 'toggleLike']);
            Route::get('projects/{projectId}/mentionable-users',    [AdminProjectCommentController::class, 'mentionableUsers']);
            Route::get('projects/{projectId}/comments/{commentId}/attachments',    [AdminProjectCommentAttachmentController::class, 'index']);
            Route::post('projects/{projectId}/comments/{commentId}/attachments',   [AdminProjectCommentAttachmentController::class, 'store']);
            Route::get('projects/{projectId}/comments/{commentId}/attachments/{id}/download', [AdminProjectCommentAttachmentController::class, 'download']);

            Route::get('projects/{projectId}/chat',                 [AdminProjectChatController::class, 'index']);
            Route::post('projects/{projectId}/chat',                [AdminProjectChatController::class, 'store']);
            Route::get('projects/{projectId}/chat/{messageId}/attachment', [AdminProjectChatController::class, 'downloadAttachment']);
            Route::post('projects/{projectId}/chat/participants',   [AdminProjectChatController::class, 'addClientParticipant']);

            // Project Chat — one thread per project (see ProjectChatService).
            Route::get('projects/{projectId}/messenger',                              [AdminProjectMessengerController::class, 'show']);
            Route::get('projects/{projectId}/messenger/eligible-participants',        [AdminProjectMessengerController::class, 'eligibleParticipants']);
            Route::get('projects/{projectId}/messenger/messages',                     [AdminProjectMessengerController::class, 'messages']);
            Route::post('projects/{projectId}/messenger/messages',                    [AdminProjectMessengerController::class, 'send']);
            Route::delete('projects/{projectId}/messenger/messages/{messageId}',      [AdminProjectMessengerController::class, 'deleteMessage']);
            Route::patch('projects/{projectId}/messenger/messages/{messageId}',       [AdminProjectMessengerController::class, 'updateMessage']);
            Route::post('projects/{projectId}/messenger/participants',                [AdminProjectMessengerController::class, 'addParticipant']);
            Route::delete('projects/{projectId}/messenger/participants/{userId}',     [AdminProjectMessengerController::class, 'removeParticipant']);
            Route::patch('projects/{projectId}/messenger/participants/{userId}/mute', [AdminProjectMessengerController::class, 'muteParticipant']);
            Route::get('projects/{projectId}/messenger/messages/{messageId}/attachment', [AdminProjectMessengerController::class, 'downloadAttachment']);

            // Client Chat — the per-project Client <-> Seller <-> Company Admin
            // conversation shown on the Client Portal's project page (see
            // ProjectClientChatService). Separate thread from the internal
            // messenger above, which the client never sees.
            Route::get('projects/{projectId}/client-chat',                              [\App\Http\Controllers\Api\Admin\ProjectClientChatController::class, 'index']);
            Route::post('projects/{projectId}/client-chat',                             [\App\Http\Controllers\Api\Admin\ProjectClientChatController::class, 'store']);
            Route::delete('projects/{projectId}/client-chat/{messageId}',               [\App\Http\Controllers\Api\Admin\ProjectClientChatController::class, 'deleteMessage']);
            Route::post('projects/{projectId}/client-chat/{messageId}/toggle-hide',     [\App\Http\Controllers\Api\Admin\ProjectClientChatController::class, 'toggleHide']);
            Route::get('projects/{projectId}/client-chat/{messageId}/attachment',       [\App\Http\Controllers\Api\Admin\ProjectClientChatController::class, 'downloadAttachment']);

            Route::get('projects/{id}/deliverables',                [AdminProductionController::class, 'deliverables']);
            Route::post('projects/{id}/deliverables',               [AdminProductionController::class, 'storeDeliverable']);
            Route::patch('deliverables/{id}/verify',                [AdminProductionController::class, 'verifyDeliverable']);
            Route::post('deliverables/{id}/request-revision',       [AdminProductionController::class, 'requestRevision']);
            Route::get('deliverables/{deliverableId}/revisions',    [AdminProductionController::class, 'revisions']);
            Route::patch('revisions/{id}/resolve',                  [AdminProductionController::class, 'resolveRevision']);
        });

        // Compliance — requires the compliance module. Phase 1 surface only
        // (cases/requirements/templates/officer assignment) — see
        // Api\Admin\ComplianceController.
        Route::middleware('module:compliance')->group(function () {
            Route::get('compliance/dashboard',                      [AdminComplianceController::class, 'dashboard']);
            Route::get('compliance/clients',                        [AdminComplianceController::class, 'clientsIndex']);
            Route::get('compliance/clients/{clientId}',              [AdminComplianceController::class, 'clientShow']);
            Route::get('compliance/cases',                          [AdminComplianceController::class, 'index']);
            Route::get('compliance/cases/{id}',                     [AdminComplianceController::class, 'show']);
            Route::get('compliance/projects/{projectId}/case',      [AdminComplianceController::class, 'showByProject']);
            Route::get('compliance/projects/{projectId}/overview',  [AdminComplianceController::class, 'projectOverview']);
            Route::get('compliance/projects/{projectId}/documents-zip', [AdminComplianceController::class, 'projectDocumentsZip']);
            Route::get('compliance/projects-zip',                   [AdminComplianceController::class, 'allProjectsZip']);
            Route::get('compliance/projects/{projectId}/client-chat',     [AdminComplianceController::class, 'projectClientChat']);
            Route::get('compliance/projects/{projectId}/client-chat/export', [AdminComplianceController::class, 'projectChatExport']);
            Route::get('compliance/projects/{projectId}/client-attachments', [AdminComplianceController::class, 'projectClientAttachments']);
            Route::get('compliance/client-attachments/{messageId}/download', [AdminComplianceController::class, 'projectClientAttachmentDownload']);
            // The other half of Client Files: the payment receipts generated
            // for what the client actually paid on this project's invoices.
            // Read-only — receipts are produced by the payment pipeline, never
            // here. Both routes re-check the receipt against the active company
            // AND against a compliance project of that company, so the id in
            // the URL cannot reach another tenant's billing.
            Route::get('compliance/projects/{projectId}/client-receipts', [AdminComplianceController::class, 'projectClientReceipts']);
            Route::get('compliance/client-receipts/{receiptId}/image',     [AdminComplianceController::class, 'projectClientReceiptImage']);
            Route::get('compliance/projects/{projectId}/general-chat',    [AdminComplianceController::class, 'projectGeneralChat']);
            Route::get('compliance/projects/{projectId}/attachments',     [AdminComplianceController::class, 'projectAttachments']);
            Route::get('compliance/projects/{projectId}/task-attachments', [AdminComplianceController::class, 'projectTaskAttachments']);
            Route::get('compliance/attachments/{attachmentId}/download', [AdminComplianceController::class, 'projectAttachmentDownload']);
            Route::get('compliance/projects/{projectId}/tasks',          [AdminComplianceController::class, 'projectTasks']);
            Route::get('compliance/tasks/{taskId}/detail',               [AdminComplianceController::class, 'projectTaskDetail']);
            Route::get('compliance/task-attachments/{attachmentId}/download', [AdminComplianceController::class, 'taskAttachmentDownload']);
            Route::get('compliance/projects/{projectId}/deliverables',   [AdminComplianceController::class, 'projectDeliverables']);
            Route::get('compliance/deliveries/{deliveryId}/download',    [AdminComplianceController::class, 'deliverySubmissionDownload']);
            Route::get('compliance/projects/{projectId}/billing',        [AdminComplianceController::class, 'projectBilling']);
            Route::get('compliance/invoices/{invoiceId}/download',       [AdminComplianceController::class, 'invoiceDownload']);
            Route::get('compliance/projects/{projectId}/team',           [AdminComplianceController::class, 'projectTeam']);
            Route::get('compliance/projects/{projectId}/comments',       [AdminComplianceController::class, 'projectComments']);
            Route::get('compliance/projects/{projectId}/timesheets',     [AdminComplianceController::class, 'projectTimesheets']);
            Route::get('compliance/projects/{projectId}/history',        [AdminComplianceController::class, 'projectHistory']);
            Route::get('compliance/projects/{projectId}/lead',           [AdminComplianceController::class, 'projectLead']);
            Route::patch('compliance/cases/{id}/officer',           [AdminComplianceController::class, 'assignOfficer']);
            Route::patch('compliance/cases/{id}/status',            [AdminComplianceController::class, 'caseStatusUpdate']);
            Route::post('compliance/cases/{id}/requirements',       [AdminComplianceController::class, 'requirementsStore']);
            Route::put('compliance/requirements/{id}',              [AdminComplianceController::class, 'requirementsUpdate']);
            Route::delete('compliance/requirements/{id}',           [AdminComplianceController::class, 'requirementsDestroy']);
            Route::post('compliance/cases/{id}/documents',          [AdminComplianceController::class, 'documentsStore']);
            Route::post('compliance/documents/{id}/versions',       [AdminComplianceController::class, 'documentsAddVersion']);
            Route::get('compliance/documents/{id}/download',        [AdminComplianceController::class, 'documentsDownload']);
            Route::patch('compliance/documents/{id}/review',        [AdminComplianceController::class, 'documentsReview']);
            Route::get('compliance/cases/{id}/checklist',           [AdminComplianceController::class, 'checklistIndex']);
            Route::post('compliance/cases/{id}/checklist/generate', [AdminComplianceController::class, 'checklistGenerate']);
            Route::post('compliance/cases/{id}/checklist',          [AdminComplianceController::class, 'checklistStore']);
            Route::patch('compliance/checklist-items/{id}/toggle',  [AdminComplianceController::class, 'checklistToggle']);
            Route::get('compliance/cases/{id}/comments',            [AdminComplianceController::class, 'commentsIndex']);
            Route::post('compliance/cases/{id}/comments',           [AdminComplianceController::class, 'commentsStore']);
            Route::delete('compliance/comments/{id}',               [AdminComplianceController::class, 'commentsDestroy']);
            Route::get('compliance/cases/{id}/activity',            [AdminComplianceController::class, 'activityIndex']);
            Route::get('compliance/templates',                      [AdminComplianceController::class, 'templatesIndex']);
            Route::post('compliance/templates',                     [AdminComplianceController::class, 'templatesStore']);
            Route::put('compliance/templates/{id}',                 [AdminComplianceController::class, 'templatesUpdate']);
            Route::delete('compliance/templates/{id}',              [AdminComplianceController::class, 'templatesDestroy']);
            Route::patch('compliance/templates/{id}/default',       [AdminComplianceController::class, 'templatesSetDefault']);
            Route::post('compliance/templates/{id}/requirements',   [AdminComplianceController::class, 'templateRequirementsStore']);
            Route::put('compliance/template-requirements/{id}',     [AdminComplianceController::class, 'templateRequirementsUpdate']);
            Route::delete('compliance/template-requirements/{id}',  [AdminComplianceController::class, 'templateRequirementsDestroy']);
        });

        // Tasks — requires tasks module
        Route::middleware('module:tasks')->group(function () {
            Route::get('tasks',                                     [AdminTaskController::class, 'indexAll']);
            // Resolves a bare task id to its project_id, for the guard-
            // agnostic /task/{id} share-link redirector (see frontend
            // app/task/[taskId]/page.tsx) — a "Copy Link" click doesn't know
            // which project_id to put in the URL, so it links here instead
            // and lets the destination guard-specific page do the real
            // permission check once it knows where to go.
            Route::get('tasks/{id}/lookup',                         [AdminTaskController::class, 'lookup']);
            Route::get('projects/{projectId}/tasks',                [AdminTaskController::class, 'index']);
            Route::post('projects/{projectId}/tasks',                [AdminTaskController::class, 'store']);
            Route::put('projects/{projectId}/tasks/{id}',            [AdminTaskController::class, 'update']);
            Route::delete('projects/{projectId}/tasks/{id}',         [AdminTaskController::class, 'destroy']);
            Route::get('projects/{projectId}/tasks/{id}/activity',   [AdminTaskController::class, 'activity']);

            Route::get('projects/{projectId}/tasks/{taskId}/attachments',                [AdminTaskAttachmentController::class, 'index']);
            Route::post('projects/{projectId}/tasks/{taskId}/attachments',               [AdminTaskAttachmentController::class, 'store']);
            Route::get('projects/{projectId}/tasks/{taskId}/attachments/{id}/download',  [AdminTaskAttachmentController::class, 'download']);
            Route::delete('projects/{projectId}/tasks/{taskId}/attachments/{id}',        [AdminTaskAttachmentController::class, 'destroy']);
        });

        // Timesheets — requires timesheets module
        Route::middleware('module:timesheets')->group(function () {
            Route::get('timesheets',                                [AdminTimesheetController::class, 'index']);
            Route::post('timesheets',                               [AdminTimesheetController::class, 'store']);
            Route::patch('timesheets/{id}/approve',                 [AdminTimesheetController::class, 'approve']);
            Route::get('timesheets/export',                         [AdminTimesheetController::class, 'export']);
        });

        // HR — Employees (base sub-module; HR dashboard/reports ride on this
        // key too, same pattern Project Reports rides on 'projects')
        Route::middleware('module:employees')->group(function () {
            Route::get('hr/dashboard',                              [AdminHrDashboardController::class, 'index']);
            Route::get('hr/reports/headcount',                      [AdminHrReportController::class, 'headcountByDepartment']);
            Route::get('hr/reports/attendance-summary',             [AdminHrReportController::class, 'attendanceSummary']);
            Route::get('hr/reports/leave-summary',                  [AdminHrReportController::class, 'leaveSummary']);

            Route::get('employees',                                 [AdminEmployeeController::class, 'index']);
            Route::post('employees',                                [AdminEmployeeController::class, 'store']);
            Route::get('employees/{id}',                            [AdminEmployeeController::class, 'show']);
            Route::put('employees/{id}',                            [AdminEmployeeController::class, 'update']);
            Route::patch('employees/{id}/deactivate',               [AdminEmployeeController::class, 'deactivate']);
            Route::patch('employees/{id}/reactivate',               [AdminEmployeeController::class, 'reactivate']);
            Route::delete('employees/{id}',                         [AdminEmployeeController::class, 'destroy']);

            Route::get('employees/{id}/documents',                  [AdminEmployeeController::class, 'documents']);
            Route::post('employees/{id}/documents',                 [AdminEmployeeController::class, 'uploadDocument']);
            Route::delete('employees/{id}/documents/{documentId}',  [AdminEmployeeController::class, 'deleteDocument']);

            Route::get('employees/{id}/notes',                      [AdminEmployeeController::class, 'notes']);
            Route::post('employees/{id}/notes',                     [AdminEmployeeController::class, 'addNote']);

            Route::get('departments',                               [AdminDepartmentController::class, 'index']);
            Route::post('departments',                              [AdminDepartmentController::class, 'store']);
            Route::put('departments/{id}',                          [AdminDepartmentController::class, 'update']);
            Route::delete('departments/{id}',                       [AdminDepartmentController::class, 'destroy']);

            Route::get('shifts',                                    [AdminShiftController::class, 'index']);
            Route::post('shifts',                                   [AdminShiftController::class, 'store']);
            Route::put('shifts/{id}',                                [AdminShiftController::class, 'update']);
            Route::delete('shifts/{id}',                             [AdminShiftController::class, 'destroy']);
        });

        // HR — Attendance
        Route::middleware('module:attendance')->group(function () {
            Route::get('attendance',                                [AdminAttendanceController::class, 'index']);
            Route::post('attendance/mark',                          [AdminAttendanceController::class, 'mark']);
            Route::post('attendance/employees/{employeeId}/check-in', [AdminAttendanceController::class, 'checkInEmployee']);
            Route::post('attendance/employees/{employeeId}/check-out', [AdminAttendanceController::class, 'checkOutEmployee']);
            Route::post('attendance/bulk-mark',                     [AdminAttendanceController::class, 'bulkMark']);
            Route::post('attendance/sync-now',                      [AdminAttendanceController::class, 'syncNow']);
            Route::get('attendance/summary',                        [AdminAttendanceController::class, 'summary']);
            Route::get('attendance/live',                           [AdminAttendanceController::class, 'live']);
            Route::get('attendance/calendar',                       [AdminAttendanceController::class, 'calendar']);
            Route::get('attendance/policy',                         [AdminAttendancePolicyController::class, 'show']);
            Route::put('attendance/policy',                         [AdminAttendancePolicyController::class, 'update']);
            Route::post('attendance/{id}/correct',                  [AdminAttendanceController::class, 'correct']);
            Route::get('attendance/{id}/history',                   [AdminAttendanceController::class, 'history']);
        });

        // HR — Leave
        Route::middleware('module:leaves')->group(function () {
            Route::get('leaves',                                    [AdminLeaveController::class, 'index']);
            Route::post('leaves',                                   [AdminLeaveController::class, 'store']);
            Route::patch('leaves/{id}/status',                      [AdminLeaveController::class, 'updateStatus']);
        });

        // HR — Payroll
        Route::middleware('module:payroll')->group(function () {
            Route::get('payroll',                                   [AdminPayrollController::class, 'index']);
            Route::get('payroll/deduction-preview',                 [AdminPayrollController::class, 'deductionPreview']);
            Route::post('payroll',                                  [AdminPayrollController::class, 'store']);
            Route::put('payroll/{id}',                              [AdminPayrollController::class, 'update']);
            Route::patch('payroll/{id}/process',                    [AdminPayrollController::class, 'process']);
            Route::patch('payroll/{id}/mark-paid',                  [AdminPayrollController::class, 'markPaid']);
            Route::get('payroll/{id}/payslip',                      [AdminPayrollController::class, 'payslip']);
        });

        // HR — Recruitment
        Route::middleware('module:recruitment')->group(function () {
            Route::get('recruitment',                               [AdminRecruitmentController::class, 'index']);
            Route::post('recruitment',                              [AdminRecruitmentController::class, 'store']);
            Route::get('recruitment/{id}',                          [AdminRecruitmentController::class, 'show']);
            Route::put('recruitment/{id}',                          [AdminRecruitmentController::class, 'update']);
            Route::delete('recruitment/{id}',                       [AdminRecruitmentController::class, 'destroy']);
            Route::post('recruitment/{id}/applicants',              [AdminRecruitmentController::class, 'addApplicant']);
            Route::patch('recruitment/{id}/applicants/{applicantId}/status', [AdminRecruitmentController::class, 'updateApplicantStatus']);
        });

        // Reports
        Route::get('reports/invoices',                   [AdminReportController::class, 'invoices']);

        // Settings
        Route::get('settings',                           [AdminSettingsController::class, 'show']);
        Route::put('settings/company',                   [AdminSettingsController::class, 'updateCompany']);
        Route::put('settings/invoice',                   [AdminSettingsController::class, 'updateInvoice']);
        Route::put('settings/bank',                      [AdminSettingsController::class, 'updateBank']);
        Route::post('settings/logo',                     [AdminSettingsController::class, 'uploadLogo']);
        Route::post('settings/gateways',                   [AdminSettingsController::class, 'storeGateway']);
        Route::put('settings/gateways/{id}',               [AdminSettingsController::class, 'updateGateway']);
        Route::patch('settings/gateways/{id}/toggle',      [AdminSettingsController::class, 'toggleGateway']);
        Route::patch('settings/gateways/{id}/default',     [AdminSettingsController::class, 'setDefaultGateway']);
        Route::delete('settings/gateways/{id}',            [AdminSettingsController::class, 'destroyGateway']);
        Route::post('settings/gateways/{id}/test',         [AdminSettingsController::class, 'testGateway']);
        Route::get('settings/deal-workflow',               [AdminSettingsController::class, 'dealWorkflowSettings']);
        Route::put('settings/deal-workflow',                [AdminSettingsController::class, 'updateDealWorkflowSettings']);

        // Settings > Storage — Company Admin only, no staff (`Api\User`)
        // counterpart exists for any of these three (see the class note on
        // Api\Admin\StorageController). `google-drive/connect-url` is also
        // what "Change Google Account" calls — same action, different label.
        Route::get('storage',                              [\App\Http\Controllers\Api\Admin\StorageController::class, 'show']);
        Route::get('storage/google-drive/connect-url',      [\App\Http\Controllers\Api\Admin\StorageController::class, 'connectUrl']);
        Route::post('storage/google-drive/disconnect',      [\App\Http\Controllers\Api\Admin\StorageController::class, 'disconnect']);
        Route::post('storage/google-drive/refresh',         [\App\Http\Controllers\Api\Admin\StorageController::class, 'refreshQuota']);

        // Subscription payment (registration flow) — deliberately reachable
        // while pending_payment (that's the whole point of these routes);
        // reachable normally once active too.
        Route::get('subscription/plans',                 [SubscriptionPaymentController::class, 'plans'])->withoutMiddleware(\App\Http\Middleware\EnsureSubscriptionActive::class);
        Route::get('subscription/payment-init',         [SubscriptionPaymentController::class, 'init'])->withoutMiddleware(\App\Http\Middleware\EnsureSubscriptionActive::class);
        Route::get('subscription/order-summary',        [SubscriptionPaymentController::class, 'orderSummary'])->withoutMiddleware(\App\Http\Middleware\EnsureSubscriptionActive::class);
        // The register screen's Packages / Custom Modules step, which now runs
        // AFTER the account exists — so it has to be reachable by exactly the
        // pending_payment admin this gate would otherwise block.
        Route::post('subscription/select-plan',        [SubscriptionPaymentController::class, 'selectPlan'])->withoutMiddleware(\App\Http\Middleware\EnsureSubscriptionActive::class);
        Route::post('subscription/paypal/create-order', [SubscriptionPaymentController::class, 'createPaypalOrder'])->withoutMiddleware(\App\Http\Middleware\EnsureSubscriptionActive::class);
        Route::post('subscription/process',             [SubscriptionPaymentController::class, 'process'])->withoutMiddleware(\App\Http\Middleware\EnsureSubscriptionActive::class);
        Route::get('payment-gateways',                  [SubscriptionPaymentController::class, 'activeGateways'])->withoutMiddleware(\App\Http\Middleware\EnsureSubscriptionActive::class);

        // Upgrade Modules — buy additional modules after registration
        Route::get('modules/catalog',                   [AdminModulePurchaseController::class, 'catalog']);
        Route::post('modules/purchase',                 [AdminModulePurchaseController::class, 'purchase']);

        // Upgrade Seats / Company Slots — raise per-admin limits after registration
        Route::get('seats/catalog',                     [AdminSeatPurchaseController::class, 'catalog']);
        Route::post('seats/purchase',                   [AdminSeatPurchaseController::class, 'purchase']);
    });
});

/*
|--------------------------------------------------------------------------
| User Auth Routes  (guard: sanctum → User model)
|--------------------------------------------------------------------------
*/
Route::prefix('user')->group(function () use ($financeRoutes) {
    Route::post('login', [UserAuthController::class, 'login']);
    Route::post('forgot-password', [ForgotPasswordController::class, 'sendResetLink']);
    Route::post('reset-password',  [ForgotPasswordController::class, 'reset']);

    // Google OAuth (staff/sub-user login only) — unauthenticated by design.
    Route::get('auth/google/redirect',  [GoogleAuthController::class, 'redirect']);
    Route::get('auth/google/callback',  [GoogleAuthController::class, 'callback']);
    Route::post('auth/google/exchange', [GoogleAuthController::class, 'exchange']);

    // 'tenant.active' runs AFTER 'sync.active.company' — it has to gate on
    // the company this request is actually being made under, so a staff
    // member assigned to two tenants is only blocked on the one the Super
    // Admin switched off (same ordering requirement the subscription gate
    // beside it documents). It runs BEFORE that gate because a deactivated
    // tenant outranks whatever its subscription is doing, and has no in-app
    // way back — see Http\Middleware\EnsureTenantActive.
    //
    // `logout` is exempt so a client holding a token can hand it back; `me`
    // is NOT, since a blocked staff member has no session to report on.
    Route::middleware(['auth:sanctum', 'sync.active.company', 'tenant.active', 'company.subscription.active', 'company.not_suspended'])->group(function () use ($financeRoutes) {
        Route::post('logout', [UserAuthController::class, 'logout'])
            ->withoutMiddleware([\App\Http\Middleware\EnsureTenantActive::class, \App\Http\Middleware\EnsureCompanySubscriptionActive::class, \App\Http\Middleware\EnsureCompanyNotSuspended::class]);
        Route::get('me',     [UserAuthController::class, 'me'])
            ->withoutMiddleware([\App\Http\Middleware\EnsureCompanySubscriptionActive::class, \App\Http\Middleware\EnsureCompanyNotSuspended::class, \App\Http\Middleware\EnsureCompanyAdminActive::class]);

        // Finance Area — staff side. Identical list to the Company Admin
        // group's, behind the identical outer module gate; every permission
        // check inside is the staff-only branch of FinanceContext, which the
        // admin guard bypasses.
        Route::middleware('module:finance_dashboard')->group($financeRoutes);

        // My Profile — every sub-user's own name/phone/avatar/password,
        // regardless of role_type; has nothing to do with permissions.
        Route::get('profile',            [UserProfileController::class, 'show']);
        Route::put('profile',            [UserProfileController::class, 'update']);
        Route::post('profile/avatar',    [UserProfileController::class, 'uploadAvatar']);
        Route::put('profile/password',   [UserProfileController::class, 'changePassword']);

        // Settings Management — the staff-side mirror of the Company Admin's
        // own /admin/settings routes, serving the same settings for whichever
        // company this user is working under. Gated INSIDE the controller on
        // the account module's canManageSettings (not by a `permission:`
        // middleware, which reads the legacy user_permissions table rather
        // than the per-company user_company_permissions rows this needs), and
        // not module-gated: `account` is never a purchased module, same as
        // canAddUsers/canUseGeneralChat above.
        //
        // The permission is off for every role by default — see
        // RoleDefaultPermissions::ALWAYS_EXPLICIT_PERMISSIONS — so these
        // routes answer 403 until a Company Admin ticks "Settings Management
        // Permission" for this person on this company, and answer 403 again
        // on the very next request after it is unticked.
        Route::get('settings',                          [UserSettingsController::class, 'show']);
        Route::put('settings/company',                  [UserSettingsController::class, 'updateCompany']);
        Route::put('settings/invoice',                  [UserSettingsController::class, 'updateInvoice']);
        Route::put('settings/bank',                     [UserSettingsController::class, 'updateBank']);
        Route::post('settings/logo',                    [UserSettingsController::class, 'uploadLogo']);
        Route::post('settings/gateways',                [UserSettingsController::class, 'storeGateway']);
        Route::put('settings/gateways/{id}',            [UserSettingsController::class, 'updateGateway']);
        Route::patch('settings/gateways/{id}/toggle',   [UserSettingsController::class, 'toggleGateway']);
        Route::patch('settings/gateways/{id}/default',  [UserSettingsController::class, 'setDefaultGateway']);
        Route::delete('settings/gateways/{id}',         [UserSettingsController::class, 'destroyGateway']);
        Route::post('settings/gateways/{id}/test',      [UserSettingsController::class, 'testGateway']);
        Route::get('settings/deal-workflow',            [UserSettingsController::class, 'dealWorkflowSettings']);
        Route::put('settings/deal-workflow',            [UserSettingsController::class, 'updateDealWorkflowSettings']);

        // Notifications — not module-gated, a base feature for every sub-user.
        Route::get('notifications',                     [UserNotificationController::class, 'index']);
        Route::get('notifications/unread-counts',       [UserNotificationController::class, 'unreadCounts']);
        Route::patch('notifications/mark-category-read', [UserNotificationController::class, 'markCategoryRead']);
        Route::patch('notifications/{id}/read',         [UserNotificationController::class, 'markRead']);
        Route::patch('notifications/{id}/open',         [UserNotificationController::class, 'open']);
        Route::patch('notifications/read-all',          [UserNotificationController::class, 'markAllRead']);
        Route::delete('notifications/{id}',              [UserNotificationController::class, 'clear']);
        Route::delete('notifications',                   [UserNotificationController::class, 'clearAll']);

        // General Chat — not module-gated (canUseGeneralChat lives in the
        // always-available `account` catalog module, not a purchasable one).
        Route::get('chat',                                [UserGeneralChatController::class, 'index']);
        Route::get('chat/eligible-users',                 [UserGeneralChatController::class, 'eligibleUsers']);
        Route::post('chat/direct',                        [UserGeneralChatController::class, 'createDirect']);
        Route::post('chat/group',                         [UserGeneralChatController::class, 'createGroup']);
        Route::post('chat/{threadId}/participants',       [UserGeneralChatController::class, 'addParticipant']);
        Route::delete('chat/{threadId}/participants/{userId}', [UserGeneralChatController::class, 'removeParticipant']);
        Route::patch('chat/{threadId}/mute',              [UserGeneralChatController::class, 'toggleMute']);
        Route::get('chat/{threadId}/messages',            [UserGeneralChatController::class, 'messages']);
        Route::post('chat/{threadId}/messages',           [UserGeneralChatController::class, 'send']);
        Route::patch('chat/{threadId}/messages/{messageId}', [UserGeneralChatController::class, 'updateMessage']);
        Route::delete('chat/{threadId}/messages/{messageId}', [UserGeneralChatController::class, 'deleteMessage']);
        Route::get('chat/{threadId}/messages/{messageId}/attachment', [UserGeneralChatController::class, 'downloadAttachment']);

        // AI Assistance (staff, including the Seller Portal) — Client Messages.
        //
        // One read-only endpoint, and no chat with a model: a seller must not
        // have to ask an assistant anything before seeing what their client
        // wrote. It returns ONLY conversations this user is a participant of
        // AND that the client started, so `visible` is false for a seller who
        // has never been contacted — and the floating widget does not render
        // for them at all.
        //
        // Not module-gated, same reasoning as General Chat above: there is no
        // purchasable module behind it, and membership is the real gate.
        // Reading and replying use the direct-chat routes below.
        Route::get('assistant/conversations', [\App\Http\Controllers\Api\User\AssistantController::class, 'conversations'])
            ->middleware('throttle:60,1');
        // Clears the unread badge — fired when the widget (or one conversation)
        // is opened. Writes chat_participants.last_read_at, which nothing on
        // the staff side was writing for these threads before.
        Route::post('assistant/read', [\App\Http\Controllers\Api\User\AssistantController::class, 'markRead'])
            ->middleware('throttle:60,1');

        // Client management — NOT wrapped in `module:clients`, unlike Admin's
        // equivalent routes: "Basic Clients" access is grantable via either
        // the Client module OR the Sales module (see ClientController::can()),
        // and CheckCompanyModule only supports gating on a single module key.
        // Enforcement here is by permission (canViewClients/canCreateClients/
        // canEditClients) rather than module purchase, to avoid 403-ing a
        // Sales-only company's legitimate "Basic Clients" access. Still needs
        // `active.company` though — permission checks alone don't catch a
        // user whose company assignments are all suspended (their
        // UserCompanyPermission rows survive suspension).
        Route::middleware('active.company')->group(function () {
            Route::get('clients',                            [UserClientController::class,  'index']);
            Route::post('clients',                           [UserClientController::class,  'store']);
            // Picker list for the Transfer Client modal — must be registered
            // before clients/{id} below, same ordering reason as leads/company-users.
            Route::get('clients/company-users',              [UserClientController::class,  'companyUsers']);
            Route::get('clients/{id}',                       [UserClientController::class,  'show']);
            Route::put('clients/{id}',                       [UserClientController::class,  'update']);
            Route::post('clients/{id}/transfer',             [UserClientController::class,  'transfer']);
            // "Link to AI-Suggested Service" dropdown on Create Invoice — see
            // App\Services\ClientAiServiceBatchService.
            Route::get('clients/{id}/ai-service-batch-items', [UserClientController::class, 'aiServiceBatchItems']);
            // Blast radius of a delete — the confirmation modal reads this first.
            Route::get('clients/{id}/delete-summary',        [UserClientController::class,  'deleteSummary']);
            Route::delete('clients/{id}',                    [UserClientController::class,  'destroy']);
            Route::put('clients/{id}/permissions',           [UserClientController::class,  'updatePermissions']);
            Route::post('clients/{id}/enable-portal',        [UserClientController::class,  'enablePortal']);
            Route::post('clients/{id}/disable-portal',       [UserClientController::class,  'disablePortal']);
            // Sales Chat for clients — same ungated rationale as the routes above.
            Route::get('clients/{id}/chat',                  [UserSalesChatController::class, 'clientMessages']);
            Route::post('clients/{id}/chat',                 [UserSalesChatController::class, 'sendClientMessage']);
            Route::get('clients/{id}/chat/{messageId}/attachment', [UserSalesChatController::class, 'downloadClientAttachment']);

            // "Client Messages" — the client's own restricted Direct Chat (see
            // Api\Client\ChatController) — only shows threads this Seller/Finance
            // user is actually a participant of. start() additionally lets this
            // staff member INITIATE a chat with a client they're linked to
            // (account manager, or sent an invoice), not just reply to one the
            // client already started.
            Route::get('clients/{id}/direct-chat',                     [\App\Http\Controllers\Api\User\ClientChatController::class, 'index']);
            Route::post('clients/{id}/direct-chat/start',              [\App\Http\Controllers\Api\User\ClientChatController::class, 'startChat']);
            Route::get('clients/{id}/direct-chat/{threadId}/messages', [\App\Http\Controllers\Api\User\ClientChatController::class, 'messages']);
            Route::post('clients/{id}/direct-chat/{threadId}/reply',   [\App\Http\Controllers\Api\User\ClientChatController::class, 'reply']);
            // Loops a Project Manager into an existing chat (Seller-initiated —
            // "have the PM contact this client too") and lets specific replies
            // stay hidden from that PM (see reply()'s hidden_from_user_ids).
            Route::post('clients/{id}/direct-chat/{threadId}/participants',            [\App\Http\Controllers\Api\User\ClientChatController::class, 'addParticipant']);
            Route::delete('clients/{id}/direct-chat/{threadId}/participants/{userId}', [\App\Http\Controllers\Api\User\ClientChatController::class, 'removeParticipant']);
        });

        // Client Support Tickets — enforced by canViewClientSupport/
        // canManageClientSupport inside the controller (same ungated-route
        // rationale as the Client routes above).
        Route::get('support',                            [UserSupportController::class, 'index']);
        Route::get('support/{id}',                       [UserSupportController::class, 'show']);
        Route::get('support/{id}/attachment',            [UserSupportController::class, 'downloadAttachment']);
        Route::get('support/{id}/replies/{replyId}/attachment', [UserSupportController::class, 'downloadReplyAttachment']);
        Route::post('support/{id}/reply',                [UserSupportController::class, 'reply']);
        Route::patch('support/{id}/assign',               [UserSupportController::class, 'assign']);
        Route::patch
        ('support/{id}/status',               [UserSupportController::class, 'updateStatus']);

        // Staff-side "add a user" — gated per-module on canAddUsers, scoped to
        // the acting staff member's own company and their own permission
        // ceiling. `active.company` on top for the same reason as the Client
        // routes above: canAddUsers is keyed off company_id, which survives
        // a suspended assignment.
        //
        // The rest of the delegated User Management interface hangs off the
        // same grant: a staff member who holds it gets the Company Admin's
        // own screens (list, add, edit, roles, permissions, suspend, remove),
        // limited at every step to the companies they are actually assigned
        // to — see UserManagementController::manageableCompanyIds(). Static
        // segments are declared BEFORE /{user} so `roles`/`company-options`
        // aren't swallowed by the model binding.
        Route::middleware('active.company')->group(function () {
            Route::post('users', [UserManagementController::class, 'store']);
            Route::get('users',                                  [UserManagementController::class, 'index']);
            Route::get('users/company-options',                  [UserManagementController::class, 'companyOptions']);
            Route::get('users/check-email',                      [UserManagementController::class, 'checkEmail']);
            Route::get('users/roles',                            [UserManagementController::class, 'roleOptions']);
            Route::get('users/role-defaults',                    [UserManagementController::class, 'roleDefaults']);
            // Deputy-only (the Admin role). Every one of these refuses an
            // ordinary delegated User Manager outright — see
            // UserManagementController::refuseUnlessDeputy(). Declared before
            // users/{user} so none of the static paths is read as an id.
            Route::post('users/invite',                          [UserManagementController::class, 'invite']);
            Route::get('users/{user}',                           [UserManagementController::class, 'show']);
            Route::put('users/{user}',                           [UserManagementController::class, 'update']);
            Route::delete('users/{user}',                        [UserManagementController::class, 'destroy']);
            Route::patch('users/{user}/toggle-status',           [UserManagementController::class, 'toggleStatus']);
            Route::post('users/{user}/resend-invite',            [UserManagementController::class, 'resendInvite']);
            Route::post('users/{user}/reset-password',           [UserManagementController::class, 'resetPassword']);
            Route::get('users/{user}/company-workload',          [UserManagementController::class, 'companyWorkload']);
            Route::get('users/{user}/delete-impact',             [UserManagementController::class, 'deleteImpact']);
            Route::post('users/{user}/reassign',                 [UserManagementController::class, 'reassignWorkload']);
            Route::delete('users/{user}/permanent',              [UserManagementController::class, 'destroyPermanently']);
            Route::get('users/{user}/activity',                  [UserManagementController::class, 'activity']);
            Route::get('users/{user}/company-permissions/{companyId}', [UserManagementController::class, 'getCompanyPermissions']);
            Route::put('users/{user}/company-permissions/{companyId}', [UserManagementController::class, 'updateCompanyPermissions']);
        });

        // Sales — sub-user leads & follow-ups — requires leads module, matching
        // the Admin side's own `module:leads` gate.
        Route::middleware('module:leads')->group(function () {
            Route::get('sales/dashboard',                [UserSalesDashboardController::class, 'index']);
            Route::get('sales/reports/leads',             [UserSalesReportController::class, 'leadReport']);
            Route::get('sales/reports/conversion',         [UserSalesReportController::class, 'conversionReport']);
            Route::get('sales/reports/performance',        [UserSalesReportController::class, 'performanceReport']);
            Route::get('sales/reports/leads/export',       [UserSalesReportController::class, 'exportLeadReport']);
            Route::get('sales/targets',                    [UserSalesTargetController::class, 'index']);
            Route::put('sales/targets',                    [UserSalesTargetController::class, 'upsert']);
            // Lead Manager only (canManageSalesTargets) — every Seller's
            // target, not just the caller's own.
            Route::get('sales/targets/team',                [UserSalesTargetController::class, 'team']);
            Route::put('sales/targets/team/{userId}',       [UserSalesTargetController::class, 'updateForUser']);
            Route::get('leads/pipeline',                 [UserLeadController::class,    'pipeline']);
            Route::get('leads/company-users',            [UserLeadController::class,    'companyUsers']);
            Route::get('leads',                          [UserLeadController::class,    'index']);
            Route::post('leads',                         [UserLeadController::class,    'store']);
            Route::get('leads/{id}',                     [UserLeadController::class,    'show']);
            Route::put('leads/{id}',                     [UserLeadController::class,    'update']);
            Route::patch('leads/{id}/status',            [UserLeadController::class,    'updateStatus']);
            Route::post('leads/{id}/transfer',           [UserLeadController::class,    'transfer']);
            Route::post('leads/{id}/convert',            [UserLeadController::class,    'convert']);
            Route::get('leads/{id}/project-eligibility', [UserLeadController::class,    'projectEligibility']);
            // Sales Chat — Seller<->Lead conversation, separate from Project Chat
            Route::get('leads/{id}/chat',                [UserSalesChatController::class, 'leadMessages']);
            Route::post('leads/{id}/chat',               [UserSalesChatController::class, 'sendLeadMessage']);
            Route::get('leads/{id}/chat/{messageId}/attachment', [UserSalesChatController::class, 'downloadLeadAttachment']);

            // The Lead's own no-login chat link — same three actions the
            // Company Admin has, gated on this user's own lead visibility
            // plus canEditLeads. See App\Services\LeadChatInviteService.
            // See the admin counterpart above — same service, same
            // "suggestion only, never an auto-applied value" contract.
            Route::get('leads/{id}/ai-project-title',    [UserLeadController::class, 'aiProjectTitle']);
            Route::get('leads/{id}/chat-invite',         [UserLeadController::class, 'chatInvite']);
            Route::post('leads/{id}/chat-invite',        [UserLeadController::class, 'sendChatInvite']);
            Route::delete('leads/{id}/chat-invite',      [UserLeadController::class, 'revokeChatInvite']);
            Route::post('leads/{id}/follow-ups',         [UserFollowUpController::class,'store']);
            Route::get('follow-ups',                     [UserFollowUpController::class,'queue']);
            Route::patch('follow-ups/{id}/complete',     [UserFollowUpController::class,'complete']);
            Route::patch('follow-ups/{id}/miss',         [UserFollowUpController::class,'miss']);
            Route::patch('follow-ups/{id}/cancel',       [UserFollowUpController::class,'cancel']);
        });

        // Invoices — requires invoices module, matching the Admin side's own
        // `module:invoices` gate.
        Route::middleware('module:invoices')->group(function () {
            Route::get('invoices',                       [UserInvoiceController::class, 'index']);
            Route::post('invoices',                      [UserInvoiceController::class, 'store']);
            Route::get('invoices/gateway-accounts',      [UserInvoiceController::class, 'gatewayAccounts']);
            // Brand Invoice dropdown — only the brands assigned to this user.
            Route::get('invoices/brands',                [UserInvoiceController::class, 'brands']);
            // Staff counterpart to the admin route of the same name — same
            // shape, scoped to the company the caller is working under.
            Route::get('invoices/lead-budget',           [UserInvoiceController::class, 'leadBudget']);
            Route::get('invoices/{id}',                  [UserInvoiceController::class, 'show']);
            // The staff counterpart to PUT /admin/invoices/{invoice}. Absent
            // until now, which left canEditInvoices granted but unusable by
            // any sub-user and the Edit Invoice screen Company Admin-only.
            // Finance > Invoices needs Update to work for staff, so the gap
            // is filled here rather than with a Finance-private copy.
            Route::put('invoices/{id}',                  [UserInvoiceController::class, 'update']);
            Route::post('invoices/{id}/send-email',      [UserInvoiceController::class, 'sendEmail']);
            Route::post('invoices/{id}/generate-link',   [UserInvoiceController::class, 'generateLink']);
            Route::get('reports/invoices',               [UserInvoiceController::class, 'report']);

            // Sales Chat for a fully guest invoice (no Lead/Client) — see
            // Api\User\SalesChatController::invoiceMessages().
            Route::get('invoices/{id}/chat',                      [UserSalesChatController::class, 'invoiceMessages']);
            Route::post('invoices/{id}/chat',                     [UserSalesChatController::class, 'sendInvoiceMessage']);
            Route::get('invoices/{id}/chat/{messageId}/attachment', [UserSalesChatController::class, 'downloadInvoiceAttachment']);

            // Brands — same four routes as the Admin side, each gated on its
            // own Invoice-module permission (canView/Create/Edit/DeleteBrands)
            // and scoped to the caller's active company. /permissions tells
            // the UI which buttons to render.
            Route::get('brands',                      [UserBrandController::class, 'index']);
            Route::post('brands',                     [UserBrandController::class, 'store']);
            Route::get('brands/permissions',          [UserBrandController::class, 'permissions']);
            Route::get('brands/assignable-users',     [UserBrandController::class, 'assignableUsers']);
            // Transfer — the Admin ROLE is the staff-side brand keeper, so it
            // moves a brand between the companies it holds canEditBrands in,
            // the way the Company Admin moves one between companies they own.
            // Declared before /{id} so 'company-options' isn't read as an id.
            Route::get('brands/company-options',      [UserBrandController::class, 'companyOptions']);
            Route::patch('brands/{id}/assign',        [UserBrandController::class, 'assign']);
            Route::patch('brands/{id}/transfer',      [UserBrandController::class, 'transfer']);
            Route::get('brands/{id}',                 [UserBrandController::class, 'show']);
            Route::post('brands/{id}',                [UserBrandController::class, 'update']);
            Route::patch('brands/{id}/toggle-status', [UserBrandController::class, 'toggleStatus']);
            Route::delete('brands/{id}',              [UserBrandController::class, 'destroy']);
        });

        // Payments — Finance sub-user verify/reject, matching the Admin
        // side's own `module:payments` gate. Previously unreachable by any
        // sub-user; only Company Admin could confirm/reject a payment claim.
        Route::middleware('module:payments')->group(function () {
            Route::patch('payments/{id}/confirm', [\App\Http\Controllers\Api\User\PaymentController::class, 'confirm']);
            Route::patch('payments/{id}/reject',  [\App\Http\Controllers\Api\User\PaymentController::class, 'reject']);
        });

        // HR — staff-side mirror of the Admin HR routes above (routes/api.php
        // ~line 1134), gated per-action inside each controller by the real
        // canView/Create/Edit/...HR permissions (module:xxx here is only the
        // coarse "did the company purchase this" gate, same split every
        // other User module already uses).
        Route::middleware('module:employees')->group(function () {
            Route::get('hr/dashboard', [UserHrDashboardController::class, 'index']);

            Route::get('employees',                                 [UserEmployeeController::class, 'index']);
            Route::post('employees',                                [UserEmployeeController::class, 'store']);
            Route::get('employees/{id}',                            [UserEmployeeController::class, 'show']);
            Route::put('employees/{id}',                            [UserEmployeeController::class, 'update']);
            Route::patch('employees/{id}/deactivate',               [UserEmployeeController::class, 'deactivate']);
            Route::patch('employees/{id}/reactivate',               [UserEmployeeController::class, 'reactivate']);
            Route::delete('employees/{id}',                         [UserEmployeeController::class, 'destroy']);

            Route::get('employees/{id}/documents',                  [UserEmployeeController::class, 'documents']);
            Route::post('employees/{id}/documents',                 [UserEmployeeController::class, 'uploadDocument']);
            Route::delete('employees/{id}/documents/{documentId}',  [UserEmployeeController::class, 'deleteDocument']);

            Route::get('employees/{id}/notes',                      [UserEmployeeController::class, 'notes']);
            Route::post('employees/{id}/notes',                     [UserEmployeeController::class, 'addNote']);

            Route::get('departments',                               [UserDepartmentController::class, 'index']);
            Route::post('departments',                              [UserDepartmentController::class, 'store']);
            Route::put('departments/{id}',                          [UserDepartmentController::class, 'update']);
            Route::delete('departments/{id}',                       [UserDepartmentController::class, 'destroy']);

            Route::get('shifts',                                    [UserShiftController::class, 'index']);
            Route::post('shifts',                                   [UserShiftController::class, 'store']);
            Route::put('shifts/{id}',                                [UserShiftController::class, 'update']);
            Route::delete('shifts/{id}',                             [UserShiftController::class, 'destroy']);
        });

        Route::middleware('module:attendance')->group(function () {
            Route::get('attendance',                [UserAttendanceController::class, 'index']);
            Route::post('attendance/mark',           [UserAttendanceController::class, 'mark']);
            Route::post('attendance/employees/{employeeId}/check-in', [UserAttendanceController::class, 'checkInEmployee']);
            Route::post('attendance/employees/{employeeId}/check-out', [UserAttendanceController::class, 'checkOutEmployee']);
            Route::post('attendance/sync-now',       [UserAttendanceController::class, 'syncNow']);
            Route::get('attendance/summary',         [UserAttendanceController::class, 'summary']);
            Route::get('attendance/live',            [UserAttendanceController::class, 'live']);
            Route::get('attendance/calendar',        [UserAttendanceController::class, 'calendar']);
            Route::get('attendance/policy',          [UserAttendancePolicyController::class, 'show']);
            Route::put('attendance/policy',          [UserAttendancePolicyController::class, 'update']);
            Route::post('attendance/check-in',       [UserAttendanceController::class, 'checkIn']);
            Route::post('attendance/check-out',      [UserAttendanceController::class, 'checkOut']);
            Route::post('attendance/{id}/correct',   [UserAttendanceController::class, 'correct']);
            Route::get('attendance/{id}/history',    [UserAttendanceController::class, 'history']);
        });

        Route::middleware('module:leaves')->group(function () {
            Route::get('leaves',               [UserLeaveController::class, 'index']);
            Route::post('leaves',              [UserLeaveController::class, 'store']);
            Route::patch('leaves/{id}/status', [UserLeaveController::class, 'updateStatus']);
        });

        Route::middleware('module:payroll')->group(function () {
            Route::get('payroll',                [UserPayrollController::class, 'index']);
            Route::get('payroll/deduction-preview', [UserPayrollController::class, 'deductionPreview']);
            Route::put('payroll/{id}',           [UserPayrollController::class, 'update']);
            Route::patch('payroll/{id}/process', [UserPayrollController::class, 'process']);
            Route::get('payroll/{id}/payslip',   [UserPayrollController::class, 'payslip']);
        });

        Route::middleware('module:recruitment')->group(function () {
            Route::get('recruitment',                               [UserRecruitmentController::class, 'index']);
            Route::post('recruitment',                              [UserRecruitmentController::class, 'store']);
            Route::get('recruitment/{id}',                          [UserRecruitmentController::class, 'show']);
            Route::put('recruitment/{id}',                          [UserRecruitmentController::class, 'update']);
            Route::delete('recruitment/{id}',                       [UserRecruitmentController::class, 'destroy']);
            Route::post('recruitment/{id}/applicants',              [UserRecruitmentController::class, 'addApplicant']);
            Route::patch('recruitment/{id}/applicants/{applicantId}/status', [UserRecruitmentController::class, 'updateApplicantStatus']);
        });

        // Diagnostic only — not module-gated, so it can help debug a company
        // whose Project Management module or permissions are misconfigured.
        Route::get('debug/access', [\App\Http\Controllers\Api\User\DebugController::class, 'access']);

        // Project Management — sub-user access, module-gated exactly like the
        // Admin side (scoped to canViewAllCompanyProjects / assigned / team-member / task-assignee).
        Route::middleware('module:projects')->group(function () {
            Route::get('projects/reports/status',           [UserProjectReportController::class, 'statusReport']);
            Route::get('projects/reports/task-status',      [UserProjectReportController::class, 'taskStatusReport']);
            Route::get('projects/reports/overdue',          [UserProjectReportController::class, 'overdueReport']);

            Route::get('projects/company-users',                [UserProjectController::class, 'companyUsers']);
            Route::get('projects/sellers',                      [UserProjectController::class, 'sellers']);
            Route::get('projects',                              [UserProjectController::class, 'index']);
            Route::post('projects',                             [UserProjectController::class, 'store']);
            Route::get('projects/{id}',                         [UserProjectController::class, 'show']);
            Route::put('projects/{id}',                         [UserProjectController::class, 'update']);
            Route::patch('projects/{id}/seller',                [UserProjectController::class, 'assignSeller']);
            Route::patch('projects/{id}/project-manager',       [UserProjectController::class, 'assignProjectManager']);
            Route::put('projects/{id}/team',                    [UserProjectController::class, 'assignTeam']);
            Route::delete('projects/{id}/team/{memberId}',      [UserProjectController::class, 'removeTeamMember']);
            Route::get('projects/{id}/completion-status',       [UserProjectController::class, 'completionStatus']);
            Route::get('projects/{id}/activity',                [UserProjectController::class, 'activity']);
            Route::post('projects/{id}/activate',               [UserProjectController::class, 'activate']);
            Route::post('projects/{id}/complete',               [UserProjectController::class, 'complete']);
            Route::post('projects/{id}/submit-delivery',         [UserProjectController::class, 'submitDelivery']);
            Route::post('projects/{id}/close',                  [UserProjectController::class, 'close']);
            Route::post('projects/{id}/reopen',                 [UserProjectController::class, 'reopen']);
            Route::post('projects/{id}/request-reopen',         [UserProjectController::class, 'requestReopen']);
            Route::post('projects/{id}/invoices',               [UserProjectController::class, 'createInvoice']);
            Route::post('projects/{id}/invoices/link',          [UserProjectController::class, 'linkInvoice']);
            Route::delete('projects/{id}/invoices/{invoiceId}', [UserProjectController::class, 'unlinkInvoice']);

            Route::get('projects/{projectId}/chat',             [UserProjectChatController::class, 'index']);
            Route::post('projects/{projectId}/chat',            [UserProjectChatController::class, 'store']);
            Route::get('projects/{projectId}/chat/{messageId}/attachment', [UserProjectChatController::class, 'downloadAttachment']);
            Route::post('projects/{projectId}/chat/participants', [UserProjectChatController::class, 'addClientParticipant']);

            // Project Chat — one thread per project (see ProjectChatService).
            Route::get('projects/{projectId}/messenger',                              [UserProjectMessengerController::class, 'show']);
            Route::get('projects/{projectId}/messenger/eligible-participants',        [UserProjectMessengerController::class, 'eligibleParticipants']);
            Route::get('projects/{projectId}/messenger/eligible-pms',                 [UserProjectMessengerController::class, 'eligiblePms']);
            Route::post('projects/{projectId}/messenger/invite-pm',                   [UserProjectMessengerController::class, 'invitePm']);
            Route::get('projects/{projectId}/messenger/messages',                     [UserProjectMessengerController::class, 'messages']);
            Route::post('projects/{projectId}/messenger/messages',                    [UserProjectMessengerController::class, 'send']);
            Route::delete('projects/{projectId}/messenger/messages/{messageId}',      [UserProjectMessengerController::class, 'deleteMessage']);
            Route::patch('projects/{projectId}/messenger/messages/{messageId}',       [UserProjectMessengerController::class, 'updateMessage']);
            Route::post('projects/{projectId}/messenger/participants',                [UserProjectMessengerController::class, 'addParticipant']);
            Route::delete('projects/{projectId}/messenger/participants/{userId}',     [UserProjectMessengerController::class, 'removeParticipant']);
            // This project's own Seller (the "Upseller") granting/revoking a
            // Seller they brought into the chat the ability to message the
            // project's client — see setParticipantClientAccess().
            Route::patch('projects/{projectId}/messenger/participants/{userId}/client-access', [UserProjectMessengerController::class, 'setParticipantClientAccess']);
            Route::patch('projects/{projectId}/messenger/mute',                       [UserProjectMessengerController::class, 'toggleMute']);
            Route::get('projects/{projectId}/messenger/messages/{messageId}/attachment', [UserProjectMessengerController::class, 'downloadAttachment']);

            // Client Chat — the project's own Seller answering their client
            // (see ProjectClientChatService). Identity-gated to
            // projects.seller_id inside the controller, deliberately no
            // permission toggle, same as the Sales Chat routes: a Seller must
            // always be able to answer their own client.
            Route::get('projects/{projectId}/client-chat',                        [\App\Http\Controllers\Api\User\ProjectClientChatController::class, 'index']);
            Route::post('projects/{projectId}/client-chat',                       [\App\Http\Controllers\Api\User\ProjectClientChatController::class, 'store']);
            Route::delete('projects/{projectId}/client-chat/{messageId}',         [\App\Http\Controllers\Api\User\ProjectClientChatController::class, 'deleteMessage']);
            Route::post('projects/{projectId}/client-chat/{messageId}/toggle-hide', [\App\Http\Controllers\Api\User\ProjectClientChatController::class, 'toggleHide']);
            Route::get('projects/{projectId}/client-chat/{messageId}/attachment', [\App\Http\Controllers\Api\User\ProjectClientChatController::class, 'downloadAttachment']);
            // Seller pulls this project's PM into the client conversation
            // (full history or from-now-only), and pings Company Admin to
            // come look at it.
            Route::post('projects/{projectId}/client-chat/invite-pm',             [\App\Http\Controllers\Api\User\ProjectClientChatController::class, 'invitePm']);
            Route::post('projects/{projectId}/client-chat/notify-admin',          [\App\Http\Controllers\Api\User\ProjectClientChatController::class, 'notifyAdmin']);

            Route::get('projects/{projectId}/comments',         [UserProjectCommentController::class, 'index']);
            Route::post('projects/{projectId}/comments',        [UserProjectCommentController::class, 'store']);
            Route::patch('projects/{projectId}/comments/{commentId}', [UserProjectCommentController::class, 'update']);
            Route::delete('projects/{projectId}/comments/{commentId}', [UserProjectCommentController::class, 'destroy']);
            Route::post('projects/{projectId}/comments/{commentId}/like', [UserProjectCommentController::class, 'toggleLike']);
            Route::get('projects/{projectId}/mentionable-users', [UserProjectCommentController::class, 'mentionableUsers']);
            Route::get('projects/{projectId}/comments/{commentId}/attachments',    [UserProjectCommentAttachmentController::class, 'index']);
            Route::post('projects/{projectId}/comments/{commentId}/attachments',   [UserProjectCommentAttachmentController::class, 'store']);
            Route::get('projects/{projectId}/comments/{commentId}/attachments/{id}/download', [UserProjectCommentAttachmentController::class, 'download']);

            Route::get('projects/{projectId}/attachments',                [UserProjectAttachmentController::class, 'index']);
            Route::post('projects/{projectId}/attachments',               [UserProjectAttachmentController::class, 'store']);
            Route::get('projects/{projectId}/attachments/{id}/download',  [UserProjectAttachmentController::class, 'download']);
            Route::delete('projects/{projectId}/attachments/{id}',        [UserProjectAttachmentController::class, 'destroy']);

            Route::get('projects/{projectId}/deliverables',     [UserProductionController::class, 'deliverables']);
            Route::post('projects/{projectId}/deliverables',    [UserProductionController::class, 'uploadDeliverable']);
            Route::patch('revisions/{id}/resolve',              [UserProductionController::class, 'resolveRevision']);
            Route::post('deliverables/{id}/request-revision',   [UserProductionController::class, 'requestRevision']);
            Route::patch('deliverables/{id}/approve',           [UserProductionController::class, 'approve']);
            Route::patch('deliverables/{id}/reject',            [UserProductionController::class, 'reject']);
            Route::get('deliverables/{deliverableId}/revisions', [UserProductionController::class, 'revisions']);
        });

        // Compliance — requires the compliance module. Phase 1 surface only
        // — see Api\User\ComplianceController.
        Route::middleware('module:compliance')->group(function () {
            Route::get('compliance/dashboard',                      [UserComplianceController::class, 'dashboard']);
            Route::get('compliance/clients',                        [UserComplianceController::class, 'clientsIndex']);
            Route::get('compliance/clients/{clientId}',              [UserComplianceController::class, 'clientShow']);
            Route::get('compliance/cases',                          [UserComplianceController::class, 'index']);
            Route::get('compliance/cases/{id}',                     [UserComplianceController::class, 'show']);
            Route::get('compliance/projects/{projectId}/case',      [UserComplianceController::class, 'showByProject']);
            Route::get('compliance/projects/{projectId}/overview',  [UserComplianceController::class, 'projectOverview']);
            Route::get('compliance/projects/{projectId}/documents-zip', [UserComplianceController::class, 'projectDocumentsZip']);
            Route::get('compliance/projects-zip',                   [UserComplianceController::class, 'allProjectsZip']);
            Route::get('compliance/projects/{projectId}/client-chat',     [UserComplianceController::class, 'projectClientChat']);
            Route::get('compliance/projects/{projectId}/client-chat/export', [UserComplianceController::class, 'projectChatExport']);
            Route::get('compliance/projects/{projectId}/client-attachments', [UserComplianceController::class, 'projectClientAttachments']);
            Route::get('compliance/client-attachments/{messageId}/download', [UserComplianceController::class, 'projectClientAttachmentDownload']);
            // Mirror of the Admin-guard pair above — same service, same
            // scoping, so the two guards cannot disagree about which receipts
            // belong to a project.
            Route::get('compliance/projects/{projectId}/client-receipts', [UserComplianceController::class, 'projectClientReceipts']);
            Route::get('compliance/client-receipts/{receiptId}/image',     [UserComplianceController::class, 'projectClientReceiptImage']);
            Route::get('compliance/projects/{projectId}/general-chat',    [UserComplianceController::class, 'projectGeneralChat']);
            Route::get('compliance/projects/{projectId}/attachments',     [UserComplianceController::class, 'projectAttachments']);
            Route::get('compliance/projects/{projectId}/task-attachments', [UserComplianceController::class, 'projectTaskAttachments']);
            Route::get('compliance/attachments/{attachmentId}/download', [UserComplianceController::class, 'projectAttachmentDownload']);
            Route::get('compliance/projects/{projectId}/tasks',          [UserComplianceController::class, 'projectTasks']);
            Route::get('compliance/tasks/{taskId}/detail',               [UserComplianceController::class, 'projectTaskDetail']);
            Route::get('compliance/task-attachments/{attachmentId}/download', [UserComplianceController::class, 'taskAttachmentDownload']);
            Route::get('compliance/projects/{projectId}/deliverables',   [UserComplianceController::class, 'projectDeliverables']);
            Route::get('compliance/deliveries/{deliveryId}/download',    [UserComplianceController::class, 'deliverySubmissionDownload']);
            Route::get('compliance/projects/{projectId}/billing',        [UserComplianceController::class, 'projectBilling']);
            Route::get('compliance/invoices/{invoiceId}/download',       [UserComplianceController::class, 'invoiceDownload']);
            Route::get('compliance/projects/{projectId}/team',           [UserComplianceController::class, 'projectTeam']);
            Route::get('compliance/projects/{projectId}/comments',       [UserComplianceController::class, 'projectComments']);
            Route::get('compliance/projects/{projectId}/timesheets',     [UserComplianceController::class, 'projectTimesheets']);
            Route::get('compliance/projects/{projectId}/history',        [UserComplianceController::class, 'projectHistory']);
            Route::get('compliance/projects/{projectId}/lead',           [UserComplianceController::class, 'projectLead']);
            Route::patch('compliance/cases/{id}/status',            [UserComplianceController::class, 'caseStatusUpdate']);
            Route::post('compliance/cases/{id}/requirements',       [UserComplianceController::class, 'requirementsStore']);
            Route::put('compliance/requirements/{id}',              [UserComplianceController::class, 'requirementsUpdate']);
            Route::delete('compliance/requirements/{id}',           [UserComplianceController::class, 'requirementsDestroy']);
            Route::post('compliance/cases/{id}/documents',          [UserComplianceController::class, 'documentsStore']);
            Route::post('compliance/documents/{id}/versions',       [UserComplianceController::class, 'documentsAddVersion']);
            Route::get('compliance/documents/{id}/download',        [UserComplianceController::class, 'documentsDownload']);
            Route::patch('compliance/documents/{id}/review',        [UserComplianceController::class, 'documentsReview']);
            Route::get('compliance/cases/{id}/checklist',           [UserComplianceController::class, 'checklistIndex']);
            Route::post('compliance/cases/{id}/checklist/generate', [UserComplianceController::class, 'checklistGenerate']);
            Route::post('compliance/cases/{id}/checklist',          [UserComplianceController::class, 'checklistStore']);
            Route::patch('compliance/checklist-items/{id}/toggle',  [UserComplianceController::class, 'checklistToggle']);
            Route::get('compliance/cases/{id}/comments',            [UserComplianceController::class, 'commentsIndex']);
            Route::post('compliance/cases/{id}/comments',           [UserComplianceController::class, 'commentsStore']);
            Route::delete('compliance/comments/{id}',               [UserComplianceController::class, 'commentsDestroy']);
            Route::get('compliance/cases/{id}/activity',            [UserComplianceController::class, 'activityIndex']);
            Route::get('compliance/templates',                      [UserComplianceController::class, 'templatesIndex']);
            // No POST/PUT/DELETE/default templates or template-requirements
            // routes on this guard — template management is Company
            // Admin-only, see the Admin route block above.
            Route::patch('compliance/cases/{id}/officer',           [UserComplianceController::class, 'assignOfficer']);
        });

        Route::middleware('module:tasks')->group(function () {
            Route::get('tasks',                                 [UserTaskController::class, 'indexAll']);
            // See the matching Admin route's comment — resolves a bare task
            // id to its project_id for the /task/{id} share-link redirector.
            Route::get('tasks/{id}/lookup',                     [UserTaskController::class, 'lookup']);
            Route::get('projects/{projectId}/tasks',            [UserTaskController::class, 'index']);
            Route::post('projects/{projectId}/tasks',           [UserTaskController::class, 'store']);
            Route::put('projects/{projectId}/tasks/{id}',       [UserTaskController::class, 'update']);
            Route::get('projects/{projectId}/tasks/{id}/activity', [UserTaskController::class, 'activity']);
            Route::get('my-tasks',                              [UserTaskController::class, 'myTasks']);
            // No permission gate — every task-status actor needs this for
            // the optional Production handoff picker. See
            // TaskController::productionUsers().
            Route::get('tasks/production-users',                [UserTaskController::class, 'productionUsers']);
            // Also ungated — the "Assigned To" picker's Seller options, which
            // can't come from the project's team_members (a Seller never has
            // a row there). See TaskController::assignableSellers().
            Route::get('tasks/assignable-sellers',             [UserTaskController::class, 'assignableSellers']);

            Route::get('projects/{projectId}/tasks/{taskId}/attachments',                [UserTaskAttachmentController::class, 'index']);
            Route::post('projects/{projectId}/tasks/{taskId}/attachments',               [UserTaskAttachmentController::class, 'store']);
            Route::get('projects/{projectId}/tasks/{taskId}/attachments/{id}/download',  [UserTaskAttachmentController::class, 'download']);
            Route::delete('projects/{projectId}/tasks/{taskId}/attachments/{id}',        [UserTaskAttachmentController::class, 'destroy']);
        });

        Route::middleware('module:timesheets')->group(function () {
            Route::get('timesheets',                            [UserTimesheetController::class, 'index']);
            Route::post('timesheets',                           [UserTimesheetController::class, 'store']);
            Route::patch('timesheets/{id}/approve',             [UserTimesheetController::class, 'approve']);
        });

    });
});

/*
|--------------------------------------------------------------------------
| Super Admin Routes  (guard: super_admin → SuperAdmin model)
|--------------------------------------------------------------------------
*/
Route::prefix('super-admin')->group(function () {
    Route::post('login', [SuperAdminAuthController::class, 'login']);

    Route::middleware('auth:super_admin')->group(function () {
        Route::post('logout', [SuperAdminAuthController::class, 'logout']);
        Route::get('me',      [SuperAdminAuthController::class, 'me']);

        // Account Settings — the Super Admin's own password, so it can be
        // rotated from the console instead of by editing .env or the database.
        Route::get('profile',                  [SuperAdminProfileController::class, 'show']);
        Route::post('profile/change-password', [SuperAdminProfileController::class, 'changePassword']);

        // Platform-level bell. Scoped to this Super Admin AND to the
        // platform event types in SuperAdminNotificationService::TYPES —
        // tenant-internal traffic never reaches it (see the controller).
        Route::prefix('notifications')->group(function () {
            Route::get('/',              [SuperAdminNotificationController::class, 'index']);
            Route::get('unread-count',   [SuperAdminNotificationController::class, 'unreadCount']);
            Route::patch('read-all',     [SuperAdminNotificationController::class, 'markAllRead']);
            Route::patch('{id}/read',    [SuperAdminNotificationController::class, 'markRead']);
            Route::delete('/',           [SuperAdminNotificationController::class, 'clearAll']);
            Route::delete('{id}',        [SuperAdminNotificationController::class, 'clear']);
        });

        Route::get('dashboard', [DashboardController::class, 'index']);

        Route::prefix('modules')->group(function () {
            Route::get('/',                  [ModuleController::class, 'index']);
            Route::post('/',                 [ModuleController::class, 'store']);
            Route::put('/{module}',          [ModuleController::class, 'update']);
            Route::patch('/{module}/toggle',  [ModuleController::class, 'toggle']);
            Route::delete('/{module}',       [ModuleController::class, 'destroy']);
        });

        Route::prefix('packages')->group(function () {
            Route::get('/',                   [PackageController::class, 'index']);
            Route::post('/',                  [PackageController::class, 'store']);
            Route::get('/{package}',          [PackageController::class, 'show']);
            Route::put('/{package}',          [PackageController::class, 'update']);
            Route::delete('/{package}',       [PackageController::class, 'destroy']);
            Route::patch('/{package}/toggle', [PackageController::class, 'toggle']);
        });

        Route::prefix('admins')->group(function () {
            Route::get('/',            [CompanyAdminController::class, 'index']);
            Route::post('/',           [CompanyAdminController::class, 'store']);
            Route::get('/{admin}',     [CompanyAdminController::class, 'show']);
            Route::put('/{admin}',     [CompanyAdminController::class, 'update']);
            Route::patch('/{admin}/toggle', [CompanyAdminController::class, 'toggleStatus']);
            // Manual override of a blocked subscription state — see
            // CompanyAdmin::isBlockedFromAccess()/toggleAccessOverride().
            // Distinct from /toggle above (company_admins.is_active, the
            // platform-wide kill switch): this one only ever lifts a
            // trial/subscription block, never the is_active one.
            Route::patch('/{admin}/access-override', [CompanyAdminController::class, 'toggleAccessOverride']);
        });

        Route::prefix('companies')->group(function () {
            Route::get('/',                         [CompanyController::class, 'index']);
            Route::patch('/{company}/toggle',       [CompanyController::class, 'toggleStatus']);
            Route::get('/{company}/modules',        [CompanyController::class, 'modules']);
            Route::put('/{company}/modules',        [CompanyController::class, 'syncModules']);
            // Permanent delete — Super Admin only (this whole prefix already
            // sits under auth:super_admin below). See CompanyController::
            // destroy()/buildDeletionSummary() for the eligibility/safeguard
            // rules; no Company Admin or staff route ever reaches this.
            Route::get('/{company}/deletion-summary', [CompanyController::class, 'deletionSummary']);
            Route::delete('/{company}',               [CompanyController::class, 'destroy']);
        });

        Route::prefix('payment-gateways')->group(function () {
            Route::get('/',                          [PaymentGatewayController::class, 'index']);
            Route::patch('/{gateway}/toggle',        [PaymentGatewayController::class, 'toggle']);
            Route::put('/{gateway}/config',          [PaymentGatewayController::class, 'updateConfig']);
            Route::post('/{gateway}/test',           [PaymentGatewayController::class, 'testConnection']);
        });

        Route::prefix('subscription-policy')->group(function () {
            Route::get('/',  [SubscriptionPolicyController::class, 'show']);
            Route::put('/',  [SubscriptionPolicyController::class, 'update']);
        });

        Route::prefix('billing-terms')->group(function () {
            Route::get('/',                       [BillingTermController::class, 'index']);
            Route::post('/',                      [BillingTermController::class, 'store']);
            Route::put('/{billingTerm}',          [BillingTermController::class, 'update']);
            Route::delete('/{billingTerm}',       [BillingTermController::class, 'destroy']);
            Route::patch('/{billingTerm}/toggle', [BillingTermController::class, 'toggle']);
        });

        // Whether the server cron is actually running the scheduler, and
        // whether each command succeeded — see App\Support\ScheduleLogger.
        Route::get('scheduled-task-logs', [\App\Http\Controllers\Api\SuperAdmin\ScheduledTaskLogController::class, 'index']);
    });
});

// ─── Client Portal Routes ────────────────────────────────────────────────────

Route::prefix('client')->group(function () {
    Route::post('login',  [\App\Http\Controllers\Api\Client\AuthController::class, 'login']);

    // Portal password reset. Unlike the staff /forgot-password, this one reports
    // an unregistered address instead of answering generically — see
    // Api\Client\ForgotPasswordController, which explains the trade-off that
    // buys. The throttle is part of that decision, not decoration: it is what
    // stops the honest answer from being usable for bulk probing of which
    // addresses are portal clients. Do not drop it.
    Route::post('forgot-password', [\App\Http\Controllers\Api\Client\ForgotPasswordController::class, 'sendResetLink'])
        ->middleware('throttle:6,1');

    // A Client portal login IS a User row (role_type 'client', see
    // Api\Client\AuthController::login()) with no multi-company switching, so
    // EnsureCompanyNotSuspended's existing User branch — reading
    // $user->company_id directly — already covers it correctly with no new
    // code; it just wasn't wired into this route group until now. Without
    // this, a suspended company's clients could still view invoices, pay
    // with a saved card, and use portal chat indefinitely.
    Route::middleware(['auth:sanctum', 'client.role', 'company.not_suspended', 'company.admin.active'])->group(function () {
        Route::post('logout',      [\App\Http\Controllers\Api\Client\AuthController::class, 'logout'])
            ->withoutMiddleware([\App\Http\Middleware\EnsureCompanyNotSuspended::class, \App\Http\Middleware\EnsureCompanyAdminActive::class]);
        Route::get('me',           [\App\Http\Controllers\Api\Client\AuthController::class, 'me'])
            ->withoutMiddleware([\App\Http\Middleware\EnsureCompanyNotSuspended::class, \App\Http\Middleware\EnsureCompanyAdminActive::class]);
        Route::get('permissions',  [\App\Http\Controllers\Api\Client\AuthController::class, 'permissions']);

        // The client's own details. Contact Email is editable here and carries
        // the Login Email with it — see Api\Client\ProfileController, which is
        // also where the current-password gate on that change lives.
        Route::get('profile',  [\App\Http\Controllers\Api\Client\ProfileController::class, 'show']);
        Route::put('profile',  [\App\Http\Controllers\Api\Client\ProfileController::class, 'update']);

        // Payment Assistant — the rule-based invoice payment chat. It only ever
        // finds and validates an invoice; the charge itself is handed off to the
        // existing /client/invoices/{id}/gateways(/initiate) endpoints below, so
        // there is exactly one payment path in this app. See
        // Api\Client\PaymentAssistantController.
        //
        // Throttled because these drive a flow that ends in money: it caps how
        // fast invoice numbers can be probed, even though each lookup is already
        // scoped to this login's own clients and chosen company.
        // Saved cards. Adding one opens the gateway's own hosted page — no card
        // detail passes through this app — and the stored token is never
        // serialised back out (ClientPaymentMethod::$hidden).
        Route::prefix('payment-methods')->middleware('throttle:30,1')->group(function () {
            Route::get('/',              [\App\Http\Controllers\Api\Client\PaymentMethodController::class, 'index']);
            Route::post('setup',         [\App\Http\Controllers\Api\Client\PaymentMethodController::class, 'setup']);
            Route::post('complete',      [\App\Http\Controllers\Api\Client\PaymentMethodController::class, 'complete']);
            // Adding a card WITHOUT leaving the portal. Same end state as
            // setup/complete above — a card vaulted against this client's
            // gateway customer — but the fields are Stripe Elements' own
            // cross-origin iframe rather than Stripe's hosted page, so the
            // client never navigates away. The card still never touches this
            // app: /inline-setup hands back only a publishable key and a
            // single-use client secret, and /inline-complete asks Stripe
            // whether the setup really succeeded before saving anything.
            Route::post('inline-setup',    [\App\Http\Controllers\Api\Client\PaymentMethodController::class, 'inlineSetup']);
            Route::post('inline-complete', [\App\Http\Controllers\Api\Client\PaymentMethodController::class, 'inlineComplete']);
            Route::post('{id}/default',  [\App\Http\Controllers\Api\Client\PaymentMethodController::class, 'setDefault']);
            Route::delete('{id}',        [\App\Http\Controllers\Api\Client\PaymentMethodController::class, 'destroy']);
        });

        Route::prefix('payment-assistant')->middleware('throttle:60,1')->group(function () {
            Route::get('/',         [\App\Http\Controllers\Api\Client\PaymentAssistantController::class, 'show']);
            Route::get('unpaid',    [\App\Http\Controllers\Api\Client\PaymentAssistantController::class, 'unpaid']);
            Route::post('company',  [\App\Http\Controllers\Api\Client\PaymentAssistantController::class, 'selectCompany']);
            Route::post('message',  [\App\Http\Controllers\Api\Client\PaymentAssistantController::class, 'message']);
            Route::post('invoice',  [\App\Http\Controllers\Api\Client\PaymentAssistantController::class, 'selectInvoice']);
            Route::post('confirm',  [\App\Http\Controllers\Api\Client\PaymentAssistantController::class, 'confirm']);
            // The one request in the assistant that moves money. Throttled
            // harder than the rest of the group: a retry storm here is a
            // double-charge risk, not just noise (the service also takes a row
            // lock on the invoice, which is the real guard).
            Route::post('pay-saved', [\App\Http\Controllers\Api\Client\PaymentAssistantController::class, 'payWithSavedMethod'])
                ->middleware('throttle:10,1');
            Route::post('reset',    [\App\Http\Controllers\Api\Client\PaymentAssistantController::class, 'reset']);
            // "Clear History" — a conversation reset, not a delete. It rotates
            // the session's conversation_id so the client's visible thread
            // starts empty while the permanent activity trail in
            // system_audit_logs (action payment_assistant.*) stays intact and
            // still attributable to the conversation it came from. Touches one
            // row; reads nothing else.
            Route::post('clear',    [\App\Http\Controllers\Api\Client\PaymentAssistantController::class, 'clearHistory']);
            Route::post('change-company', [\App\Http\Controllers\Api\Client\PaymentAssistantController::class, 'changeCompany']);
        });

        // The floating portal assistant — the widget mounted on every Client
        // Portal page. A second front door onto what already exists, not a
        // second implementation: it shares the payment_assistant_sessions row
        // above (so widget and page can never disagree about which invoice is
        // in view), charges through ClientPaymentMethodService exactly as the
        // page does, adds cards through the /client/payment-methods/setup
        // endpoints above, and reaches a seller through the existing project
        // chat or NotificationService. See Api\Client\AssistantController.
        //
        // Throttled like the page for the same reason — these drive a flow that
        // ends in money — with /action kept tighter, since that is the group
        // that includes confirm_payment. The row lock inside
        // ClientPaymentMethodService::charge() is still the real double-charge
        // guard; this only caps how fast it can be hammered.
        Route::prefix('assistant')->middleware('throttle:60,1')->group(function () {
            Route::get('/',         [\App\Http\Controllers\Api\Client\AssistantController::class, 'open']);
            Route::post('message',  [\App\Http\Controllers\Api\Client\AssistantController::class, 'message']);
            Route::post('action',   [\App\Http\Controllers\Api\Client\AssistantController::class, 'action'])
                ->middleware('throttle:30,1');
            Route::post('clear',    [\App\Http\Controllers\Api\Client\AssistantController::class, 'clear']);
        });

        // The CLIENT end of the per-client Direct Chat (thread_type='client').
        //
        // The staff and Admin ends already exist and are untouched
        // (/user/clients/{id}/direct-chat/*, /admin/clients/{id}/direct-chat/*).
        // Only the client's own end was missing — Api\Client\ChatController was
        // removed on 2026-09-11 — so a seller could reply to a client with
        // nowhere to read it. AI Assistance opens the conversation; these serve
        // it from then on, and the assistant is not in it.
        //
        // Not module-gated: this is the client's conversation with the person
        // who sold to them, not a portal section Company Admin toggles. Every
        // request re-derives ownership, company and membership from the session
        // (see Api\Client\SellerChatController) — a thread id is a filter, never
        // a grant.
        Route::prefix('seller-chat')->middleware('throttle:60,1')->group(function () {
            Route::get('/',                    [\App\Http\Controllers\Api\Client\SellerChatController::class, 'index']);
            Route::get('{threadId}',           [\App\Http\Controllers\Api\Client\SellerChatController::class, 'show']);
            Route::post('{threadId}/messages', [\App\Http\Controllers\Api\Client\SellerChatController::class, 'store'])
                ->middleware('throttle:20,1');
        });

        Route::get('dashboard', [\App\Http\Controllers\Api\Client\DashboardController::class, 'index']);
        Route::post('dashboard/request-service', [\App\Http\Controllers\Api\Client\DashboardController::class, 'requestService']);

        // Portal notifications — the client-side counterpart to the staff bell.
        // Not module-gated: a notification can come from any portal section the
        // client has, and each row's own writer already checked that gate.
        Route::get('notifications',                  [\App\Http\Controllers\Api\Client\NotificationController::class, 'index']);
        Route::patch('notifications/read-all',       [\App\Http\Controllers\Api\Client\NotificationController::class, 'markAllRead']);
        Route::patch('notifications/{id}/read',      [\App\Http\Controllers\Api\Client\NotificationController::class, 'markRead']);
        Route::delete('notifications',               [\App\Http\Controllers\Api\Client\NotificationController::class, 'clearAll']);
        Route::delete('notifications/{id}',          [\App\Http\Controllers\Api\Client\NotificationController::class, 'clear']);

        Route::get('projects',                               [\App\Http\Controllers\Api\Client\ProjectController::class, 'index']);
        Route::get('projects/{id}',                          [\App\Http\Controllers\Api\Client\ProjectController::class, 'show']);
        Route::get('projects/{id}/delivery/download',         [\App\Http\Controllers\Api\Client\ProjectController::class, 'downloadDelivery']);
        Route::get('delivery-submissions/{deliveryId}/download', [\App\Http\Controllers\Api\Client\ProjectController::class, 'downloadDeliverySubmission']);
        Route::post('deliverables/{id}/approve',             [\App\Http\Controllers\Api\Client\ProjectController::class, 'approveDeliverable']);
        Route::post('deliverables/{id}/revision',            [\App\Http\Controllers\Api\Client\ProjectController::class, 'requestRevision']);

        // Client-facing Project Comments — always visibility='client', never
        // sees 'internal'. (The per-project live chat added below is a
        // separate, deliberately narrow channel: Client <-> Seller <-> Company
        // Admin only, never the internal production team.)
        Route::get('projects/{id}/comments',                 [\App\Http\Controllers\Api\Client\ProjectCommentController::class, 'index']);
        Route::post('projects/{id}/comments',                [\App\Http\Controllers\Api\Client\ProjectCommentController::class, 'store']);

        // Per-PROJECT live chat — Client <-> that project's Seller <-> Company
        // Admin, one conversation per project (see
        // Api\Client\ProjectChatController and ProjectClientChatService). The
        // client only ever reaches the chats of projects that are their own;
        // the internal team messenger stays invisible to them.
        Route::get('projects/{id}/chat',                     [\App\Http\Controllers\Api\Client\ProjectChatController::class, 'index']);
        Route::post('projects/{id}/chat',                    [\App\Http\Controllers\Api\Client\ProjectChatController::class, 'store']);
        Route::delete('projects/{id}/chat/{messageId}',      [\App\Http\Controllers\Api\Client\ProjectChatController::class, 'deleteMessage']);
        Route::get('projects/{id}/chat/{messageId}/attachment', [\App\Http\Controllers\Api\Client\ProjectChatController::class, 'downloadAttachment']);

        Route::get('invoices',                  [\App\Http\Controllers\Api\Client\InvoiceController::class, 'index']);
        Route::get('invoices/{id}',             [\App\Http\Controllers\Api\Client\InvoiceController::class, 'show']);
        Route::get('invoices/{id}/pdf',         [\App\Http\Controllers\Api\Client\InvoiceController::class, 'downloadPdf']);
        Route::get('invoices/{id}/gateways',    [\App\Http\Controllers\Api\Client\InvoiceController::class, 'getGateways']);
        Route::post('invoices/{id}/pay',        [\App\Http\Controllers\Api\Client\InvoiceController::class, 'payRequest']);
        Route::post('invoices/{id}/gateways/{gateway}/initiate', [\App\Http\Controllers\Api\Client\InvoiceController::class, 'initiateGatewayCheckout']);
        Route::get('invoices/{id}/gateways/{gateway}/return',    [\App\Http\Controllers\Api\Client\InvoiceController::class, 'returnFromGateway']);

        // Inline gateway payment — same rationale as the public link's inline
        // routes above; charged and finalized synchronously, no webhook.
        Route::get('invoices/{id}/gateways/{gateway}/init',       [\App\Http\Controllers\Api\Client\InvoiceController::class, 'initGateway']);
        Route::post('invoices/{id}/gateways/paypal/create-order', [\App\Http\Controllers\Api\Client\InvoiceController::class, 'createPaypalOrder']);
        Route::post('invoices/{id}/gateways/{gateway}/charge',    [\App\Http\Controllers\Api\Client\InvoiceController::class, 'chargeGateway']);

        
        Route::get('payments', [\App\Http\Controllers\Api\Client\PaymentController::class, 'index']);

        Route::get('documents',                  [\App\Http\Controllers\Api\Client\DocumentController::class, 'index']);
        Route::get('documents/{id}/download',    [\App\Http\Controllers\Api\Client\DocumentController::class, 'download']);
        Route::get('attachments/{id}/download',  [\App\Http\Controllers\Api\Client\AttachmentController::class, 'download']);

        // Sales Chat is deliberately absent from the Client Portal (2026-09-11
        // request). A sales conversation belongs to the LEAD stage, where it
        // now runs both in the CRM's Lead → Sales Chat tab and on the Lead's
        // own no-login link (Api\PublicLeadChatController). By the time
        // someone is a Client they have paid, which means a Project exists,
        // and the per-project chat below is the conversation that matters —
        // so an account-level chat in the portal was a second, near-empty
        // inbox competing with it. Api\Client\ChatController and
        // frontend/app/client/chat were removed rather than just hidden.

        Route::get('support',           [\App\Http\Controllers\Api\Client\SupportController::class, 'index']);
        Route::post('support',          [\App\Http\Controllers\Api\Client\SupportController::class, 'store']);
        Route::get('support/{id}',      [\App\Http\Controllers\Api\Client\SupportController::class, 'show']);
        Route::get('support/{id}/attachment', [\App\Http\Controllers\Api\Client\SupportController::class, 'downloadAttachment']);
        Route::get('support/{id}/replies/{replyId}/attachment', [\App\Http\Controllers\Api\Client\SupportController::class, 'downloadReplyAttachment']);
        Route::post('support/{id}/reply',[\App\Http\Controllers\Api\Client\SupportController::class, 'reply']);

        Route::get('reports/projects', [\App\Http\Controllers\Api\Client\ReportController::class, 'projects']);
        Route::get('reports/invoices', [\App\Http\Controllers\Api\Client\ReportController::class, 'invoices']);

        // "Grow Your Business With Us" — what this company offers, and what
        // this client should look at next based on what they already have.
        // Gated on the 'services' portal module inside the controller, like
        // every other section here.
        Route::get('services',          [\App\Http\Controllers\Api\Client\ServiceController::class, 'index']);
        Route::post('services/request', [\App\Http\Controllers\Api\Client\ServiceController::class, 'storeRequest']);
    });
});
