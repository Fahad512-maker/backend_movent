<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CompanyAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * One rule, both sides of the product: a client's CONTACT email and their
 * PORTAL LOGIN email are the same address.
 *
 * Tests\Feature\ClientPortalProfileTest covers the client editing it
 * themselves. This covers the admin editing it on
 * /admin/clients/{id} — which did NOT keep the two in step, so an admin
 * changing the contact address left the portal login pointing at the old one.
 * That was invisible: the Portal Login tab still showed the stale address, its
 * email field is not editable, and the client kept signing in with an address
 * nothing on screen mentioned any more.
 */
class ClientEmailSyncTest extends TestCase
{
    use DatabaseTransactions;

    private CompanyAdmin $admin;
    private int $companyId;
    private User $portalUser;
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
            'name' => 'Sync Admin', 'email' => 'syncadmin+' . uniqid() . '@example.test',
            'password' => Hash::make('password'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin = CompanyAdmin::findOrFail($adminId);

        $this->companyId = DB::table('companies')->insertGetId([
            'admin_id' => $adminId, 'name' => 'Sync Co',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('company_modules')->insert([
            'company_id' => $this->companyId, 'module_key' => 'clients', 'is_enabled' => true,
            'updated_at' => now(),
        ]);

        $this->portalUser = User::create([
            'company_id' => $this->companyId,
            'name' => 'Portal Client', 'email' => 'contact+' . uniqid() . '@example.test',
            'password' => Hash::make('password'), 'role_type' => 'client', 'is_active' => true,
        ]);

        $this->client = Client::create([
            'company_id' => $this->companyId,
            'user_id' => $this->portalUser->id,
            'name' => 'Portal Client',
            'email' => $this->portalUser->email,
            'portal_access' => true,
        ]);
    }

    private function updateClient(array $payload)
    {
        return $this->actingAs($this->admin, 'admin')
            ->putJson("/api/admin/clients/{$this->client->id}", array_merge([
                'name' => $this->client->name,
            ], $payload));
    }

    public function test_an_admin_changing_the_contact_email_moves_the_portal_login(): void
    {
        $newEmail = 'moved+' . uniqid() . '@example.test';

        $this->updateClient(['email' => $newEmail])->assertOk();

        $this->assertSame($newEmail, $this->client->fresh()->email);
        $this->assertSame(
            $newEmail,
            $this->portalUser->fresh()->email,
            'The portal login was left pointing at the old address.'
        );
    }

    public function test_the_client_can_sign_in_with_the_address_the_admin_set(): void
    {
        $newEmail = 'adminset+' . uniqid() . '@example.test';

        $this->updateClient(['email' => $newEmail])->assertOk();

        $this->postJson('/api/client/login', ['email' => $newEmail, 'password' => 'password'])
            ->assertOk();
    }

    /** Editing anything else must leave the login alone. */
    public function test_editing_other_fields_leaves_the_login_email_untouched(): void
    {
        $original = $this->portalUser->email;

        $this->updateClient(['name' => 'Renamed', 'email' => $original])->assertOk();

        $this->assertSame('Renamed', $this->client->fresh()->name);
        $this->assertSame($original, $this->portalUser->fresh()->email);
    }

    /** A client with no portal login has no credential to move — and must not error. */
    public function test_a_client_without_a_portal_login_updates_normally(): void
    {
        $plain = Client::create([
            'company_id' => $this->companyId,
            'name' => 'No Portal', 'email' => 'noportal+' . uniqid() . '@example.test',
            'portal_access' => false,
        ]);

        $newEmail = 'changed+' . uniqid() . '@example.test';

        $this->actingAs($this->admin, 'admin')
            ->putJson("/api/admin/clients/{$plain->id}", ['name' => 'No Portal', 'email' => $newEmail])
            ->assertOk();

        $this->assertSame($newEmail, $plain->fresh()->email);
    }

    /** The login must never be pointed at an address another account already holds. */
    public function test_an_address_held_by_another_account_is_refused(): void
    {
        $other = User::create([
            'company_id' => $this->companyId,
            'name' => 'Someone Else', 'email' => 'taken+' . uniqid() . '@example.test',
            'password' => Hash::make('password'), 'role_type' => 'seller', 'is_active' => true,
        ]);

        $originalContact = $this->client->email;
        $originalLogin   = $this->portalUser->email;

        $this->updateClient(['email' => $other->email])->assertStatus(422);

        $this->assertSame($originalContact, $this->client->fresh()->email, 'The contact email changed despite the refusal.');
        $this->assertSame($originalLogin, $this->portalUser->fresh()->email);
        $this->assertSame($other->email, $other->fresh()->email, 'The other account was altered.');
    }
}
