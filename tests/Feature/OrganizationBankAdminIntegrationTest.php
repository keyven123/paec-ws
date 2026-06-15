<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Http\Repositories\OrganizationRepository;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\OrganizationBank;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

class OrganizationBankAdminIntegrationTest extends TestCase
{
    use GrantsAdminPermissions;
    use RefreshDatabase;

    private AdminUser $adminUser;

    private string $adminToken;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function bankFields(array $overrides = []): array
    {
        return array_merge([
            'account_type' => OrganizationBank::ACCOUNT_TYPE_SAVINGS,
            'bank_name' => 'BDO',
            'bank_branch' => 'Makati',
            'bank_address' => '123 Banking Street',
            'bank_account_name' => 'Jane Doe',
            'bank_account_number' => '1234567890',
        ], $overrides);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::create([
            'name' => 'Admin',
            'code' => GeneralConstants::ROLES['ADMIN']['name'],
            'is_admin' => true,
        ]);

        $this->grantRolePermissions($adminRole, [
            'organizations' => ['view', 'update'],
            'commissions' => ['view', 'update'],
            'payment-methods' => ['view', 'update'],
        ]);

        $this->adminUser = AdminUser::create([
            'role_uuid' => $adminRole->uuid,
            'email' => 'admin-banks@test.com',
            'password' => 'password123',
            'first_name' => 'Platform',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $this->adminToken = auth('admin')->login($this->adminUser) ?? '';
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itExposesDefaultBankFieldsOnOrganizationShow(): void
    {
        $organization = Organization::create([
            'name' => 'Test Merchant',
            'representative_first_name' => 'Jane',
            'representative_last_name' => 'Doe',
            'address' => '123 Test Street',
            'contact_number' => '09171234567',
            'email' => 'merchant-show@test.com',
            'description' => 'Merchant description',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ]);

        OrganizationBank::create(array_merge($this->bankFields(), [
            'organization_uuid' => $organization->uuid,
            'is_default' => true,
            'status' => OrganizationBank::STATUS_ACTIVE,
        ]));

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/organizations/' . $organization->uuid);

        $response->assertStatus(200)
            ->assertJsonPath('data.bank_name', 'BDO')
            ->assertJsonPath('data.bank_account_number', '1234567890')
            ->assertJsonCount(1, 'data.banks')
            ->assertJsonPath('data.banks.0.bank_branch', 'Makati');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCreatesDefaultBankWhenOrganizationRepositoryCreatesWithBankDetails(): void
    {
        $organization = app(OrganizationRepository::class)->create(array_merge([
            'name' => 'New Merchant',
            'representative_first_name' => 'New',
            'representative_last_name' => 'Merchant',
            'address' => 'New Address',
            'contact_number' => '09171234567',
            'email' => 'new-merchant@test.com',
            'description' => 'New merchant description',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ], $this->bankFields([
            'bank_name' => 'UnionBank',
            'bank_account_number' => '5555555555',
        ])));

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/organizations/' . $organization->uuid);

        $response->assertStatus(200)
            ->assertJsonPath('data.bank_name', 'UnionBank')
            ->assertJsonPath('data.bank_account_number', '5555555555')
            ->assertJsonCount(1, 'data.banks');

        $this->assertDatabaseHas('organization_banks', [
            'organization_uuid' => $organization->uuid,
            'bank_name' => 'UnionBank',
            'bank_account_number' => '5555555555',
            'is_default' => true,
            'status' => OrganizationBank::STATUS_ACTIVE,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itUpdatesDefaultBankWhenAdminUpdatesOrganizationBankFields(): void
    {
        $organization = Organization::create([
            'name' => 'Test Merchant',
            'representative_first_name' => 'Jane',
            'representative_last_name' => 'Doe',
            'address' => '123 Test Street',
            'contact_number' => '09171234567',
            'email' => 'merchant-update@test.com',
            'description' => 'Merchant description',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ]);

        $defaultBank = OrganizationBank::create(array_merge($this->bankFields(), [
            'organization_uuid' => $organization->uuid,
            'is_default' => true,
            'status' => OrganizationBank::STATUS_ACTIVE,
        ]));

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/v1/organizations/' . $organization->uuid, array_merge([
            'name' => $organization->name,
            'representative_first_name' => $organization->representative_first_name,
            'representative_last_name' => $organization->representative_last_name,
            'address' => $organization->address,
            'contact_number' => $organization->contact_number,
            'email' => $organization->email,
            'description' => $organization->description,
        ], $this->bankFields([
            'bank_name' => 'Updated Bank',
            'bank_account_number' => '9876543210',
        ])));

        $response->assertStatus(200)
            ->assertJsonPath('data.bank_name', 'Updated Bank')
            ->assertJsonPath('data.bank_account_number', '9876543210');

        $this->assertDatabaseHas('organization_banks', [
            'uuid' => $defaultBank->uuid,
            'bank_name' => 'Updated Bank',
            'bank_account_number' => '9876543210',
            'is_default' => true,
        ]);

        $this->assertDatabaseCount('organization_banks', 1);
    }
}
