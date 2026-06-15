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

class AuthAdminControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private AdminUser $adminUser;
    private Role $adminRole;
    private Role $customerRole;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles
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
            'name' => 'Dashboard',
            'code' => 'dashboard',
            'available_access' => ['view'],
        ]);

        // Assign permissions to admin role
        RolePermission::create([
            'role_uuid' => $this->adminRole->uuid,
            'permission_uuid' => $permission->uuid,
            'access' => 'dashboard-view',
        ]);

        // Create admin user
        $this->adminUser = AdminUser::create([
            'role_uuid' => $this->adminRole->uuid,
            'email' => 'admin@test.com',
            'password' => 'password123',
            'first_name' => 'Admin',
            'last_name' => 'User',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanLoginAdminUser()
    {
        $loginData = [
            'email' => 'admin@test.com',
            'password' => 'password123',
        ];

        $response = $this->postJson('/api/v1/admin/login', $loginData);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'expires_in',
                'admin_user',
                'role',
                'permissions',
            ])
            ->assertJson([
                'token_type' => 'Bearer',
            ]);

        // Check that last_login_at was updated
        $this->adminUser->refresh();
        $this->assertNotNull($this->adminUser->last_login_at);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCannotLoginWithInvalidCredentials()
    {
        $loginData = [
            'email' => 'admin@test.com',
            'password' => 'wrongpassword',
        ];

        $response = $this->postJson('/api/v1/admin/login', $loginData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCannotLoginWithUnverifiedEmail()
    {
        // Create admin user with unverified email
        $unverifiedAdmin = AdminUser::create([
            'role_uuid' => $this->adminRole->uuid,
            'email' => 'unverified@test.com',
            'password' => 'password123',
            'first_name' => 'Unverified',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => null, // Not verified
        ]);

        $loginData = [
            'email' => 'unverified@test.com',
            'password' => 'password123',
        ];

        $response = $this->postJson('/api/v1/admin/login', $loginData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCannotLoginCustomerUserThroughAdminAuth()
    {
        // Create customer user (should not be able to login through admin auth)
        $customerUser = AdminUser::create([
            'role_uuid' => $this->customerRole->uuid,
            'email' => 'customer@test.com',
            'password' => 'password123',
            'first_name' => 'Customer',
            'last_name' => 'User',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $loginData = [
            'email' => 'customer@test.com',
            'password' => 'password123',
        ];

        $response = $this->postJson('/api/v1/admin/login', $loginData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanGetAuthenticatedAdminUserInfo()
    {
        $token = auth('admin')->login($this->adminUser);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/v1/admin/me');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'admin_user' => [
                        'uuid',
                        'email',
                        'first_name',
                        'last_name',
                    ],
                    'role',
                    'permissions',
                    'role_permissions',
                ],
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'admin_user' => [
                        'email' => 'admin@test.com',
                    ],
                ],
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanRefreshAdminToken()
    {
        $token = auth('admin')->login($this->adminUser);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/v1/admin/refresh');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'expires_in',
                'admin_user',
            ])
            ->assertJson([
                'token_type' => 'Bearer',
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanLogoutAdminUser()
    {
        $token = auth('admin')->login($this->adminUser);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/v1/admin/logout');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Admin logged out successfully',
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanChangeAdminPassword()
    {
        $token = auth('admin')->login($this->adminUser);

        $passwordData = [
            'current_password' => 'password123',
            'new_password' => 'newpassword123',
            'new_password_confirmation' => 'newpassword123',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/v1/admin/change-password', $passwordData);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Password changed successfully',
            ]);

        // Verify password was changed
        $this->adminUser->refresh();
        $this->assertTrue(\Hash::check('newpassword123', $this->adminUser->password));
        $this->assertFalse($this->adminUser->is_first_time_login);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCannotChangePasswordWithWrongCurrentPassword()
    {
        $token = auth('admin')->login($this->adminUser);

        $passwordData = [
            'current_password' => 'wrongpassword',
            'new_password' => 'newpassword123',
            'new_password_confirmation' => 'newpassword123',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/v1/admin/change-password', $passwordData);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'Current password is incorrect',
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanUpdateAdminProfile()
    {
        $token = auth('admin')->login($this->adminUser);

        $profileData = [
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'phone_number' => '1234567890',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->putJson('/api/v1/admin/profile', $profileData);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => [
                    'first_name' => 'Updated',
                    'last_name' => 'Name',
                    'phone_number' => '1234567890',
                ],
            ]);

        $this->assertDatabaseHas('admin_users', [
            'uuid' => $this->adminUser->uuid,
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'phone_number' => '1234567890',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanGetDashboardStats()
    {
        $token = auth('admin')->login($this->adminUser);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/v1/admin/dashboard-stats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_admin_users',
                    'active_admin_users',
                    'super_admins',
                    'admins',
                    'organizers',
                ],
            ])
            ->assertJson([
                'success' => true,
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresAuthenticationForProtectedEndpoints()
    {
        // Test without token
        $response = $this->getJson('/api/v1/admin/me');
        $response->assertStatus(401);

        $response = $this->postJson('/api/v1/admin/refresh');
        $response->assertStatus(401);

        $response = $this->postJson('/api/v1/admin/logout');
        $response->assertStatus(401);

        $response = $this->postJson('/api/v1/admin/change-password', []);
        $response->assertStatus(401);

        $response = $this->putJson('/api/v1/admin/profile', []);
        $response->assertStatus(401);

        $response = $this->getJson('/api/v1/admin/dashboard-stats');
        $response->assertStatus(401);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesPasswordChangeRequest()
    {
        $token = auth('admin')->login($this->adminUser);

        // Test missing fields
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/v1/admin/change-password', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['current_password', 'new_password']);

        // Test password confirmation mismatch
        $passwordData = [
            'current_password' => 'password123',
            'new_password' => 'newpassword123',
            'new_password_confirmation' => 'differentpassword',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/v1/admin/change-password', $passwordData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['new_password']);
    }
}
