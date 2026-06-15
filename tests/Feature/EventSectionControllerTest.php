<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Role;
use App\Models\EventSection;
use App\Models\Permission;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class EventSectionControllerTest extends TestCase
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
            'name' => 'Event Sections',
            'code' => 'event-sections',
            'available_access' => ['view', 'create', 'update', 'delete'],
        ]);

        // Assign permissions to admin role
        foreach (['view', 'create', 'update', 'delete'] as $access) {
            RolePermission::create([
                'role_uuid' => $this->adminRole->uuid,
                'permission_uuid' => $permission->uuid,
                'access' => 'event-sections-' . $access,
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

        // Generate JWT token for admin user
        $this->adminToken = auth('admin')->login($this->adminUser);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanListEventSections()
    {
        $eventSection = EventSection::create([
            'name' => 'Main Hall',
            'title' => 'Main Conference Hall',
            'description' => 'The primary venue for keynote presentations',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/event-sections');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'uuid',
                        'name',
                        'title',
                        'description',
                        'status',
                    ],
                ],
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanCreateAnEventSection()
    {
        $eventSectionData = [
            'name' => 'Workshop Room A',
            'title' => 'Interactive Workshop Space A',
            'description' => 'Dedicated space for hands-on workshops and breakout sessions',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/event-sections', $eventSectionData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'uuid',
                    'name',
                    'title',
                    'description',
                    'status',
                ],
            ]);

        $this->assertDatabaseHas('event_sections', [
            'name' => 'Workshop Room A',
            'title' => 'Interactive Workshop Space A',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanShowAnEventSection()
    {
        $eventSection = EventSection::create([
            'name' => 'Exhibition Hall',
            'title' => 'Product Exhibition Hall',
            'description' => 'Large space for vendor booths and product demonstrations',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/event-sections/' . $eventSection->uuid);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'uuid' => $eventSection->uuid,
                    'name' => $eventSection->name,
                    'title' => $eventSection->title,
                    'description' => $eventSection->description,
                ],
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanUpdateAnEventSection()
    {
        $eventSection = EventSection::create([
            'name' => 'Meeting Room',
            'title' => 'Small Meeting Room',
            'description' => 'Intimate space for small group discussions',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $updateData = [
            'name' => 'Conference Room',
            'title' => 'Executive Conference Room',
            'description' => 'Premium space for executive meetings and VIP sessions',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/v1/event-sections/' . $eventSection->uuid, $updateData);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'name' => 'Conference Room',
                    'title' => 'Executive Conference Room',
                    'description' => 'Premium space for executive meetings and VIP sessions',
                ],
            ]);

        $this->assertDatabaseHas('event_sections', [
            'uuid' => $eventSection->uuid,
            'name' => 'Conference Room',
            'title' => 'Executive Conference Room',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanDeleteAnEventSection()
    {
        $eventSection = EventSection::create([
            'name' => 'Storage Room',
            'title' => 'Equipment Storage',
            'description' => 'Storage space for event equipment',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->deleteJson('/api/v1/event-sections/' . $eventSection->uuid);

        $response->assertStatus(204);

        $this->assertSoftDeleted('event_sections', [
            'uuid' => $eventSection->uuid,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesEventSectionCreationData()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/event-sections', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'title']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturns404ForNonExistentEventSection()
    {
        $nonExistentUuid = '550e8400-e29b-41d4-a716-446655440000';

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/event-sections/' . $nonExistentUuid);

        $response->assertStatus(404);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresAuthenticationForAllEndpoints()
    {
        $eventSection = EventSection::create([
            'name' => 'Test Section',
            'title' => 'Test Section Title',
            'description' => 'Test description',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        // Test without token
        $response = $this->getJson('/api/v1/event-sections');
        $response->assertStatus(401);

        $response = $this->postJson('/api/v1/event-sections', []);
        $response->assertStatus(401);

        $response = $this->getJson('/api/v1/event-sections/' . $eventSection->uuid);
        $response->assertStatus(401);

        $response = $this->putJson('/api/v1/event-sections/' . $eventSection->uuid, []);
        $response->assertStatus(401);

        $response = $this->deleteJson('/api/v1/event-sections/' . $eventSection->uuid);
        $response->assertStatus(401);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanSearchEventSections()
    {
        EventSection::create([
            'name' => 'Main Auditorium',
            'title' => 'Grand Auditorium',
            'description' => 'Large auditorium for keynote presentations',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        EventSection::create([
            'name' => 'Workshop Room B',
            'title' => 'Creative Workshop Space',
            'description' => 'Space for creative workshops and art sessions',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/event-sections?q=auditorium');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }
}
