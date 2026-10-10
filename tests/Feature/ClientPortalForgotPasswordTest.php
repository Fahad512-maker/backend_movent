<?php

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Password reset for a Client Portal login.
 *
 * No separate client flow was built, and this file is why: a portal login is an
 * ordinary User row (role_type='client') and Auth\ForgotPasswordController
 * never filtered by role, so POST /forgot-password and /user/reset-password
 * already worked for clients. The only thing missing was a way in from
 * /client/login and a reset page that sent them back there instead of the staff
 * login — hence the `portal=client` hint these tests pin.
 *
 * These tests exist to keep that true. If someone later adds a role filter to
 * that controller, the portal's "Forgot password?" link would silently become a
 * dead end, and these would catch it.
 */
class ClientPortalForgotPasswordTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $packageId = DB::table('packages')->insertGetId([
            'name' => 'Test Package', 'tier' => 'basic', 'price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $adminId = DB::table('company_admins')->insertGetId([
            'package_id' => $packageId,
            'name' => 'Reset Admin', 'email' => 'resetadmin+' . uniqid() . '@example.test',
            'password' => Hash::make('password'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->companyId = DB::table('companies')->insertGetId([
            'admin_id' => $adminId, 'name' => 'Reset Co',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeUser(string $roleType): User
    {
        return User::create([
            'company_id' => $this->companyId,
            'name' => 'Person ' . uniqid(),
            'email' => $roleType . '+' . uniqid() . '@example.test',
            'password' => Hash::make('original-password'),
            'role_type' => $roleType,
            'is_active' => true,
        ]);
    }

    // ── The status codes the portal's axios guard depends on ────────────────
    //
    // frontend/lib/clientAxios.ts treats a 401 as an expired session and sends
    // the client back to sign in — which, on the LOGIN endpoint itself, wiped
    // the "Invalid credentials" error and the typed email the instant the form
    // rendered them, and looked like the page reloading itself.
    //
    // The guard now exempts the auth endpoints, which is only correct as long
    // as the codes below hold. These pin them: a wrong login is a 401 the
    // frontend must handle in place, and an unregistered reset address is a 422
    // that never reaches the 401 branch at all.

    public function test_a_wrong_portal_login_is_a_plain_401_with_a_readable_message(): void
    {
        $client = $this->makeUser('client');
        DB::table('clients')->insert([
            'company_id' => $this->companyId, 'user_id' => $client->id,
            'name' => 'Portal Client', 'email' => $client->email,
            'portal_access' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/client/login', [
            'email'    => $client->email,
            'password' => 'not-the-password',
        ])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Invalid credentials');
    }

    public function test_an_unregistered_portal_login_is_also_a_plain_401(): void
    {
        $this->postJson('/api/client/login', [
            'email'    => 'nobody+' . uniqid() . '@example.test',
            'password' => 'whatever',
        ])->assertStatus(401);
    }

    public function test_a_client_portal_login_receives_a_reset_link(): void
    {
        $client = $this->makeUser('client');

        $this->postJson('/api/forgot-password', ['email' => $client->email])->assertOk();

        Mail::assertSent(PasswordResetMail::class, fn ($mail) => $mail->hasTo($client->email));

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $client->email]);
    }

    /**
     * A client's link must land on the PORTAL's reset page. The staff page is
     * wrapped in the marketing navbar and the blue brand, and every link on it
     * leads into an app a portal client has no account on.
     */
    public function test_a_client_reset_link_points_at_the_portal_reset_page(): void
    {
        $client = $this->makeUser('client');

        $this->postJson('/api/forgot-password', ['email' => $client->email])->assertOk();

        Mail::assertSent(PasswordResetMail::class, function ($mail) {
            return str_contains($mail->resetUrl ?? '', '/client/reset-password');
        });
    }

    /** A staff link must stay on the staff page, not be sent into the portal. */
    public function test_a_staff_reset_link_points_at_the_staff_reset_page(): void
    {
        $staff = $this->makeUser('seller');

        $this->postJson('/api/forgot-password', ['email' => $staff->email])->assertOk();

        Mail::assertSent(PasswordResetMail::class, function ($mail) {
            return str_contains($mail->resetUrl ?? '', 'reset-password')
                && !str_contains($mail->resetUrl ?? '', '/client/reset-password');
        });
    }

    /** End to end: reset, then sign in to the portal with the new password. */
    public function test_a_client_can_reset_and_then_sign_in_with_the_new_password(): void
    {
        $client = $this->makeUser('client');

        DB::table('clients')->insert([
            'company_id' => $this->companyId, 'user_id' => $client->id,
            'name' => 'Reset Client', 'email' => $client->email, 'portal_access' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $token = null;
        $this->postJson('/api/forgot-password', ['email' => $client->email])->assertOk();
        Mail::assertSent(PasswordResetMail::class, function ($mail) use (&$token) {
            parse_str(parse_url($mail->resetUrl, PHP_URL_QUERY) ?? '', $query);
            $token = $query['token'] ?? null;

            return true;
        });

        $this->assertNotNull($token, 'No reset token was present in the emailed link.');

        $this->postJson('/api/user/reset-password', [
            'email' => $client->email,
            'token' => $token,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        $this->postJson('/api/client/login', [
            'email' => $client->email,
            'password' => 'brand-new-password',
        ])->assertOk();
    }

    /**
     * The STAFF endpoint keeps its generic, non-enumerating answer. Nothing
     * about the portal change below is allowed to leak into it.
     */
    public function test_the_staff_endpoint_still_answers_generically_for_an_unknown_email(): void
    {
        $this->postJson('/api/forgot-password', ['email' => 'nobody+' . uniqid() . '@example.test'])
            ->assertOk();

        Mail::assertNothingSent();
    }

    // ── The portal endpoint: POST /api/client/forgot-password ────────────────
    //
    // This one deliberately DOES report an unregistered address, so the page can
    // show the reason under the email field instead of promising a mail that
    // will never arrive. See Api\Client\ForgotPasswordController for the
    // enumeration trade-off that buys, and the route's throttle.

    public function test_a_registered_portal_client_gets_a_link_from_the_portal_endpoint(): void
    {
        $client = $this->makePortalClient();

        $this->postJson('/api/client/forgot-password', ['email' => $client->email])->assertOk();

        Mail::assertSent(PasswordResetMail::class, fn ($mail) => $mail->hasTo($client->email));
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $client->email]);
    }

    /** The error must arrive under `errors.email` so the field can render it. */
    public function test_an_unregistered_email_is_refused_with_a_field_error_and_sends_nothing(): void
    {
        $this->postJson('/api/client/forgot-password', ['email' => 'nobody+' . uniqid() . '@example.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        Mail::assertNothingSent();
    }

    /** A staff address is not a portal client, however real the account is. */
    public function test_a_staff_email_is_not_treated_as_a_portal_client(): void
    {
        $staff = $this->makeUser('seller');

        $this->postJson('/api/client/forgot-password', ['email' => $staff->email])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        Mail::assertNothingSent();
    }

    /**
     * Portal access switched off means they cannot sign in at all, so reporting
     * them as registered would hand them a link to a login that refuses them.
     */
    public function test_a_client_with_portal_access_disabled_is_refused(): void
    {
        $client = $this->makePortalClient(portalAccess: false);

        $this->postJson('/api/client/forgot-password', ['email' => $client->email])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        Mail::assertNothingSent();
    }

    /** Rate limited — the honest answer must not be usable for bulk probing. */
    public function test_the_portal_endpoint_is_rate_limited(): void
    {
        $statuses = [];
        for ($i = 0; $i < 9; $i++) {
            $statuses[] = $this->postJson('/api/client/forgot-password', [
                'email' => 'probe' . $i . '+' . uniqid() . '@example.test',
            ])->getStatusCode();
        }

        $this->assertContains(429, $statuses, 'The endpoint accepted unlimited attempts.');
    }

    /** A portal-enabled client login, as Api\Client\AuthController::login() requires one. */
    private function makePortalClient(bool $portalAccess = true): User
    {
        $user = $this->makeUser('client');

        DB::table('clients')->insert([
            'company_id' => $this->companyId, 'user_id' => $user->id,
            'name' => 'Portal Client', 'email' => $user->email,
            'portal_access' => $portalAccess,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }
}
