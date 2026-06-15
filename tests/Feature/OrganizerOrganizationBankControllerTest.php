<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\OrganizationBank;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

class OrganizerOrganizationBankControllerTest extends TestCase
{
    use GrantsAdminPermissions;
    use RefreshDatabase;

    private const BANKS_INDEX_URL = '/api/v1/organizer/profile/organization/banks';

    private Organization $organization;

    private Organization $otherOrganization;

    private AdminUser $organizerUser;

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
            'name' => 'Merchant A',
            'representative_first_name' => 'Jane',
            'representative_last_name' => 'Doe',
            'address' => '123 Street',
            'contact_number' => '09171234567',
            'email' => 'merchant_a@test.com',
            'description' => 'Test merchant',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ]);

        $this->otherOrganization = Organization::create([
            'name' => 'Merchant B',
            'representative_first_name' => 'John',
            'representative_last_name' => 'Smith',
            'address' => '456 Street',
            'contact_number' => '09179876543',
            'email' => 'merchant_b@test.com',
            'description' => 'Other merchant',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ]);

        $this->organizerUser = AdminUser::create([
            'role_uuid' => $organizerRole->uuid,
            'organization_uuid' => $this->organization->uuid,
            'email' => 'organizer@test.com',
            'password' => 'password123',
            'first_name' => 'Org',
            'last_name' => 'User',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $this->organizerToken = auth('admin')->login($this->organizerUser) ?? '';
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validBankPayload(array $overrides = []): array
    {
        return array_merge([
            'account_type' => OrganizationBank::ACCOUNT_TYPE_SAVINGS,
            'bank_name' => 'BDO',
            'bank_branch' => 'Makati',
            'bank_address' => '123 Banking Street',
            'bank_account_name' => 'Jane Doe',
            'bank_account_number' => '1234567890',
            'is_default' => true,
            'status' => OrganizationBank::STATUS_ACTIVE,
        ], $overrides);
    }

    private function authHeaders(?string $token = null): array
    {
        return [
            'Authorization' => 'Bearer ' . ($token ?? $this->organizerToken),
        ];
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresAuthenticationForBankEndpoints(): void
    {
        $this->getJson(self::BANKS_INDEX_URL)->assertStatus(401);
        $this->postJson(self::BANKS_INDEX_URL, $this->validBankPayload())->assertStatus(401);
        $this->putJson(self::BANKS_INDEX_URL . '/sync', ['banks' => []])->assertStatus(401);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresProfileViewPermissionToListBanks(): void
    {
        $role = Role::create([
            'name' => 'Viewer',
            'code' => 'viewer',
            'is_admin' => false,
        ]);

        $permission = Permission::create([
            'name' => 'Profile',
            'code' => 'profile',
            'available_access' => ['view'],
        ]);

        RolePermission::create([
            'role_uuid' => $role->uuid,
            'permission_uuid' => $permission->uuid,
            'access' => 'profile-view',
        ]);

        $viewer = AdminUser::create([
            'role_uuid' => $role->uuid,
            'organization_uuid' => $this->organization->uuid,
            'email' => 'viewer@test.com',
            'password' => 'password123',
            'first_name' => 'View',
            'last_name' => 'Only',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $viewerToken = auth('admin')->login($viewer) ?? '';

        $this->withHeaders($this->authHeaders($viewerToken))
            ->getJson(self::BANKS_INDEX_URL)
            ->assertStatus(200);

        $this->withHeaders($this->authHeaders($viewerToken))
            ->putJson(self::BANKS_INDEX_URL . '/sync', [
                'banks' => [$this->validBankPayload()],
            ])
            ->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itListsOrganizationBanksForAuthenticatedOrganizer(): void
    {
        $bank = OrganizationBank::create(array_merge($this->validBankPayload(), [
            'organization_uuid' => $this->organization->uuid,
        ]));

        $response = $this->withHeaders($this->authHeaders())
            ->getJson(self::BANKS_INDEX_URL);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $bank->uuid)
            ->assertJsonPath('data.0.bank_name', 'BDO')
            ->assertJsonPath('data.0.account_type', OrganizationBank::ACCOUNT_TYPE_SAVINGS)
            ->assertJsonPath('data.0.is_default', true)
            ->assertJsonPath('data.0.status', OrganizationBank::STATUS_ACTIVE);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsBanksOnOrganizationProfile(): void
    {
        OrganizationBank::create(array_merge($this->validBankPayload(), [
            'organization_uuid' => $this->organization->uuid,
        ]));

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/organizer/profile/organization');

        $response->assertStatus(200)
            ->assertJsonPath('data.bank_name', 'BDO')
            ->assertJsonPath('data.bank_account_number', '1234567890')
            ->assertJsonCount(1, 'data.banks')
            ->assertJsonPath('data.banks.0.bank_branch', 'Makati');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanCreateOrganizationBank(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson(self::BANKS_INDEX_URL, $this->validBankPayload());

        $response->assertStatus(201)
            ->assertJsonPath('data.bank_name', 'BDO')
            ->assertJsonPath('data.is_default', true);

        $this->assertDatabaseHas('organization_banks', [
            'organization_uuid' => $this->organization->uuid,
            'bank_name' => 'BDO',
            'bank_account_number' => '1234567890',
            'is_default' => true,
            'status' => OrganizationBank::STATUS_ACTIVE,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itSetsFirstBankAsDefaultWhenCreatingWithoutExplicitDefault(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson(self::BANKS_INDEX_URL, $this->validBankPayload([
                'is_default' => false,
            ]));

        $response->assertStatus(201)
            ->assertJsonPath('data.is_default', true);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanUpdateOrganizationBank(): void
    {
        $bank = OrganizationBank::create(array_merge($this->validBankPayload(), [
            'organization_uuid' => $this->organization->uuid,
        ]));

        $response = $this->withHeaders($this->authHeaders())
            ->putJson(self::BANKS_INDEX_URL . '/' . $bank->uuid, $this->validBankPayload([
                'bank_name' => 'Metrobank',
                'bank_branch' => 'BGC',
                'status' => OrganizationBank::STATUS_INACTIVE,
            ]));

        $response->assertStatus(200)
            ->assertJsonPath('data.bank_name', 'Metrobank')
            ->assertJsonPath('data.status', OrganizationBank::STATUS_INACTIVE);

        $this->assertDatabaseHas('organization_banks', [
            'uuid' => $bank->uuid,
            'bank_name' => 'Metrobank',
            'status' => OrganizationBank::STATUS_INACTIVE,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanDeleteOrganizationBankAndPromoteNewDefault(): void
    {
        $defaultBank = OrganizationBank::create(array_merge($this->validBankPayload([
            'is_default' => true,
            'bank_account_number' => '1111111111',
        ]), [
            'organization_uuid' => $this->organization->uuid,
        ]));

        $secondaryBank = OrganizationBank::create(array_merge($this->validBankPayload([
            'is_default' => false,
            'bank_name' => 'Metrobank',
            'bank_account_number' => '2222222222',
        ]), [
            'organization_uuid' => $this->organization->uuid,
        ]));

        $this->withHeaders($this->authHeaders())
            ->deleteJson(self::BANKS_INDEX_URL . '/' . $defaultBank->uuid)
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('organization_banks', [
            'uuid' => $defaultBank->uuid,
        ]);

        $this->assertDatabaseHas('organization_banks', [
            'uuid' => $secondaryBank->uuid,
            'is_default' => true,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanSyncMultipleOrganizationBanks(): void
    {
        $existing = OrganizationBank::create(array_merge($this->validBankPayload([
            'bank_account_number' => '1111111111',
        ]), [
            'organization_uuid' => $this->organization->uuid,
        ]));

        $response = $this->withHeaders($this->authHeaders())
            ->putJson(self::BANKS_INDEX_URL . '/sync', [
                'banks' => [
                    array_merge($this->validBankPayload([
                        'uuid' => $existing->uuid,
                        'bank_account_number' => '1111111111',
                        'is_default' => false,
                    ])),
                    $this->validBankPayload([
                        'bank_name' => 'Metrobank',
                        'bank_account_number' => '2222222222',
                        'is_default' => true,
                    ]),
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');

        $banks = collect($response->json('data'));
        $this->assertTrue($banks->contains(
            fn (array $bank) => $bank['uuid'] === $existing->uuid && $bank['is_default'] === false,
        ));
        $this->assertTrue($banks->contains(
            fn (array $bank) => $bank['bank_name'] === 'Metrobank'
                && $bank['is_default'] === true
                && $bank['bank_account_number'] === '2222222222',
        ));

        $this->assertDatabaseCount('organization_banks', 2);
        $this->assertDatabaseHas('organization_banks', [
            'uuid' => $existing->uuid,
            'is_default' => false,
        ]);
        $this->assertDatabaseHas('organization_banks', [
            'bank_name' => 'Metrobank',
            'is_default' => true,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itSyncRemovesBanksNotIncludedInPayload(): void
    {
        $keep = OrganizationBank::create(array_merge($this->validBankPayload([
            'bank_account_number' => '1111111111',
        ]), [
            'organization_uuid' => $this->organization->uuid,
        ]));

        $remove = OrganizationBank::create(array_merge($this->validBankPayload([
            'bank_name' => 'Remove Me Bank',
            'bank_account_number' => '9999999999',
            'is_default' => false,
        ]), [
            'organization_uuid' => $this->organization->uuid,
        ]));

        $this->withHeaders($this->authHeaders())
            ->putJson(self::BANKS_INDEX_URL . '/sync', [
                'banks' => [
                    array_merge($this->validBankPayload([
                        'uuid' => $keep->uuid,
                        'bank_account_number' => '1111111111',
                    ])),
                ],
            ])
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->assertDatabaseHas('organization_banks', ['uuid' => $keep->uuid]);
        $this->assertDatabaseMissing('organization_banks', ['uuid' => $remove->uuid]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itAssignsDefaultToFirstBankOnSyncWhenNoneMarkedDefault(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->putJson(self::BANKS_INDEX_URL . '/sync', [
                'banks' => [
                    $this->validBankPayload([
                        'bank_account_number' => '1111111111',
                        'is_default' => false,
                    ]),
                    $this->validBankPayload([
                        'bank_name' => 'Metrobank',
                        'bank_account_number' => '2222222222',
                        'is_default' => false,
                    ]),
                ],
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('organization_banks', [
            'bank_account_number' => '1111111111',
            'is_default' => true,
        ]);
        $this->assertDatabaseHas('organization_banks', [
            'bank_account_number' => '2222222222',
            'is_default' => false,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesRequiredBankFieldsOnStore(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson(self::BANKS_INDEX_URL, [
                'bank_name' => '',
                'bank_branch' => '',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'account_type',
                'bank_name',
                'bank_branch',
                'bank_account_name',
                'bank_account_number',
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesInvalidAccountTypeOnStore(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson(self::BANKS_INDEX_URL, $this->validBankPayload([
                'account_type' => 'checking',
            ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['account_type']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itAllowsStoreWithoutBankAddress(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson(self::BANKS_INDEX_URL, $this->validBankPayload([
                'bank_address' => null,
            ]));

        $response->assertStatus(201)
            ->assertJsonPath('data.bank_address', null);

        $this->assertDatabaseHas('organization_banks', [
            'bank_account_number' => '1234567890',
            'bank_address' => null,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesInvalidStatusOnStore(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson(self::BANKS_INDEX_URL, $this->validBankPayload([
                'status' => 'paused',
            ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturns404WhenUpdatingBankFromAnotherOrganization(): void
    {
        $foreignBank = OrganizationBank::create(array_merge($this->validBankPayload(), [
            'organization_uuid' => $this->otherOrganization->uuid,
        ]));

        $this->withHeaders($this->authHeaders())
            ->putJson(self::BANKS_INDEX_URL . '/' . $foreignBank->uuid, $this->validBankPayload([
                'bank_name' => 'Hacked Bank',
            ]))
            ->assertStatus(404);

        $this->assertDatabaseHas('organization_banks', [
            'uuid' => $foreignBank->uuid,
            'bank_name' => 'BDO',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturns404WhenDeletingBankFromAnotherOrganization(): void
    {
        $foreignBank = OrganizationBank::create(array_merge($this->validBankPayload(), [
            'organization_uuid' => $this->otherOrganization->uuid,
        ]));

        $this->withHeaders($this->authHeaders())
            ->deleteJson(self::BANKS_INDEX_URL . '/' . $foreignBank->uuid)
            ->assertStatus(404);

        $this->assertDatabaseHas('organization_banks', [
            'uuid' => $foreignBank->uuid,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itDoesNotAcceptBankFieldsOnOrganizationProfileUpdate(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->putJson('/api/v1/organizer/profile/organization', [
                'business_type' => Organization::BUSINESS_TYPE_SOLE_PROPRIETORSHIP,
                'address' => 'Updated Address',
                'contact_number' => '09171234567',
                'email' => 'merchant_a@test.com',
                'description' => 'Updated description',
                'bank_name' => 'Should Not Apply',
                'bank_account_number' => '0000000000',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.address', 'Updated Address');

        $this->assertDatabaseMissing('organization_banks', [
            'organization_uuid' => $this->organization->uuid,
            'bank_name' => 'Should Not Apply',
        ]);
    }
}
