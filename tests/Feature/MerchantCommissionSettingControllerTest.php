<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminWithOrganizationPermissions;
use Tests\TestCase;

class MerchantCommissionSettingControllerTest extends TestCase
{
    use CreatesAdminWithOrganizationPermissions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAdminWithOrganizationPermissions();

        Dataset::create([
            'name' => 'merchant_commission_percentage',
            'value' => '10',
            'description' => 'Default merchant commission',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsDefaultMerchantCommissionSetting(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/merchant-commission-settings');

        $response->assertStatus(200)
            ->assertJsonPath('data.default_commission_percentage', 10)
            ->assertJsonStructure([
                'data' => ['default_commission_percentage', 'updated_at'],
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itUpdatesDefaultMerchantCommissionWithoutChangingExistingOrganizations(): void
    {
        $organization = Organization::create([
            'name' => 'Existing Merchant',
            'representative_first_name' => 'Jane',
            'representative_last_name' => 'Doe',
            'address' => '123 Test Street',
            'contact_number' => '09171234567',
            'email' => 'existing@test.com',
            'status' => 'approved',
            'commission_percentage' => 8.5,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/v1/merchant-commission-settings', [
            'default_commission_percentage' => 12,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.default_commission_percentage', 12);

        $this->assertEquals('12', Dataset::where('name', 'merchant_commission_percentage')->value('value'));

        $organization->refresh();
        $this->assertEquals(8.5, (float) $organization->commission_percentage);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesDefaultMerchantCommissionRange(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/v1/merchant-commission-settings', [
            'default_commission_percentage' => 150,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['default_commission_percentage']);
    }
}
