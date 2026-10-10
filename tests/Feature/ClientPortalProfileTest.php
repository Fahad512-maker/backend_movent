<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Client Portal's own profile screen: PUT /api/client/profile.
 *
 * The rule under test is the one the screen is built around — the Contact Email
 * (clients.email) is editable and the Login Email (users.email) follows it, so
 * the address a client just typed is the address they sign in with next time.
 * The reverse is never true: the login is not separately editable, because two
 * independently-editable addresses is how someone ends up locked out of an
 * account whose contact details still look right.
 *
 * Also pins the guards on that change, since it is a credential change:
 * the current password, and the refusal to point a client login at an address
 * that already belongs to staff or a Company Admin.
 */
class ClientPortalProfileTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'client-password';

    private int $companyId;
    private User $user;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $packageId = DB::table('packages')->insertGetId([
            'name' => 'Test Package', 'tier' => 'basic', 'price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $adminId = DB::table('company_admins')->insertGetId([
            'package_id' => $packageId,
            'name' => 'Portal Admin', 'email' => 'portaladmin+' . uniqid() . '@example.test',
            'password' => Hash::make('password'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->companyId = DB::table('companies')->insertGetId([
            'admin_id' => $adminId, 'name' => 'Portal Co',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->user = User::create([
            'company_id' => $this->companyId,
            'name'       => 'Portal Client',
            'email'      => 'client+' . uniqid() . '@example.test',
            'password'   => Hash::make(self::PASSWORD),
            'role_type'  => 'client',
            'is_active'  => true,
        ]);

        $this->client = Client::create([
            'company_id'    => $this->companyId,
            'user_id'       => $this->user->id,
            'name'          => 'Portal Client',
            'email'         => $this->user->email,
            'portal_access' => true,
        ]);
    }

    /**
     * The portal routes sit behind `client.role`, which checks
     * tokenCan('role:client') — the ability Api\Client\AuthController::login()
     * mints. A plain actingAs() issues a TransientToken without it and gets a
     * 401, so the ability has to be granted explicitly here.
     */
    private function actingAsClient(): self
    {
        Sanctum::actingAs($this->user, ['role:client']);

        return $this;
    }

    private function save(array $overrides = [])
    {
        return $this->actingAsClient()->putJson('/api/client/profile', array_merge([
            'name'  => 'Portal Client',
            'email' => $this->client->fresh()->email,
        ], $overrides));
    }

    // ── Reading ──────────────────────────────────────────────────────────────

    public function test_the_profile_exposes_both_emails(): void
    {
        $this->actingAsClient()->getJson('/api/client/profile')
            ->assertOk()
            ->assertJsonPath('data.email', $this->client->email)
            ->assertJsonPath('data.login_email', $this->user->email);
    }

    // ── The core rule ────────────────────────────────────────────────────────

    public function test_changing_the_contact_email_moves_the_login_email_with_it(): void
    {
        $newEmail = 'moved+' . uniqid() . '@example.test';

        $this->save(['email' => $newEmail, 'current_password' => self::PASSWORD])->assertOk();

        $this->assertSame($newEmail, $this->client->fresh()->email, 'Contact email did not save.');
        $this->assertSame($newEmail, $this->user->fresh()->email, 'Login email did not follow the contact email.');
    }

    public function test_the_client_can_sign_in_with_the_new_email_afterwards(): void
    {
        $newEmail = 'signin+' . uniqid() . '@example.test';

        $this->save(['email' => $newEmail, 'current_password' => self::PASSWORD])->assertOk();

        $this->postJson('/api/client/login', ['email' => $newEmail, 'password' => self::PASSWORD])
            ->assertOk();
    }

    /** Editing anything else must leave the login alone and not demand a password. */
    public function test_editing_other_fields_does_not_touch_the_login_email(): void
    {
        $originalLogin = $this->user->email;

        // E.164 — App\Rules\ValidPhoneNumber rejects a bare local number.
        $this->save(['name' => 'Renamed Client', 'phone' => '+923001234567'])->assertOk();

        $this->assertSame('Renamed Client', $this->client->fresh()->name);
        $this->assertSame($originalLogin, $this->user->fresh()->email);
    }

    // ── Guards on the credential change ──────────────────────────────────────

    public function test_changing_the_email_without_the_current_password_is_refused(): void
    {
        $originalLogin = $this->user->email;

        $this->save(['email' => 'nopass+' . uniqid() . '@example.test'])->assertStatus(422);

        $this->assertSame($originalLogin, $this->user->fresh()->email);
    }

    public function test_changing_the_email_with_the_wrong_password_is_refused(): void
    {
        $originalLogin = $this->user->email;

        $this->save([
            'email' => 'wrongpass+' . uniqid() . '@example.test',
            'current_password' => 'not-the-password',
        ])->assertStatus(422);

        $this->assertSame($originalLogin, $this->user->fresh()->email);
        $this->assertSame($originalLogin, $this->client->fresh()->email, 'Contact email changed even though the login did not.');
    }

    /** A client must never be able to point their login at a staff account's address. */
    public function test_an_email_belonging_to_a_staff_account_is_refused(): void
    {
        $staff = User::create([
            'company_id' => $this->companyId,
            'name' => 'Some Seller', 'email' => 'seller+' . uniqid() . '@example.test',
            'password' => Hash::make('password'), 'role_type' => 'seller', 'is_active' => true,
        ]);

        $originalLogin = $this->user->email;

        $this->save(['email' => $staff->email, 'current_password' => self::PASSWORD])->assertStatus(422);

        $this->assertSame($originalLogin, $this->user->fresh()->email);
        $this->assertSame($staff->email, $staff->fresh()->email, 'The staff account was altered.');
    }

    /** Re-saving the same address is not a change and must not ask for a password. */
    public function test_saving_the_same_email_needs_no_password(): void
    {
        $this->save(['email' => $this->user->email])->assertOk();

        $this->assertSame($this->client->email, $this->client->fresh()->email);
    }

    // ── Access ───────────────────────────────────────────────────────────────

    public function test_the_endpoint_requires_a_signed_in_client(): void
    {
        $this->getJson('/api/client/profile')->assertStatus(401);
    }
}
