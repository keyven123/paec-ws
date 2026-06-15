<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\Organization;
use App\Models\OrganizationPlatformCom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminWithOrganizationPermissions;
use Tests\TestCase;

class OrganizationCommissionPercentageControllerTest extends TestCase
{
    use CreatesAdminWithOrganizationPermissions;
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAdminWithOrganizationPermissions();

        $this->organization = Organization::create([
            'name' => 'Test Merchant',
            'representative_first_name' => 'Jane',
            'representative_last_name' => 'Doe',
            'address' => '123 Test Street',
            'contact_number' => '09171234567',
            'email' => 'merchant@test.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
            'commission_percentage' => 10,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itUpdatesOrganizationCommissionPercentage(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/v1/organizations/' . $this->organization->uuid . '/commission-percentage', [
            'commission_percentage' => 12.5,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.commission_percentage', 12.5);

        $this->organization->refresh();
        $this->assertEquals(12.5, (float) $this->organization->commission_percentage);

        $this->assertDatabaseHas('organization_platform_coms', [
            'organization_uuid' => $this->organization->uuid,
            'previous_coms' => '10.00',
            'current_coms' => '12.50',
            'created_by' => $this->adminUser->uuid,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itDoesNotLogWhenCommissionPercentageIsUnchanged(): void
    {
        $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/v1/organizations/' . $this->organization->uuid . '/commission-percentage', [
            'commission_percentage' => 10,
        ])->assertStatus(200);

        $this->assertDatabaseCount('organization_platform_coms', 0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesCommissionPercentageRange(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/v1/organizations/' . $this->organization->uuid . '/commission-percentage', [
            'commission_percentage' => 150,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['commission_percentage']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsNotFoundForMissingOrganization(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/v1/organizations/00000000-0000-0000-0000-000000000099/commission-percentage', [
            'commission_percentage' => 10,
        ]);

        $response->assertStatus(404)
            ->assertJsonPath('message', 'Organization not found');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresAuthentication(): void
    {
        $this->putJson('/api/v1/organizations/' . $this->organization->uuid . '/commission-percentage', [
            'commission_percentage' => 12,
        ])->assertStatus(401);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresCommissionsUpdatePermission(): void
    {
        OrganizationPlatformCom::query()->delete();

        $role = \App\Models\Role::create([
            'name' => 'Commissions Viewer',
            'code' => 'commissions-viewer',
        ]);
        $this->grantRolePermissions($role, [
            'commissions' => ['view'],
        ]);
        $viewer = \App\Models\AdminUser::create([
            'role_uuid' => $role->uuid,
            'email' => 'viewer@test.com',
            'password' => 'password123',
            'first_name' => 'View',
            'last_name' => 'Only',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);
        $viewerToken = auth('admin')->login($viewer) ?? '';

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $viewerToken,
        ])->putJson('/api/v1/organizations/' . $this->organization->uuid . '/commission-percentage', [
            'commission_percentage' => 12,
        ])->assertStatus(403);
    }
}
