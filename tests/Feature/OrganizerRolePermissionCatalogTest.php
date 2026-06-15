<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesMerchantPartnerPermissionFixtures;
use Tests\TestCase;

class OrganizerRolePermissionCatalogTest extends TestCase
{
    use CreatesMerchantPartnerPermissionFixtures;
    use RefreshDatabase;

    private string $organizerToken;

    protected function setUp(): void
    {
        parent::setUp();

        $organizerUser = $this->createOrganizerAdminWithRolePermissions();
        $this->organizerToken = auth('admin')->login($organizerUser) ?? '';
    }

    private function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->organizerToken,
        ];
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCreatesMerchantPartnerRoleWithCatalogPermissions(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/organizer/roles', [
                'name' => 'Content Manager',
                'code' => 'content-manager',
                'permission_grants' => [
                    ['code' => 'events', 'available_access' => 'rw'],
                    ['code' => 'categories', 'available_access' => 'r'],
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $roleUuid = $response->json('data.uuid');

        $this->assertDatabaseHas('role_permissions', [
            'role_uuid' => $roleUuid,
            'access' => 'events-view',
        ]);
        $this->assertDatabaseHas('role_permissions', [
            'role_uuid' => $roleUuid,
            'access' => 'events-create',
        ]);
        $this->assertDatabaseHas('role_permissions', [
            'role_uuid' => $roleUuid,
            'access' => 'categories-view',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRejectsMerchantPartnerRoleGrantsOutsideCatalog(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/organizer/roles', [
                'name' => 'Invalid Role',
                'code' => 'invalid-catalog-role',
                'permission_grants' => [
                    ['code' => 'dashboard', 'available_access' => 'r'],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['permission_grants']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRejectsMerchantPartnerRoleGrantsWithDisallowedAccessLetters(): void
    {
        $role = $this->createOrganizerRole([
            'code' => 'existing-merchant-role',
            'name' => 'Existing Merchant Role',
            'organization_uuid' => $this->testOrganization->uuid,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/organizer/roles/' . $role->uuid . '/permissions', [
                'permission_grants' => [
                    ['code' => 'venues', 'available_access' => 'rw'],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['permission_grants']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itAssignsCatalogPermissionsToExistingMerchantPartnerRole(): void
    {
        $role = $this->createOrganizerRole([
            'code' => 'assignable-merchant-role',
            'name' => 'Assignable Merchant Role',
            'organization_uuid' => $this->testOrganization->uuid,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/organizer/roles/' . $role->uuid . '/permissions', [
                'permission_grants' => [
                    ['code' => 'venues', 'available_access' => 'r'],
                    ['code' => 'promo-codes', 'available_access' => 'rwudx'],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('role_permissions', [
            'role_uuid' => $role->uuid,
            'access' => 'venues-view',
        ]);
        $this->assertDatabaseHas('role_permissions', [
            'role_uuid' => $role->uuid,
            'access' => 'promo-codes-delete',
        ]);
    }
}
