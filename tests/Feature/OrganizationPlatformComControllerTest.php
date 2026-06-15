<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\Dataset;
use App\Models\Organization;
use App\Models\OrganizationPlatformCom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminWithOrganizationPermissions;
use Tests\TestCase;

class OrganizationPlatformComControllerTest extends TestCase
{
    use CreatesAdminWithOrganizationPermissions;
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAdminWithOrganizationPermissions();

        Dataset::create([
            'name' => 'merchant_commission_percentage',
            'value' => '10',
            'description' => 'Default merchant commission',
        ]);

        $this->organization = Organization::create([
            'name' => 'Test Merchant',
            'representative_first_name' => 'Jane',
            'representative_last_name' => 'Doe',
            'address' => '123 Test Street',
            'contact_number' => '09171234567',
            'email' => 'merchant@test.com',
            'bank_name' => 'Test Bank',
            'bank_branch' => 'Main',
            'bank_address' => 'Bank Address',
            'bank_account_name' => 'Jane Doe',
            'bank_account_number' => '1234567890',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
            'commission_percentage' => 10,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itLogsPlatformDefaultCommissionChangeWithNullOrganization(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/v1/merchant-commission-settings', [
            'default_commission_percentage' => 15,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('organization_platform_coms', [
            'organization_uuid' => null,
            'previous_coms' => '10.00',
            'current_coms' => '15.00',
            'created_by' => $this->adminUser->uuid,
        ]);

        $this->organization->refresh();
        $this->assertEquals(10, (float) $this->organization->commission_percentage);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itDoesNotLogPlatformDefaultCommissionWhenValueUnchanged(): void
    {
        $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/v1/merchant-commission-settings', [
            'default_commission_percentage' => 10,
        ])->assertStatus(200);

        $this->assertDatabaseCount('organization_platform_coms', 0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itLogsOrganizationCommissionChangeOnUpdate(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/v1/organizations/' . $this->organization->uuid . '/commission-percentage', [
            'commission_percentage' => 12.5,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('organization_platform_coms', [
            'organization_uuid' => $this->organization->uuid,
            'previous_coms' => '10.00',
            'current_coms' => '12.50',
            'created_by' => $this->adminUser->uuid,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itDoesNotLogOrganizationCommissionWhenValueUnchanged(): void
    {
        $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/v1/organizations/' . $this->organization->uuid . '/commission-percentage', [
            'commission_percentage' => 10,
        ])->assertStatus(200);

        $this->assertDatabaseCount('organization_platform_coms', 0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itListsCommissionLogsFilteredByOrganization(): void
    {
        $log = OrganizationPlatformCom::create([
            'organization_uuid' => $this->organization->uuid,
            'previous_coms' => 10,
            'current_coms' => 12.5,
            'created_by' => $this->adminUser->uuid,
        ]);

        OrganizationPlatformCom::create([
            'organization_uuid' => null,
            'previous_coms' => 10,
            'current_coms' => 15,
            'created_by' => $this->adminUser->uuid,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/organization-platform-coms?organization_uuid=' . $this->organization->uuid);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $log->uuid)
            ->assertJsonPath('data.0.organization_uuid', $this->organization->uuid)
            ->assertJsonPath('data.0.current_coms', 12.5);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itListsCommissionLogsForPlatformDefaultOnly(): void
    {
        OrganizationPlatformCom::create([
            'organization_uuid' => $this->organization->uuid,
            'previous_coms' => 10,
            'current_coms' => 12.5,
            'created_by' => $this->adminUser->uuid,
        ]);

        $platformLog = OrganizationPlatformCom::create([
            'organization_uuid' => null,
            'previous_coms' => 10,
            'current_coms' => 15,
            'created_by' => $this->adminUser->uuid,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/organization-platform-coms?platform_default=true');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $platformLog->uuid)
            ->assertJsonPath('data.0.organization_uuid', null)
            ->assertJsonPath('data.0.current_coms', 15);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itShowsSingleCommissionLog(): void
    {
        $log = OrganizationPlatformCom::create([
            'organization_uuid' => $this->organization->uuid,
            'previous_coms' => 10,
            'current_coms' => 12.5,
            'created_by' => $this->adminUser->uuid,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/organization-platform-coms/' . $log->uuid);

        $response->assertStatus(200)
            ->assertJsonPath('data.uuid', $log->uuid)
            ->assertJsonPath('data.previous_coms', 10)
            ->assertJsonPath('data.current_coms', 12.5)
            ->assertJsonPath('data.created_by', $this->adminUser->uuid);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsNotFoundForMissingCommissionLog(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/organization-platform-coms/00000000-0000-0000-0000-000000000099');

        $response->assertStatus(404)
            ->assertJsonPath('message', 'Organization platform commission log not found');
    }
}
