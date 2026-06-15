<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Role;
use App\Models\PromoCode;
use App\Models\Organization;
use App\Models\Event;
use App\Models\Permission;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class PromoCodeControllerTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    private AdminUser $adminUser;
    private Role $adminRole;
    private string $adminToken;
    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        // Create organization
        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'representative_first_name' => 'John',
            'representative_last_name' => 'Doe',
            'email' => 'org@test.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ]);

        // Create admin role
        $this->adminRole = Role::create([
            'name' => 'Admin',
            'code' => GeneralConstants::ROLES['ADMIN']['name'],
        ]);

        // Create permissions
        $permission = Permission::create([
            'name' => 'Promo Codes',
            'code' => 'promo-codes',
            'available_access' => ['view', 'create', 'update', 'delete'],
        ]);

        // Assign permissions to admin role
        foreach (['view', 'create', 'update', 'delete'] as $access) {
            RolePermission::create([
                'role_uuid' => $this->adminRole->uuid,
                'permission_uuid' => $permission->uuid,
                'access' => 'promo-codes-' . $access,
            ]);
        }

        // Create admin user
        $this->adminUser = AdminUser::create([
            'role_uuid' => $this->adminRole->uuid,
            'organization_uuid' => $this->organization->uuid,
            'email' => 'admin@test.com',
            'password' => 'password123',
            'first_name' => 'Regular',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        // Generate JWT token for admin user
        $this->adminToken = auth('admin')->login($this->adminUser) ?? '';
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanListPromoCodes()
    {
        $promoCode = PromoCode::create([
            'organization_uuid' => $this->organization->uuid,
            'code' => 'TEST2024',
            'description' => 'Test promo code description',
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
            'discount_value' => 10.00,
            'is_unlimited' => true,
            'usable_from' => now(),
            'usable_to' => now()->addDays(30),
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/promo-codes');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'uuid',
                        'code',
                        'discount_type',
                        'discount_value',
                        'is_unlimited',
                        'status',
                    ],
                ],
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanCreateAPromoCode()
    {
        $promoCodeData = [
            'code' => 'NEW2024',
            'description' => 'New promo code for testing',
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
            'discount_value' => 15.00,
            'is_unlimited' => false,
            'max_use' => 100,
            'usable_from' => now()->toDateTimeString(),
            'usable_to' => now()->addDays(30)->toDateTimeString(),
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/promo-codes', $promoCodeData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'uuid',
                    'code',
                    'discount_type',
                    'discount_value',
                    'is_unlimited',
                    'max_use',
                    'status',
                ],
            ]);

        $this->assertDatabaseHas('promo_codes', [
            'code' => 'NEW2024',
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanShowAPromoCode()
    {
        $promoCode = PromoCode::create([
            'organization_uuid' => $this->organization->uuid,
            'code' => 'SHOW2024',
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['AMOUNT'],
            'discount_value' => 50.00,
            'is_unlimited' => true,
            'usable_from' => now(),
            'usable_to' => now()->addDays(30),
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/promo-codes/' . $promoCode->uuid);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'uuid' => $promoCode->uuid,
                    'code' => $promoCode->code,
                    'discount_type' => $promoCode->discount_type,
                ],
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanUpdateAPromoCode()
    {
        $promoCode = PromoCode::create([
            'organization_uuid' => $this->organization->uuid,
            'code' => 'UPDATE2024',
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
            'discount_value' => 10.00,
            'is_unlimited' => true,
            'usable_from' => now(),
            'usable_to' => now()->addDays(30),
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $updateData = [
            'code' => 'UPDATED2024',
            'discount_value' => 20.00,
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/v1/promo-codes/' . $promoCode->uuid, $updateData);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'code' => 'UPDATED2024',
                    'discount_value' => '20.00',
                ],
            ]);

        $this->assertDatabaseHas('promo_codes', [
            'uuid' => $promoCode->uuid,
            'code' => 'UPDATED2024',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanDeleteAPromoCode()
    {
        $promoCode = PromoCode::create([
            'organization_uuid' => $this->organization->uuid,
            'code' => 'DELETE2024',
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
            'discount_value' => 10.00,
            'is_unlimited' => true,
            'usable_from' => now(),
            'usable_to' => now()->addDays(30),
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->deleteJson('/api/v1/promo-codes/' . $promoCode->uuid);

        $response->assertStatus(204);

        $this->assertSoftDeleted('promo_codes', [
            'uuid' => $promoCode->uuid,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesPromoCodeCreationData()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/promo-codes', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code', 'discount_type', 'discount_value', 'usable_from', 'usable_to']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesUniqueCodeOnCreation()
    {
        PromoCode::create([
            'organization_uuid' => $this->organization->uuid,
            'code' => 'UNIQUE2024',
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
            'discount_value' => 10.00,
            'is_unlimited' => true,
            'usable_from' => now(),
            'usable_to' => now()->addDays(30),
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/promo-codes', [
            'code' => 'UNIQUE2024',
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
            'discount_value' => 15.00,
            'usable_from' => now()->toDateTimeString(),
            'usable_to' => now()->addDays(30)->toDateTimeString(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesPercentageDiscountValue()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/promo-codes', [
            'code' => 'PERCENT2024',
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
            'discount_value' => 150.00, // Exceeds 100%
            'usable_from' => now()->toDateTimeString(),
            'usable_to' => now()->addDays(30)->toDateTimeString(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['discount_value']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturns404ForNonExistentPromoCode()
    {
        $nonExistentUuid = '550e8400-e29b-41d4-a716-446655440000';

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/promo-codes/' . $nonExistentUuid);

        $response->assertStatus(404);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresAuthenticationForAllEndpoints()
    {
        $promoCode = PromoCode::create([
            'organization_uuid' => $this->organization->uuid,
            'code' => 'AUTH2024',
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
            'discount_value' => 10.00,
            'is_unlimited' => true,
            'usable_from' => now(),
            'usable_to' => now()->addDays(30),
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        // Test without token
        $response = $this->getJson('/api/v1/promo-codes');
        $response->assertStatus(401);

        $response = $this->postJson('/api/v1/promo-codes', []);
        $response->assertStatus(401);

        $response = $this->getJson('/api/v1/promo-codes/' . $promoCode->uuid);
        $response->assertStatus(401);

        $response = $this->putJson('/api/v1/promo-codes/' . $promoCode->uuid, []);
        $response->assertStatus(401);

        $response = $this->deleteJson('/api/v1/promo-codes/' . $promoCode->uuid);
        $response->assertStatus(401);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanCreateAPromoCodeAttachedToAnEvent()
    {
        // Create an event first
        $event = Event::create([
            'organization_uuid' => $this->organization->uuid,
            'event_name' => 'Test Event for Promo Code',
            'event_description' => 'This is a test event',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'contact_email' => 'event@test.com',
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'status' => GeneralConstants::EVENT_STATUSES['DRAFT'],
        ]);

        // Create promo code data attached to the event
        $promoCodeData = [
            'code' => 'EVENT2024',
            'description' => 'Special discount for Test Event',
            'activityable_type' => Event::class,
            'activityable_id' => $event->uuid,
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
            'discount_value' => 25.00,
            'is_unlimited' => false,
            'max_use' => 50,
            'usable_from' => now()->toDateTimeString(),
            'usable_to' => now()->addDays(30)->toDateTimeString(),
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/promo-codes', $promoCodeData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'uuid',
                    'code',
                    'description',
                    'activityable_type',
                    'activityable_id',
                    'discount_type',
                    'discount_value',
                    'is_unlimited',
                    'max_use',
                    'status',
                ],
            ])
            ->assertJson([
                'data' => [
                    'code' => 'EVENT2024',
                    'description' => 'Special discount for Test Event',
                    'activityable_type' => Event::class,
                    'activityable_id' => $event->uuid,
                    'discount_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
                    'discount_value' => '25.00',
                ],
            ]);

        // Verify the promo code was saved with the event relationship
        $this->assertDatabaseHas('promo_codes', [
            'code' => 'EVENT2024',
            'activityable_type' => Event::class,
            'activityable_id' => $event->uuid,
            'organization_uuid' => $this->organization->uuid,
        ]);

        // Verify the relationship works
        $promoCode = PromoCode::where('code', 'EVENT2024')->first();
        $this->assertNotNull($promoCode);
        $this->assertEquals($event->uuid, $promoCode->activityable_id);
        $this->assertEquals(Event::class, $promoCode->activityable_type);

        // Load and verify the relationship
        $promoCode->load('activityable');
        $this->assertNotNull($promoCode->activityable);
        $this->assertInstanceOf(Event::class, $promoCode->activityable);
        $this->assertEquals($event->uuid, $promoCode->activityable->uuid);
        $this->assertEquals('Test Event for Promo Code', $promoCode->activityable->event_name);
    }
}
