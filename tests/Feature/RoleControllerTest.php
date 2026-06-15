<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\Permission;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class RoleControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private AdminUser $adminUser;
    private User $customerUser;
    private Role $adminRole;
    private Role $customerRole;
    private Role $testRole;
    private string $adminToken;
    private string $customerToken;
    private Permission $permission1;
    private Permission $permission2;
    private Permission $permission3;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::create([
            'business_type' => Organization::BUSINESS_TYPE_SOLE_PROPRIETORSHIP,
            'name' => 'Role Test Org',
            'representative_first_name' => 'Role',
            'representative_last_name' => 'Test',
            'address' => '123 Test Street',
            'contact_number' => '09171234567',
            'email' => 'role-controller@test.com',
            'description' => 'Role controller test organization',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ]);

        // Create roles
        $this->adminRole = Role::create([
            'organization_uuid' => $organization->uuid,
            'name' => 'Admin',
            'code' => GeneralConstants::ROLES['ADMIN']['name'],
            'is_admin' => true,
        ]);

        $this->customerRole = Role::create([
            'organization_uuid' => $organization->uuid,
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);

        $this->testRole = Role::create([
            'organization_uuid' => $organization->uuid,
            'name' => 'Test Role',
            'code' => 'test-role',
        ]);

        // Create permissions
        $this->permission1 = Permission::create([
            'name' => 'Dashboard View',
            'code' => 'dashboard',
            'available_access' => ['view'],
        ]);

        $this->permission2 = Permission::create([
            'name' => 'Users Management',
            'code' => 'users',
            'available_access' => ['view', 'create', 'update', 'delete'],
        ]);

        $this->permission3 = Permission::create([
            'name' => 'Reports Access',
            'code' => 'reports',
            'available_access' => ['view', 'export'],
        ]);

        // Assign permissions to admin role
        foreach ([$this->permission1, $this->permission2, $this->permission3] as $permission) {
            RolePermission::create([
                'role_uuid' => $this->adminRole->uuid,
                'permission_uuid' => $permission->uuid,
                'access' => true,
            ]);
        }

        // Add role management permissions to admin
        $rolePermission = Permission::create([
            'name' => 'Role Management',
            'code' => 'roles',
            'available_access' => ['view', 'create', 'update', 'delete'],
        ]);

        foreach (['view', 'create', 'update', 'delete'] as $access) {
            RolePermission::create([
                'role_uuid' => $this->adminRole->uuid,
                'permission_uuid' => $rolePermission->uuid,
                'access' => 'roles-' . $access,
            ]);
        }

        // Assign some permissions to test role
        RolePermission::create([
            'role_uuid' => $this->testRole->uuid,
            'permission_uuid' => $this->permission1->uuid,
            'access' => true,
        ]);

        // Create users
        $this->adminUser = AdminUser::create([
            'role_uuid' => $this->adminRole->uuid,
            'email' => 'admin@test.com',
            'password' => 'password123',
            'first_name' => 'Regular',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $this->customerUser = User::create([
            'role_uuid' => $this->customerRole->uuid,
            'email' => 'customer@test.com',
            'password' => 'password123',
            'first_name' => 'Customer',
            'last_name' => 'User',
            'email_verified_at' => now(),
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        // Generate JWT tokens
        $this->adminToken = auth('admin')->login($this->adminUser);
        $this->customerToken = JWTAuth::fromUser($this->customerUser);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanListAllRoles()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/roles');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'uuid',
                        'name',
                        'code',
                        'permissions' => [
                            '*' => [
                                'uuid',
                                'name',
                                'code',
                            ],
                        ],
                    ],
                ],
            ]);

        $data = $response->json('data');
        $this->assertCount(3, $data); // admin, customer, test-role

        // Check if our created roles are in the response
        $roleCodes = collect($data)->pluck('code')->toArray();
        $this->assertContains('admin', $roleCodes);
        $this->assertContains('customer', $roleCodes);
        $this->assertContains('test-role', $roleCodes);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanCreateANewRole()
    {
        $roleData = [
            'name' => 'New Test Role',
            'code' => 'new-test-role',
            'permissions' => [$this->permission1->uuid, $this->permission2->uuid],
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/roles', $roleData);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Role created successfully',
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'uuid',
                    'name',
                    'code',
                    'permissions',
                ],
            ]);

        $this->assertDatabaseHas('roles', [
            'name' => 'New Test Role',
            'code' => 'new-test-role',
        ]);

        // Check if permissions were assigned
        $role = Role::where('code', 'new-test-role')->first();
        $this->assertDatabaseHas('role_permissions', [
            'role_uuid' => $role->uuid,
            'permission_uuid' => $this->permission1->uuid,
        ]);
        $this->assertDatabaseHas('role_permissions', [
            'role_uuid' => $role->uuid,
            'permission_uuid' => $this->permission2->uuid,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanCreateRoleWithoutPermissions()
    {
        $roleData = [
            'name' => 'Role Without Permissions',
            'code' => 'no-permissions-role',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/roles', $roleData);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Role created successfully',
            ]);

        $this->assertDatabaseHas('roles', [
            'name' => 'Role Without Permissions',
            'code' => 'no-permissions-role',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesRoleCreationData()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/roles', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'code']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itPreventsCreatingRoleWithDuplicateCode()
    {
        $roleData = [
            'name' => 'Duplicate Code Role',
            'code' => 'admin', // Use existing code
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/roles', $roleData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanShowSpecificRole()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/roles/' . $this->testRole->uuid);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'uuid' => $this->testRole->uuid,
                    'name' => $this->testRole->name,
                    'code' => $this->testRole->code,
                ],
            ])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'uuid',
                    'name',
                    'code',
                    'permissions',
                ],
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturns404ForNonExistentRole()
    {
        $nonExistentUuid = '550e8400-e29b-41d4-a716-446655440000';

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/roles/' . $nonExistentUuid);

        $response->assertStatus(404);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanUpdateRole()
    {
        $updateData = [
            'uuid' => $this->testRole->uuid,
            'name' => 'Updated Test Role',
            'code' => 'updated-test-role',
            'permissions' => [$this->permission2->uuid, $this->permission3->uuid],
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/v1/roles/' . $this->testRole->uuid, $updateData);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Role updated successfully',
                'data' => [
                    'name' => 'Updated Test Role',
                    'code' => 'updated-test-role',
                ],
            ]);

        $this->assertDatabaseHas('roles', [
            'uuid' => $this->testRole->uuid,
            'name' => 'Updated Test Role',
            'code' => 'updated-test-role',
        ]);

        // Check if permissions were updated
        $this->assertDatabaseMissing('role_permissions', [
            'role_uuid' => $this->testRole->uuid,
            'permission_uuid' => $this->permission1->uuid,
        ]);
        $this->assertDatabaseHas('role_permissions', [
            'role_uuid' => $this->testRole->uuid,
            'permission_uuid' => $this->permission2->uuid,
        ]);
        $this->assertDatabaseHas('role_permissions', [
            'role_uuid' => $this->testRole->uuid,
            'permission_uuid' => $this->permission3->uuid,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanDeleteRole()
    {
        // Create a role without users
        $roleToDelete = Role::create([
            'organization_uuid' => $this->adminRole->organization_uuid,
            'name' => 'Role to Delete',
            'code' => 'delete-me',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->deleteJson('/api/v1/roles/' . $roleToDelete->uuid);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Role deleted successfully',
            ]);

        $this->assertDatabaseMissing('roles', [
            'uuid' => $roleToDelete->uuid,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCannotDeleteRoleWithUsers()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->deleteJson('/api/v1/roles/' . $this->adminRole->uuid);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'Cannot delete role that is assigned to users',
            ]);

        $this->assertDatabaseHas('roles', [
            'uuid' => $this->adminRole->uuid,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanAssignPermissionsToRole()
    {
        $permissionData = [
            'permissions' => [$this->permission2->uuid, $this->permission3->uuid],
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/roles/' . $this->testRole->uuid . '/permissions', $permissionData);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Permissions assigned successfully',
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'uuid',
                    'name',
                    'code',
                    'permissions',
                ],
            ]);

        // Check if old permissions were removed and new ones added
        $this->assertDatabaseMissing('role_permissions', [
            'role_uuid' => $this->testRole->uuid,
            'permission_uuid' => $this->permission1->uuid,
        ]);
        $this->assertDatabaseHas('role_permissions', [
            'role_uuid' => $this->testRole->uuid,
            'permission_uuid' => $this->permission2->uuid,
        ]);
        $this->assertDatabaseHas('role_permissions', [
            'role_uuid' => $this->testRole->uuid,
            'permission_uuid' => $this->permission3->uuid,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesPermissionAssignmentData()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/roles/' . $this->testRole->uuid . '/permissions', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['permissions']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesPermissionUuidsInAssignment()
    {
        $permissionData = [
            'permissions' => ['invalid-uuid', 'another-invalid-uuid'],
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/roles/' . $this->testRole->uuid . '/permissions', $permissionData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['permissions.0', 'permissions.1']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresAuthenticationForAllEndpoints()
    {
        // Test without token
        $response = $this->getJson('/api/v1/roles');
        $response->assertStatus(401);

        $response = $this->postJson('/api/v1/roles', []);
        $response->assertStatus(401);

        $response = $this->getJson('/api/v1/roles/' . $this->testRole->uuid);
        $response->assertStatus(401);

        $response = $this->putJson('/api/v1/roles/' . $this->testRole->uuid, []);
        $response->assertStatus(401);

        $response = $this->deleteJson('/api/v1/roles/' . $this->testRole->uuid);
        $response->assertStatus(401);

        $response = $this->postJson('/api/v1/roles/' . $this->testRole->uuid . '/permissions', []);
        $response->assertStatus(401);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itHandlesInvalidUuidInRoutes()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/roles/invalid-uuid');

        $response->assertStatus(404);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itMaintainsConsistentResponseFormat()
    {
        // Test index endpoint
        $indexResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/roles');

        $this->assertArrayHasKey('success', $indexResponse->json());
        $this->assertArrayHasKey('data', $indexResponse->json());
        $this->assertTrue($indexResponse->json('success'));

        // Test show endpoint
        $showResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/roles/' . $this->testRole->uuid);

        $this->assertArrayHasKey('success', $showResponse->json());
        $this->assertArrayHasKey('data', $showResponse->json());
        $this->assertTrue($showResponse->json('success'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itIncludesPermissionsInRoleResponses()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/roles/' . $this->adminRole->uuid);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertArrayHasKey('permissions', $data);
        $this->assertIsArray($data['permissions']);
        $this->assertGreaterThanOrEqual(4, count($data['permissions'])); // admin role has at least 4 permissions

        foreach ($data['permissions'] as $permission) {
            $this->assertArrayHasKey('uuid', $permission);
            $this->assertArrayHasKey('name', $permission);
            $this->assertArrayHasKey('code', $permission);
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itHandlesEmptyPermissionsArrayInUpdate()
    {
        $updateData = [
            'uuid' => $this->testRole->uuid,
            'name' => 'Updated Role',
            'code' => 'updated-role',
            'permissions' => [],
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/v1/roles/' . $this->testRole->uuid, $updateData);

        $response->assertStatus(200);

        // All permissions should be removed
        $this->assertDatabaseMissing('role_permissions', [
            'role_uuid' => $this->testRole->uuid,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itSetsCorrectUserIdsOnCreationAndUpdate()
    {
        // Test creation
        $roleData = [
            'name' => 'User ID Test Role',
            'code' => 'user-id-test',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/roles', $roleData);

        $response->assertStatus(201);

        $role = Role::where('code', $roleData['code'])->first();
        $this->assertEquals($this->adminUser->uuid, $role->created_by);
        $this->assertEquals($this->adminUser->uuid, $role->updated_by);
        // Test update
        $updateData = [
            'uuid' => $role->uuid,
            'name' => 'Updated User ID Test Role',
            'code' => 'updated-user-id-test',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/v1/roles/' . $role->uuid, $updateData);

        $response->assertStatus(200);

        $role->refresh();
        $this->assertEquals($this->adminUser->uuid, $role->updated_by);
    }
}
