<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Role;
use App\Models\Category;
use App\Models\Permission;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class CategoryControllerTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    private AdminUser $adminUser;
    private Role $adminRole;
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        // Create admin role
        $this->adminRole = Role::create([
            'name' => 'Admin',
            'code' => GeneralConstants::ROLES['ADMIN']['name'],
        ]);

        // Create permissions
        $permission = Permission::create([
            'name' => 'Categories',
            'code' => 'categories',
            'available_access' => ['view', 'create', 'update', 'delete'],
        ]);

        // Assign permissions to admin role
        foreach (['view', 'create', 'update', 'delete'] as $access) {
            RolePermission::create([
                'role_uuid' => $this->adminRole->uuid,
                'permission_uuid' => $permission->uuid,
                'access' => 'categories-' . $access,
            ]);
        }

        // Create admin user
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
        $this->adminToken = auth('admin')->login($this->adminUser);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanListCategories()
    {
        $category = Category::create([
            'name' => Category::CATEGORIES['CONFERENCE'],
            'code' => Category::CATEGORY_CODES['CONFERENCE'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/categories');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'uuid',
                        'name',
                        'code',
                        'status',
                    ],
                ],
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanCreateACategory()
    {
        $categoryData = [
            'name' => Category::CATEGORIES['WORKSHOP'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/categories', $categoryData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'uuid',
                    'name',
                    'code',
                    'status',
                ],
            ]);

        $this->assertDatabaseHas('categories', [
            'name' => Category::CATEGORIES['WORKSHOP'],
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanShowACategory()
    {
        $category = Category::create([
            'name' => Category::CATEGORIES['SEMINAR'],
            'code' => Category::CATEGORY_CODES['CONFERENCE'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/categories/' . $category->uuid);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'uuid' => $category->uuid,
                    'name' => $category->name,
                    'code' => $category->code,
                ],
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanUpdateACategory()
    {
        $category = Category::create([
            'name' => Category::CATEGORIES['NETWORKING'],
            'code' => Category::CATEGORY_CODES['CONFERENCE'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $updateData = [
            'name' => Category::CATEGORIES['CONCERT'],
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/v1/categories/' . $category->uuid, $updateData);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'name' => Category::CATEGORIES['CONCERT'],
                ],
            ]);

        $this->assertDatabaseHas('categories', [
            'uuid' => $category->uuid,
            'name' => Category::CATEGORIES['CONCERT'],
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanDeleteACategory()
    {
        $category = Category::create([
            'name' => Category::CATEGORIES['FESTIVAL'],
            'code' => Category::CATEGORY_CODES['FESTIVAL'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->deleteJson('/api/v1/categories/' . $category->uuid);

        $response->assertStatus(204);

        $this->assertSoftDeleted('categories', [
            'uuid' => $category->uuid,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesCategoryCreationData()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/categories', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesUniqueCodeOnCreation()
    {
        Category::create([
            'name' => Category::CATEGORIES['SPORTS'],
            'code' => Category::CATEGORY_CODES['SPORTS'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/categories', [
            'name' => Category::CATEGORIES['SPORTS'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturns404ForNonExistentCategory()
    {
        $nonExistentUuid = '550e8400-e29b-41d4-a716-446655440000';

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/categories/' . $nonExistentUuid);

        $response->assertStatus(404);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresAuthenticationForAllEndpoints()
    {
        $category = Category::create([
            'name' => Category::CATEGORIES['OTHERS'],
            'code' => Category::CATEGORY_CODES['OTHERS'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        // Test without token
        $response = $this->getJson('/api/v1/categories');
        $response->assertStatus(401);

        $response = $this->postJson('/api/v1/categories', []);
        $response->assertStatus(401);

        $response = $this->getJson('/api/v1/categories/' . $category->uuid);
        $response->assertStatus(401);

        $response = $this->putJson('/api/v1/categories/' . $category->uuid, []);
        $response->assertStatus(401);

        $response = $this->deleteJson('/api/v1/categories/' . $category->uuid);
        $response->assertStatus(401);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanFilterCategoriesByCode()
    {
        Category::create([
            'name' => Category::CATEGORIES['CONFERENCE'],
            'code' => Category::CATEGORY_CODES['CONFERENCE'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        Category::create([
            'name' => Category::CATEGORIES['WORKSHOP'],
            'code' => Category::CATEGORY_CODES['WORKSHOP'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/categories?code=' . Category::CATEGORY_CODES['CONFERENCE']);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }
}
