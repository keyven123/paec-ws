<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Role;
use App\Models\User;
use App\Models\Permission;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\Concerns\SeedsUserAffiliate;
use Tests\TestCase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class UserControllerTest extends TestCase
{
    use GrantsAdminPermissions;
    use RefreshDatabase;
    use SeedsUserAffiliate;
    use WithFaker;

    private AdminUser $adminUser;
    private User $testUser;
    private Role $adminRole;
    private Role $customerRole;
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles
        $this->adminRole = Role::create([
            'name' => 'Admin',
            'code' => GeneralConstants::ROLES['ADMIN']['name'],
            'is_admin' => true,
        ]);

        $this->customerRole = Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);

        $this->grantRolePermissions($this->adminRole, [
            'users' => ['view', 'create', 'update', 'delete'],
        ]);
        $this->grantAffiliatePartnerAdminPermissions($this->adminRole);

        // Create admin user
        $this->adminUser = AdminUser::create([
            'role_uuid' => $this->adminRole->uuid,
            'email' => 'admin@test.com',
            'password' => 'password123',
            'first_name' => 'Admin',
            'last_name' => 'User',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        // Create test user
        $this->testUser = User::create([
            'role_uuid' => $this->customerRole->uuid,
            'email' => 'test@test.com',
            'password' => 'password123',
            'first_name' => 'Test',
            'last_name' => 'User',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        // Generate JWT token for admin user
        $this->adminToken = auth('admin')->login($this->adminUser);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanListUsers()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/users');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'uuid',
                        'email',
                        'first_name',
                        'last_name',
                    ],
                ],
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanShowASpecificUser()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/users/' . $this->testUser->uuid);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'uuid' => $this->testUser->uuid,
                    'email' => $this->testUser->email,
                    'first_name' => $this->testUser->first_name,
                    'last_name' => $this->testUser->last_name,
                ],
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanCreateANewUser()
    {
        $userData = [
            'email' => 'newuser@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'first_name' => 'New',
            'last_name' => 'User',
            'role_uuid' => $this->customerRole->uuid,
            'phone_number' => '09123456789',
            'birth_date' => '1990-01-01',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/users', $userData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'uuid',
                    'email',
                    'first_name',
                    'last_name',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'newuser@test.com',
            'first_name' => 'New',
            'last_name' => 'User',
            'role_uuid' => $this->customerRole->uuid,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesRequiredFieldsWhenCreatingUser()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/users', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password', 'first_name', 'last_name', 'role_uuid']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanUpdateAUser()
    {
        $updateData = [
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'phone_number' => '09987654321',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/v1/users/' . $this->testUser->uuid, $updateData);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'first_name' => 'Updated',
                    'last_name' => 'Name',
                    'phone_number' => '09987654321',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'uuid' => $this->testUser->uuid,
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'phone_number' => '09987654321',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanUpdateUserPassword()
    {
        $updateData = [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/v1/users/' . $this->testUser->uuid, $updateData);

        $response->assertStatus(200);

        // Verify password was updated by attempting to authenticate
        $this->testUser->refresh();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('newpassword123', $this->testUser->password));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturns404ForNonExistentUser()
    {
        $nonExistentUuid = '550e8400-e29b-41d4-a716-446655440000';

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/users/' . $nonExistentUuid);

        $response->assertStatus(404);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresAuthenticationForAllEndpoints()
    {
        // Test without token
        $response = $this->getJson('/api/v1/users');
        $response->assertStatus(401);

        $response = $this->postJson('/api/v1/users', []);
        $response->assertStatus(401);

        $response = $this->getJson('/api/v1/users/' . $this->testUser->uuid);
        $response->assertStatus(401);

        $response = $this->putJson('/api/v1/users/' . $this->testUser->uuid, []);
        $response->assertStatus(401);

        $response = $this->deleteJson('/api/v1/users/' . $this->testUser->uuid);
        $response->assertStatus(401);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesUniqueEmailOnCreation()
    {
        $userData = [
            'email' => $this->testUser->email, // Use existing email
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'first_name' => 'New',
            'last_name' => 'User',
            'role_uuid' => $this->customerRole->uuid,
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/users', $userData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanSearchUsers()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/users?search=Test');

        $response->assertStatus(200);

        $users = $response->json('data');
        $this->assertTrue(count($users) >= 1);

        // Check that the search result contains our test user
        $foundTestUser = collect($users)->first(function ($user) {
            return $user['uuid'] === $this->testUser->uuid;
        });

        $this->assertNotNull($foundTestUser);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanPaginateUsers()
    {
        // Create additional users for pagination test
        for ($i = 1; $i <= 20; $i++) {
            User::create([
                'role_uuid' => $this->customerRole->uuid,
                'email' => "user{$i}@test.com",
                'password' => 'password123',
                'first_name' => "User{$i}",
                'last_name' => 'Test',
                'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            ]);
        }

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/users?per_page=5');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'uuid',
                        'email',
                        'first_name',
                        'last_name',
                    ],
                ],
                'links',
                'meta' => [
                    'current_page',
                    'from',
                    'last_page',
                    'links',
                    'path',
                    'per_page',
                    'to',
                    'total',
                ]
            ]);

        $this->assertEquals(5, count($response->json('data')));
        $this->assertEquals(5, $response->json('meta.per_page'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsAffiliatePartnerStatsForUser()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/users/' . $this->testUser->uuid . '/affiliate-partner-stats');

        $response->assertStatus(200)
            ->assertJsonPath('data.user.uuid', $this->testUser->uuid)
            ->assertJsonStructure([
                'data' => [
                    'user' => [
                        'uuid',
                        'full_name',
                        'email',
                        'affiliate_code',
                        'affiliate_status',
                        'affiliate_applied_at',
                        'affiliate_approved_at',
                        'terms_accepted_at',
                        'affiliate_suspend_reason',
                        'affiliate_suspended_at',
                    ],
                    'stats' => [
                        'total_clicks',
                        'total_conversions',
                        'matured_commission_net',
                        'pending_earnings',
                        'paid_earnings',
                        'available_earnings',
                    ],
                    'bank_details' => [
                        'bank',
                        'branch',
                        'account_name',
                        'account_number',
                        'tin',
                    ],
                    'conversions' => [
                        'data',
                        'meta' => [
                            'current_page',
                            'last_page',
                            'per_page',
                            'total',
                        ],
                    ],
                ],
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanSuspendAndReinstateAffiliatePartner()
    {
        $partner = User::create([
            'role_uuid' => $this->customerRole->uuid,
            'email' => 'affiliate_suspend@test.com',
            'password' => 'password123',
            'first_name' => 'Aff',
            'last_name' => 'Partner',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
        $this->seedApprovedAffiliate($partner, 'SUSPTST1');

        $suspend = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->patchJson('/api/v1/users/' . $partner->uuid . '/affiliate-suspend', [
            'reason' => 'Test suspension reason for compliance review.',
        ]);

        $suspend->assertStatus(200)
            ->assertJsonPath('data.affiliate_status', GeneralConstants::AFFILIATE_STATUSES['SUSPENDED'])
            ->assertJsonPath('data.affiliate_suspend_reason', 'Test suspension reason for compliance review.');

        $this->assertDatabaseHas('user_affiliates', [
            'user_uuid' => $partner->uuid,
            'affiliate_status' => GeneralConstants::AFFILIATE_STATUSES['SUSPENDED'],
        ]);

        $reinstate = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->patchJson('/api/v1/users/' . $partner->uuid . '/affiliate-reinstate');

        $reinstate->assertStatus(200)
            ->assertJsonPath('data.affiliate_status', GeneralConstants::AFFILIATE_STATUSES['APPROVED'])
            ->assertJsonPath('data.affiliate_suspend_reason', null);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesReasonWhenSuspendingAffiliatePartner()
    {
        $partner = User::create([
            'role_uuid' => $this->customerRole->uuid,
            'email' => 'affiliate_suspend2@test.com',
            'password' => 'password123',
            'first_name' => 'Aff',
            'last_name' => 'Two',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
        $this->seedApprovedAffiliate($partner, 'SUSPTST2');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->patchJson('/api/v1/users/' . $partner->uuid . '/affiliate-suspend', [
            'reason' => 'ab',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }
}
