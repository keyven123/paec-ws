<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\Role;
use App\Models\User;
use App\Models\Event;
use App\Models\Transaction;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class TransactionControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private \App\Models\AdminUser $adminUser;
    private Role $adminRole;
    private Event $testEvent;
    private Schedule $testSchedule;
    private ScheduleTime $testScheduleTime;
    private string $adminToken;
    private User $testUser;

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
            'name' => 'Transactions',
            'code' => 'transactions',
            'available_access' => ['view', 'create', 'edit', 'delete'],
        ]);

        // Assign permissions to admin role
        foreach (['view', 'create', 'edit', 'delete'] as $access) {
            RolePermission::create([
                'role_uuid' => $this->adminRole->uuid,
                'permission_uuid' => $permission->uuid,
                'access' => 'transactions-' . $access,
            ]);
        }

        // Create admin user
        $this->adminUser = \App\Models\AdminUser::create([
            'role_uuid' => $this->adminRole->uuid,
            'email' => 'admin@test.com',
            'password' => 'password123',
            'first_name' => 'Admin',
            'last_name' => 'User',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        // Create test event
        $this->testEvent = Event::create([
            'event_name' => 'Test Event',
            'event_description' => 'Test event description',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'contact_email' => 'contact@event.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['SEAT_SELECTION'],
        ]);

        $this->testSchedule = Schedule::create([
            'event_uuid' => $this->testEvent->uuid,
            'date_from' => '2025-01-01',
            'date_to' => '2025-01-02',
            'status' => 'published',
        ]);
        $this->testScheduleTime = ScheduleTime::create([
            'schedule_uuid' => $this->testSchedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
            'status' => 'published',
        ]);

        // Create a regular user for transactions
        $customerRole = Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);
        $this->testUser = User::create([
            'role_uuid' => $customerRole->uuid,
            'email' => 'customer@test.com',
            'password' => bcrypt('password123'),
            'first_name' => 'Test',
            'last_name' => 'Customer',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        // Generate JWT token for admin user
        $this->adminToken = auth('admin')->login($this->adminUser);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanListTransactions()
    {
        $transaction = Transaction::create([
            'user_uuid' => $this->testUser->uuid,
            'event_uuid' => $this->testEvent->uuid,
            'schedule_uuid' => $this->testSchedule->uuid,
            'schedule_time_uuid' => $this->testScheduleTime->uuid,
            'payment_method' => 'cash',
            'order_number' => 'ORD-20250101-ABC123',
            'total_amount' => 100.00,
            'sub_total' => 90.00,
            'tax_amount' => 10.00,
            'discount' => 0.00,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_status' => 'confirmed',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/transactions');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'uuid',
                        'user_uuid',
                        'event_uuid',
                        'order_number',
                        'total_amount',
                        'status',
                        'payment_status',
                        'order_status',
                    ],
                ],
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanCreateATransaction()
    {
        $transactionData = [
            'user_uuid' => $this->testUser->uuid,
            'event_uuid' => $this->testEvent->uuid,
            'total_amount' => 100.00,
            'sub_total' => 90.00,
            'tax_amount' => 10.00,
            'discount' => 0.00,
            'status' => 'pending',
            'payment_status' => 'pending',
            'order_status' => 'processing',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/transactions', $transactionData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'uuid',
                    'user_uuid',
                    'event_uuid',
                    'order_number',
                    'total_amount',
                    'status',
                ],
            ]);

        $this->assertDatabaseHas('transactions', [
            'user_uuid' => $this->testUser->uuid,
            'event_uuid' => $this->testEvent->uuid,
            'total_amount' => 100.00,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresAuthenticationForAllEndpoints()
    {
        // Test without token
        $response = $this->getJson('/api/v1/transactions');
        $response->assertStatus(401);

        $response = $this->postJson('/api/v1/transactions', []);
        $response->assertStatus(401);
    }
}
