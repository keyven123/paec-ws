<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Role;
use App\Models\Venue;
use App\Models\Permission;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class VenueControllerTest extends TestCase
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
            'is_admin' => true,
        ]);

        // Create permissions
        $permission = Permission::create([
            'name' => 'Venues',
            'code' => 'venues',
            'available_access' => ['view', 'create', 'update', 'delete'],
        ]);

        // Assign permissions to admin role
        foreach (['view', 'create', 'update', 'delete'] as $access) {
            RolePermission::create([
                'role_uuid' => $this->adminRole->uuid,
                'permission_uuid' => $permission->uuid,
                'access' => 'venues-' . $access,
            ]);
        }

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

        // Generate JWT token for admin user
        $this->adminToken = auth('admin')->login($this->adminUser);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanListVenues()
    {
        $venue = Venue::create([
            'name' => 'Convention Center',
            'code' => 'CC001',
            'type' => Venue::TYPES['MUSICAL'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/venues');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'uuid',
                        'name',
                        'code',
                        'type',
                        'status',
                    ],
                ],
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanCreateAVenue()
    {
        $venueData = [
            'name' => 'Grand Hotel Ballroom',
            'type' => Venue::TYPES['MUSICAL'],
            'image_uuid' => '550e8400-e29b-41d4-a716-446655440001',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/venues', $venueData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'uuid',
                    'name',
                    'code',
                    'type',
                    'image_uuid',
                    'status',
                ],
            ]);

        $this->assertDatabaseHas('venues', [
            'name' => 'Grand Hotel Ballroom',
            'type' => Venue::TYPES['MUSICAL'],
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanShowAVenue()
    {
        $venue = Venue::create([
            'name' => 'Sports Arena',
            'code' => 'SA001',
            'type' => Venue::TYPES['SPORT'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/venues/' . $venue->uuid);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'uuid' => $venue->uuid,
                    'name' => $venue->name,
                    'code' => $venue->code,
                    'type' => $venue->type,
                ],
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanUpdateAVenue()
    {
        $venue = Venue::create([
            'name' => 'Community Center',
            'code' => 'CC001',
            'type' => Venue::TYPES['MUSICAL'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $updateData = [
            'name' => 'Updated Community Center',
            'type' => Venue::TYPES['MUSICAL'],
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/v1/venues/' . $venue->uuid, $updateData);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'name' => 'Updated Community Center',
                    'type' => Venue::TYPES['MUSICAL'],
                ],
            ]);

        $this->assertDatabaseHas('venues', [
            'uuid' => $venue->uuid,
            'name' => 'Updated Community Center',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanDeleteAVenue()
    {
        $venue = Venue::create([
            'name' => 'Temporary Venue',
            'code' => 'TV001',
            'type' => Venue::TYPES['MUSICAL'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->deleteJson('/api/v1/venues/' . $venue->uuid);

        $response->assertStatus(204);

        $this->assertSoftDeleted('venues', [
            'uuid' => $venue->uuid,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesVenueCreationData()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/venues', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'type']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesUniqueNameOnCreation()
    {
        Venue::create([
            'name' => 'First Venue',
            'code' => 'FV001',
            'type' => Venue::TYPES['MUSICAL'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/venues', [
            'name' => 'First Venue',
            'type' => Venue::TYPES['MUSICAL'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturns404ForNonExistentVenue()
    {
        $nonExistentUuid = '550e8400-e29b-41d4-a716-446655440000';

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/venues/' . $nonExistentUuid);

        $response->assertStatus(404);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresAuthenticationForAllEndpoints()
    {
        $venue = Venue::create([
            'name' => 'Test Venue',
            'code' => 'TEST001',
            'type' => Venue::TYPES['MUSICAL'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        // Test without token
        $response = $this->getJson('/api/v1/venues');
        $response->assertStatus(401);

        $response = $this->postJson('/api/v1/venues', []);
        $response->assertStatus(401);

        $response = $this->getJson('/api/v1/venues/' . $venue->uuid);
        $response->assertStatus(401);

        $response = $this->putJson('/api/v1/venues/' . $venue->uuid, []);
        $response->assertStatus(401);

        $response = $this->deleteJson('/api/v1/venues/' . $venue->uuid);
        $response->assertStatus(401);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanFilterVenuesByType()
    {
        Venue::create([
            'name' => 'Hotel Venue',
            'code' => 'HV001',
            'type' => Venue::TYPES['MUSICAL'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        Venue::create([
            'name' => 'Conference Venue',
            'code' => 'CV001',
            'type' => Venue::TYPES['THEATRE'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/venues?type=' . Venue::TYPES['MUSICAL']);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanSearchVenues()
    {
        Venue::create([
            'name' => 'Grand Convention Center',
            'code' => 'GCC001',
            'type' => Venue::TYPES['MUSICAL'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        Venue::create([
            'name' => 'Small Meeting Room',
            'code' => 'SMR001',
            'type' => Venue::TYPES['MUSICAL'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/venues?q=grand&type=' . Venue::TYPES['MUSICAL']);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }
}
