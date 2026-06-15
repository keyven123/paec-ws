<?php

namespace Tests\Feature;

use App\Services\Organizer\OrganizerPermissionCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesMerchantPartnerPermissionFixtures;
use Tests\TestCase;

class PermissionControllerMerchantPartnerTest extends TestCase
{
    use CreatesMerchantPartnerPermissionFixtures;
    use RefreshDatabase;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $adminUser = $this->createPlatformAdminWithRolePermissions();
        $this->adminToken = auth('admin')->login($adminUser) ?? '';
    }

    private function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->adminToken,
        ];
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsMerchantPartnerCatalogForPlatformAdmin(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/permissions/merchant-partner/catalog');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $catalogService = app(OrganizerPermissionCatalogService::class);
        $this->assertSame($catalogService->getCatalogRows(), $response->json('data'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsCatalogFilteredPermissionsForPlatformAdmin(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/permissions/merchant-partner');

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
    public function itRequiresAuthenticationForMerchantPartnerPermissionEndpoints(): void
    {
        $this->getJson('/api/v1/permissions/merchant-partner/catalog')->assertStatus(401);
        $this->getJson('/api/v1/permissions/merchant-partner')->assertStatus(401);
    }
}
