<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presence, honestly: when this user was last actually here.
 *
 * The users table has carried `is_online` (bool) and `socket_id` since the
 * very first 2024 migration, but nothing in the app has ever written either —
 * they are leftovers from a websocket layer that was never built, so
 * `is_online` reads false for everybody, forever. Reusing that boolean would
 * also be the wrong shape: a flag can only be cleared by an explicit "I left"
 * that a closed laptop, a crashed tab or a dropped connection never sends, so
 * it strands people "online" indefinitely.
 *
 * A timestamp expires on its own. App\Http\Middleware\TouchLastSeen stamps it
 * on authenticated requests (throttled), and User::isOnline() treats anything
 * inside User::ONLINE_WINDOW_MINUTES as present. Nothing is inferred and
 * nothing is faked: "Online" means this account made a request moments ago.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('last_login_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_seen_at']);
            $table->dropColumn('last_seen_at');
        });
    }
};
