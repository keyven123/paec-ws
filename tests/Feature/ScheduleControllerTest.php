<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\Role;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\Schedule;
use App\Models\Permission;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class ScheduleControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private AdminUser $adminUser;
    private Role $adminRole;
    private Event $testEvent;
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
            'name' => 'Schedules',
            'code' => 'schedules',
            'available_access' => ['view', 'create', 'update', 'delete'],
        ]);

        // Assign permissions to admin role
        foreach (['view', 'create', 'update', 'delete'] as $access) {
            RolePermission::create([
                'role_uuid' => $this->adminRole->uuid,
                'permission_uuid' => $permission->uuid,
                'access' => 'schedules-' . $access,
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

        // Create test event
        $this->testEvent = Event::create([
            'event_name' => 'Test Event',
            'event_description' => 'Test event description',
            'contact_email' => 'contact@event.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['SEAT_SELECTION'],
            'tags' => ['conference', 'tech'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        // Generate JWT token for admin user
        $this->adminToken = auth('admin')->login($this->adminUser);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanListSchedules()
    {
        $schedule = Schedule::create([
            'event_uuid' => $this->testEvent->uuid,
            'date_from' => '2025-01-01',
            'date_to' => '2025-01-02',
            'status' => 'published',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/schedules');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'uuid',
                        'event_uuid',
                        'date_from',
                        'date_to',
                        'status',
                    ],
                ],
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanCreateASchedule()
    {
        $scheduleData = [
            'event_uuid' => $this->testEvent->uuid,
            'date_from' => '2025-01-01',
            'date_to' => '2025-01-02',
            'status' => 'published',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/schedules', $scheduleData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'uuid',
                    'event_uuid',
                    'date_from',
                    'date_to',
                    'status',
                ],
            ]);

        $this->assertDatabaseHas('schedules', [
            'event_uuid' => $this->testEvent->uuid,
            'date_from' => '2025-01-01 00:00:00',
            'date_to' => '2025-01-02 00:00:00',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanShowASchedule()
    {
        $schedule = Schedule::create([
            'event_uuid' => $this->testEvent->uuid,
            'date_from' => '2025-01-01',
            'date_to' => '2025-01-02',
            'status' => 'published',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/schedules/' . $schedule->uuid);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'uuid' => $schedule->uuid,
                    'event_uuid' => $schedule->event_uuid,
                    'date_from' => $schedule->date_from->format('Y-m-d'),
                    'date_to' => $schedule->date_to->format('Y-m-d'),
                ],
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanUpdateASchedule()
    {
        $schedule = Schedule::create([
            'event_uuid' => $this->testEvent->uuid,
            'date_from' => '2025-01-01',
            'date_to' => '2025-01-02',
            'status' => 'published',
        ]);

        $updateData = [
            'date_from' => '2025-01-03',
            'date_to' => '2025-01-04',
            'status' => 'draft',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/v1/schedules/' . $schedule->uuid, $updateData);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'date_from' => '2025-01-03',
                    'date_to' => '2025-01-04',
                    'status' => 'draft',
                ],
            ]);

        $this->assertDatabaseHas('schedules', [
            'uuid' => $schedule->uuid,
            'date_from' => '2025-01-03 00:00:00',
            'date_to' => '2025-01-04 00:00:00',
            'status' => 'draft',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanDeleteASchedule()
    {
        $schedule = Schedule::create([
            'event_uuid' => $this->testEvent->uuid,
            'date_from' => '2025-01-01',
            'date_to' => '2025-01-02',
            'status' => 'published',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->deleteJson('/api/v1/schedules/' . $schedule->uuid);

        $response->assertStatus(204);

        $this->assertSoftDeleted('schedules', [
            'uuid' => $schedule->uuid,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesScheduleCreationData()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/schedules', [
            'date_from' => '2025-01-02',
            'date_to' => '2025-01-01', // Invalid: end date before start date
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['event_uuid', 'date_to']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresAuthenticationForAllEndpoints()
    {
        $schedule = Schedule::create([
            'event_uuid' => $this->testEvent->uuid,
            'date_from' => '2025-01-01',
            'date_to' => '2025-01-02',
            'status' => 'published',
        ]);

        // Test without token
        $response = $this->getJson('/api/v1/schedules');
        $response->assertStatus(401);

        $response = $this->postJson('/api/v1/schedules', []);
        $response->assertStatus(401);

        $response = $this->getJson('/api/v1/schedules/' . $schedule->uuid);
        $response->assertStatus(401);

        $response = $this->putJson('/api/v1/schedules/' . $schedule->uuid, []);
        $response->assertStatus(401);

        $response = $this->deleteJson('/api/v1/schedules/' . $schedule->uuid);
        $response->assertStatus(401);
    }
}
