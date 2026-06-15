<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\OrganizationBank;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

class OrganizerOrganizationProfileTest extends TestCase
{
    use GrantsAdminPermissions;
    use RefreshDatabase;

    private const ORGANIZATION_PROFILE_URL = '/api/v1/organizer/profile/organization';

    private Organization $organization;

    private string $organizerToken;

    protected function setUp(): void
    {
        parent::setUp();

        $organizerRole = Role::create([
            'name' => 'Organizer',
            'code' => GeneralConstants::ROLES['ORGANIZER']['name'],
            'is_admin' => false,
        ]);

        $this->grantOrganizerProfilePermissions($organizerRole);

        $this->organization = Organization::create([
            'business_type' => Organization::BUSINESS_TYPE_SOLE_PROPRIETORSHIP,
            'name' => 'Merchant A',
            'representative_first_name' => 'Jane',
            'representative_last_name' => 'Doe',
            'address' => '123 Street',
            'contact_number' => '09171234567',
            'email' => 'merchant_a@test.com',
            'description' => 'Test merchant',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ]);

        OrganizationBank::create([
            'organization_uuid' => $this->organization->uuid,
            'account_type' => OrganizationBank::ACCOUNT_TYPE_SAVINGS,
            'bank_name' => 'BDO',
            'bank_branch' => 'Makati',
            'bank_account_name' => 'Jane Doe',
            'bank_account_number' => '1234567890',
            'is_default' => true,
            'status' => OrganizationBank::STATUS_ACTIVE,
        ]);

        $organizerUser = AdminUser::create([
            'role_uuid' => $organizerRole->uuid,
            'organization_uuid' => $this->organization->uuid,
            'email' => 'organizer-profile@test.com',
            'password' => 'password123',
            'first_name' => 'Org',
            'last_name' => 'User',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $this->organizerToken = auth('admin')->login($organizerUser) ?? '';
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validOrganizationProfilePayload(array $overrides = []): array
    {
        return array_merge([
            'business_type' => Organization::BUSINESS_TYPE_SOLE_PROPRIETORSHIP,
            'address' => '123 Street',
            'contact_number' => '09171234567',
            'email' => 'merchant_a@test.com',
            'description' => 'Test merchant',
            'tin' => null,
        ], $overrides);
    }

    private function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->organizerToken,
        ];
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsBusinessTypeOnOrganizationProfile(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson(self::ORGANIZATION_PROFILE_URL);

        $response->assertStatus(200)
            ->assertJsonPath('data.business_type', Organization::BUSINESS_TYPE_SOLE_PROPRIETORSHIP);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresBusinessTypeWhenUpdatingOrganizationProfile(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->putJson(self::ORGANIZATION_PROFILE_URL, [
                'address' => 'Updated Address',
                'contact_number' => '09171234567',
                'email' => 'merchant_a@test.com',
                'description' => 'Updated description',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['business_type']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesInvalidBusinessTypeOnUpdate(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->putJson(self::ORGANIZATION_PROFILE_URL, $this->validOrganizationProfilePayload([
                'business_type' => 'invalid_type',
            ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['business_type']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanUpdateOrganizationBusinessType(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->putJson(self::ORGANIZATION_PROFILE_URL, $this->validOrganizationProfilePayload([
                'business_type' => Organization::BUSINESS_TYPE_CORPORATION,
                'address' => 'Updated Address',
                'description' => 'Updated description',
            ]));

        $response->assertStatus(200)
            ->assertJsonPath('data.business_type', Organization::BUSINESS_TYPE_CORPORATION)
            ->assertJsonPath('data.address', 'Updated Address');

        $this->assertDatabaseHas('organizations', [
            'uuid' => $this->organization->uuid,
            'business_type' => Organization::BUSINESS_TYPE_CORPORATION,
            'address' => 'Updated Address',
        ]);
    }
}
