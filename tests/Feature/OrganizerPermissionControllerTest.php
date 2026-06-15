<?php

namespace Tests\Feature;

use App\Services\Organizer\OrganizerPermissionCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesMerchantPartnerPermissionFixtures;
use Tests\TestCase;

class OrganizerPermissionControllerTest extends TestCase
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
    public function itReturnsMerchantPartnerPermissionCatalogFromCsv(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/organizer/permissions/catalog');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'code',
                        'available_access',
                    ],
                ],
            ]);

        $catalogService = app(OrganizerPermissionCatalogService::class);
        $this->assertSame($catalogService->getCatalogRows(), $response->json('data'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsOnlyCatalogPermissionsForMerchantPartnerRoleEditor(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/organizer/permissions');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $codes = collect($response->json('data'))->pluck('code')->all();
        $catalogCodes = collect(app(OrganizerPermissionCatalogService::class)->getCatalogRows())
            ->pluck('code')
            ->all();

        sort($codes);
        sort($catalogCodes);

        $this->assertSame($catalogCodes, $codes);
        $this->assertNotContains('dashboard', $codes);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCapsAvailableAccessUsingOrganizerPermissionsCsv(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/organizer/permissions');

        $response->assertStatus(200);

        $categories = collect($response->json('data'))->firstWhere('code', 'categories');
        $events = collect($response->json('data'))->firstWhere('code', 'events');

        $this->assertSame(['r'], $categories['available_access']);
        $this->assertSame(['r', 'w', 'u', 'd', 'e', 'i', 'x'], $events['available_access']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresAuthenticationForOrganizerPermissionEndpoints(): void
    {
        $this->getJson('/api/v1/organizer/permissions/catalog')->assertStatus(401);
        $this->getJson('/api/v1/organizer/permissions')->assertStatus(401);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresRolesViewPermissionForOrganizerPermissionEndpoints(): void
    {
        $organizerWithoutRolesPermission = $this->createOrganizerRole([
            'code' => 'scanner-only-role',
            'name' => 'Scanner Only Role',
            'organization_uuid' => $this->testOrganization->uuid,
        ]);

        $user = \App\Models\AdminUser::create([
            'role_uuid' => $organizerWithoutRolesPermission->uuid,
            'organization_uuid' => $this->testOrganization->uuid,
            'email' => 'scanner-only@test.com',
            'password' => 'password123',
            'first_name' => 'Scanner',
            'last_name' => 'Only',
            'status' => \App\Constants\GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $token = auth('admin')->login($user) ?? '';

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/organizer/permissions/catalog')
            ->assertStatus(403);
    }
}
