<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\Role;
use App\Models\AdminUser;
use App\Models\Venue;
use App\Models\VenueSeat;
use App\Models\Permission;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class VenueSeatControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private AdminUser $adminUser;
    private Role $adminRole;
    private Venue $testVenue;
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
            'name' => 'Venue Seats',
            'code' => 'venue-seats',
            'available_access' => ['view', 'create', 'edit', 'delete'],
        ]);

        // Assign permissions to admin role
        foreach (['view', 'create', 'edit', 'delete'] as $access) {
            RolePermission::create([
                'role_uuid' => $this->adminRole->uuid,
                'permission_uuid' => $permission->uuid,
                'access' => 'venue-seats-' . $access,
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

        // Create test venue
        $this->testVenue = Venue::create([
            'name' => 'Test Venue',
            'code' => 'TEST001',
            'type' => 'indoor',
            'address' => 'Test Address',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        // Generate JWT token for admin user
        $this->adminToken = auth('admin')->login($this->adminUser);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanListVenueSeats()
    {
        $venueSeat = VenueSeat::create([
            'venue_uuid' => $this->testVenue->uuid,
            'col' => 'A',
            'row' => 1,
            'seat_no' => 1,
            'category' => 'bronze',
            'color' => 'bronze',
            'status' => 'active',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/venue-seats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'uuid',
                        'venue_uuid',
                        'col',
                        'row',
                        'seat_no',
                        'category',
                        'color',
                        'status',
                    ],
                ],
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanCreateAVenueSeat()
    {
        $venueSeatData = [
            'venue_uuid' => $this->testVenue->uuid,
            'col' => 'A',
            'row' => 1,
            'seat_no' => 1,
            'category' => 'bronze',
            'color' => 'bronze',
            'status' => 'active',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/venue-seats', $venueSeatData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'uuid',
                    'venue_uuid',
                    'col',
                    'row',
                    'seat_no',
                    'category',
                    'color',
                    'status',
                ],
            ]);

        $this->assertDatabaseHas('venue_seats', [
            'venue_uuid' => $this->testVenue->uuid,
            'col' => 'A',
            'row' => 1,
            'seat_no' => 1,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresAuthenticationForAllEndpoints()
    {
        $venueSeat = VenueSeat::create([
            'venue_uuid' => $this->testVenue->uuid,
            'col' => 'A',
            'row' => 1,
            'seat_no' => 1,
            'category' => 'bronze',
            'color' => 'bronze',
        ]);

        // Test without token
        $response = $this->getJson('/api/v1/venue-seats');
        $response->assertStatus(401);

        $response = $this->postJson('/api/v1/venue-seats', []);
        $response->assertStatus(401);
    }
}
