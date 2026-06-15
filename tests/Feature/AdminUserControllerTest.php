<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\Role;
use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class AdminUserControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private AdminUser $superAdminUser;
    private AdminUser $adminUser;
    private Role $superAdminRole;
    private Role $adminRole;
    private Role $customerRole;
    private string $superAdminToken;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles
        $this->superAdminRole = Role::create([
            'name' => 'Super Admin',
            'code' => GeneralConstants::ROLES['SUPER_ADMIN']['name'],
        ]);

        $this->adminRole = Role::create([
            'name' => 'Admin',
            'code' => GeneralConstants::ROLES['ADMIN']['name'],
        ]);

        $this->customerRole = Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);

        // Create permissions
        $permission = Permission::create([
            'name' => 'Admin Users',
            'code' => 'admin-users',
            'available_access' => ['view', 'create', 'update', 'delete'],
        ]);

        // Assign permissions to super admin role
        foreach (['view', 'create', 'update', 'delete'] as $access) {
            RolePermission::create([
                'role_uuid' => $this->superAdminRole->uuid,
                'permission_uuid' => $permission->uuid,
                'access' => 'admin-users-' . $access,
            ]);
        }

        // Create super admin user
        $this->superAdminUser = AdminUser::create([
            'role_uuid' => $this->superAdminRole->uuid,
            'email' => 'superadmin@test.com',
            'password' => 'password123',
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        // Create regular admin user
        $this->adminUser = AdminUser::create([
            'role_uuid' => $this->adminRole->uuid,
            'email' => 'admin@test.com',
            'password' => 'password123',
            'first_name' => 'Regular',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        // Generate JWT token for super admin user
        $this->superAdminToken = auth('admin')->login($this->superAdminUser);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanListAdminUsers()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->superAdminToken,
        ])->getJson('/api/v1/admin-users');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'uuid',
                        'email',
                        'first_name',
                        'last_name',
                        'full_name',
                        'status',
                    ],
                ],
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanCreateAnAdminUser()
    {
        $adminUserData = [
            'role_uuid' => $this->adminRole->uuid,
            'email' => 'newadmin@test.com',
            'password' => 'password123',
            'first_name' => 'New',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->superAdminToken,
        ])->postJson('/api/v1/admin-users', $adminUserData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'uuid',
                    'email',
                    'first_name',
                    'last_name',
                    'role',
                ],
            ]);

        $this->assertDatabaseHas('admin_users', [
            'email' => 'newadmin@test.com',
            'first_name' => 'New',
            'last_name' => 'Admin',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCannotCreateAdminUserWithCustomerRole()
    {
        $adminUserData = [
            'role_uuid' => $this->customerRole->uuid,
            'email' => 'customer@test.com',
            'password' => 'password123',
            'first_name' => 'Customer',
            'last_name' => 'User',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->superAdminToken,
        ])->postJson('/api/v1/admin-users', $adminUserData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['role_uuid']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanShowAnAdminUser()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->superAdminToken,
        ])->getJson('/api/v1/admin-users/' . $this->adminUser->uuid);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'uuid' => $this->adminUser->uuid,
                    'email' => $this->adminUser->email,
                    'first_name' => $this->adminUser->first_name,
                    'last_name' => $this->adminUser->last_name,
                ],
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanUpdateAnAdminUser()
    {
        $updateData = [
            'first_name' => 'Updated',
            'last_name' => 'Admin',
            'phone_number' => '1234567890',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->superAdminToken,
        ])->putJson('/api/v1/admin-users/' . $this->adminUser->uuid, $updateData);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'first_name' => 'Updated',
                    'last_name' => 'Admin',
                    'phone_number' => '1234567890',
                ],
            ]);

        $this->assertDatabaseHas('admin_users', [
            'uuid' => $this->adminUser->uuid,
            'first_name' => 'Updated',
            'last_name' => 'Admin',
            'phone_number' => '1234567890',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanDeleteAnAdminUser()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->superAdminToken,
        ])->deleteJson('/api/v1/admin-users/' . $this->adminUser->uuid);

        $response->assertStatus(204);

        $this->assertSoftDeleted('admin_users', [
            'uuid' => $this->adminUser->uuid,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCannotDeleteSuperAdminUser()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->superAdminToken,
        ])->deleteJson('/api/v1/admin-users/' . $this->superAdminUser->uuid);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Cannot delete super admin user.',
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturns404ForNonExistentAdminUser()
    {
        $nonExistentUuid = '550e8400-e29b-41d4-a716-446655440000';

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->superAdminToken,
        ])->getJson('/api/v1/admin-users/' . $nonExistentUuid);

        $response->assertStatus(404);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanGetAvailableRoles()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->superAdminToken,
        ])->getJson('/api/v1/admin-users/available-roles');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'uuid',
                        'name',
                        'code',
                    ],
                ],
            ]);

        // Should not include customer role
        $response->assertJsonMissing([
            'code' => GeneralConstants::ROLES['CUSTOMER']['name']
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresAuthenticationForAllEndpoints()
    {
        // Clear any existing authentication state
        auth('admin')->logout();

        // Test without token
        $response = $this->getJson('/api/v1/admin-users');
        $response->assertStatus(401);

        $response = $this->postJson('/api/v1/admin-users', []);
        $response->assertStatus(401);

        $response = $this->getJson('/api/v1/admin-users/' . $this->adminUser->uuid);
        $response->assertStatus(401);

        $response = $this->putJson('/api/v1/admin-users/' . $this->adminUser->uuid, []);
        $response->assertStatus(401);

        $response = $this->deleteJson('/api/v1/admin-users/' . $this->adminUser->uuid);
        $response->assertStatus(401);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesEmailUniqueness()
    {
        $adminUserData = [
            'role_uuid' => $this->adminRole->uuid,
            'email' => $this->adminUser->email, // Use existing email
            'password' => 'password123',
            'first_name' => 'Duplicate',
            'last_name' => 'Email',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->superAdminToken,
        ])->postJson('/api/v1/admin-users', $adminUserData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }
}
