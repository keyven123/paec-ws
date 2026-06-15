<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\Role;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Permission;
use App\Models\RolePermission;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class ScheduleTimeControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private AdminUser $adminUser;
    private Role $adminRole;
    private Event $testEvent;
    private Schedule $testSchedule;
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
            'name' => 'Schedule Times',
            'code' => 'schedule-times',
            'available_access' => ['view', 'create', 'update', 'delete'],
        ]);

        // Assign permissions to admin role
        foreach (['view', 'create', 'update', 'delete'] as $access) {
            RolePermission::create([
                'role_uuid' => $this->adminRole->uuid,
                'permission_uuid' => $permission->uuid,
                'access' => 'schedule-times-' . $access,
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

        // Create test event and schedule
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

        $this->testSchedule = Schedule::create([
            'event_uuid' => $this->testEvent->uuid,
            'date_from' => '2025-01-01',
            'date_to' => '2025-01-02',
            'status' => 'published',
        ]);

        // Generate JWT token for admin user
        $this->adminToken = auth('admin')->login($this->adminUser);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanListScheduleTimes()
    {
        $scheduleTime = ScheduleTime::create([
            'schedule_uuid' => $this->testSchedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
            'status' => 'published',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/schedule-times');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'uuid',
                        'schedule_uuid',
                        'time_start',
                        'time_end',
                        'status',
                    ],
                ],
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanCreateAScheduleTime()
    {
        $scheduleTimeData = [
            'schedule_uuid' => $this->testSchedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
            'status' => 'published',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/schedule-times', $scheduleTimeData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'uuid',
                    'schedule_uuid',
                    'time_start',
                    'time_end',
                    'status',
                ],
            ]);

        $this->assertDatabaseHas('schedule_times', [
            'schedule_uuid' => $this->testSchedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresAuthenticationForAllEndpoints()
    {
        ScheduleTime::create([
            'schedule_uuid' => $this->testSchedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
            'status' => 'published',
        ]);

        // Test without token
        $response = $this->getJson('/api/v1/schedule-times');
        $response->assertStatus(401);

        $response = $this->postJson('/api/v1/schedule-times', []);
        $response->assertStatus(401);
    }
}
