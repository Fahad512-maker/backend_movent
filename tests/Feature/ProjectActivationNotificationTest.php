<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use App\Services\PaymentProjectStartService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Who hears about it when a paid invoice turns into a live project.
 *
 * The standing fan-out (ProjectCompletionService::notificationTargetUserIds())
 * covers the people who will DO the work — PM, team, production, the project's
 * seller. It never covered the person who raised the invoice and got it paid,
 * who is the reason the project exists at all and is frequently none of those
 * roles. That is what this file pins.
 *
 * Exercises the real finishActivation(), so the recipient list under test is
 * the production one.
 */
class ProjectActivationNotificationTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $packageId = DB::table('packages')->insertGetId([
            'name' => 'Test Package', 'tier' => 'basic', 'price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->adminId = DB::table('company_admins')->insertGetId([
            'package_id' => $packageId,
            'name' => 'Activation Admin', 'email' => 'actadmin+' . uniqid() . '@example.test',
            'password' => Hash::make('password'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->companyId = DB::table('companies')->insertGetId([
            'admin_id' => $this->adminId, 'name' => 'Activation Co',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeUser(string $roleType): User
    {
        return User::create([
            'company_id' => $this->companyId,
            'name' => ucfirst($roleType) . ' ' . uniqid(),
            'email' => $roleType . '+' . uniqid() . '@example.test',
            'password' => 'password',
            'role_type' => $roleType,
            'is_active' => true,
        ]);
    }

    private function activate(Project $project, Invoice $invoice): void
    {
        $method = new ReflectionMethod(PaymentProjectStartService::class, 'finishActivation');
        $method->invoke(null, $project, $invoice);
    }

    private function notifiedUserIds(int $projectId): array
    {
        return DB::table('notifications')
            ->where('type', 'project_activated')
            ->whereNotNull('user_id')
            ->whereJsonContains('data->project_id', $projectId)
            ->pluck('user_id')->unique()->values()->all();
    }

    /**
     * The fix: whoever raised the invoice is told their project went live, even
     * though they hold no role on it.
     */
    public function test_the_invoice_creator_is_notified_when_the_project_goes_live(): void
    {
        $invoiceCreator = $this->makeUser('seller');
        $projectSeller  = $this->makeUser('seller');

        $project = Project::create([
            'company_id' => $this->companyId, 'name' => 'Live Project',
            'seller_id' => $projectSeller->id, 'status' => 'active',
        ]);

        $invoice = Invoice::create([
            'company_id' => $this->companyId,
            'invoice_number' => 'INV-' . uniqid(),
            'created_by' => $invoiceCreator->id,
            'status' => 'paid',
            'subtotal' => 100,
            'total_amount' => 100,
        ]);

        $this->activate($project, $invoice);

        $this->assertContains(
            $invoiceCreator->id,
            $this->notifiedUserIds($project->id),
            'The person who raised and collected the invoice was not told the project went live.'
        );
    }

    /** The existing fan-out must keep working — this only adds to it. */
    public function test_the_projects_own_seller_is_still_notified(): void
    {
        $invoiceCreator = $this->makeUser('seller');
        $projectSeller  = $this->makeUser('seller');

        $project = Project::create([
            'company_id' => $this->companyId, 'name' => 'Live Project',
            'seller_id' => $projectSeller->id, 'status' => 'active',
        ]);

        $invoice = Invoice::create([
            'company_id' => $this->companyId, 'invoice_number' => 'INV-' . uniqid(),
            'created_by' => $invoiceCreator->id, 'status' => 'paid', 'subtotal' => 100, 'total_amount' => 100,
        ]);

        $this->activate($project, $invoice);

        $this->assertContains($projectSeller->id, $this->notifiedUserIds($project->id));
    }

    /** Whoever raised the project stub earlier is waiting to hear it went live too. */
    public function test_the_project_creator_is_notified(): void
    {
        $projectCreator = $this->makeUser('seller');
        $invoiceCreator = $this->makeUser('seller');

        $project = Project::create([
            'company_id' => $this->companyId, 'name' => 'Live Project',
            'created_by' => $projectCreator->id, 'status' => 'active',
        ]);

        $invoice = Invoice::create([
            'company_id' => $this->companyId, 'invoice_number' => 'INV-' . uniqid(),
            'created_by' => $invoiceCreator->id, 'status' => 'paid', 'subtotal' => 100, 'total_amount' => 100,
        ]);

        $this->activate($project, $invoice);

        $notified = $this->notifiedUserIds($project->id);
        $this->assertContains($projectCreator->id, $notified);
        $this->assertContains($invoiceCreator->id, $notified);
    }

    public function test_the_company_admin_is_notified(): void
    {
        $project = Project::create([
            'company_id' => $this->companyId, 'name' => 'Live Project', 'status' => 'active',
        ]);

        $invoice = Invoice::create([
            'company_id' => $this->companyId, 'invoice_number' => 'INV-' . uniqid(),
            'status' => 'paid', 'subtotal' => 100, 'total_amount' => 100,
        ]);

        $this->activate($project, $invoice);

        $this->assertTrue(
            DB::table('notifications')
                ->where('type', 'project_activated')
                ->where('recipient_admin_id', $this->adminId)
                ->exists(),
            'The Company Admin was not notified that a project went live.'
        );
    }

    /**
     * An invoice raised by a Company Admin carries created_by_admin_id and a
     * NULL created_by. That must not blow up the activation, and must not
     * invent a notification for user 0.
     */
    public function test_an_admin_raised_invoice_activates_without_a_user_creator(): void
    {
        $project = Project::create([
            'company_id' => $this->companyId, 'name' => 'Live Project', 'status' => 'active',
        ]);

        $invoice = Invoice::create([
            'company_id' => $this->companyId, 'invoice_number' => 'INV-' . uniqid(),
            'created_by' => null, 'created_by_admin_id' => $this->adminId,
            'status' => 'paid', 'subtotal' => 100, 'total_amount' => 100,
        ]);

        $this->activate($project, $invoice);

        $this->assertSame([], $this->notifiedUserIds($project->id));
        $this->assertTrue(
            DB::table('notifications')->where('type', 'project_activated')
                ->where('recipient_admin_id', $this->adminId)->exists()
        );
    }
}
