<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Category;
use App\Models\EventSection;
use App\Models\Role;
use App\Models\Event;
use App\Models\Permission;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class EventControllerTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    private AdminUser $adminUser;
    private Role $adminRole;
    private string $adminToken;
    private Category $category;
    private EventSection $featuredSection;

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
            'name' => 'Events',
            'code' => 'events',
            'available_access' => ['view', 'create', 'update', 'delete'],
        ]);

        // Assign permissions to admin role
        foreach (['view', 'create', 'update', 'delete'] as $access) {
            RolePermission::create([
                'role_uuid' => $this->adminRole->uuid,
                'permission_uuid' => $permission->uuid,
                'access' => 'events-' . $access,
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

        // Create category for event tests
        $this->category = Category::create([
            'name' => 'Conference',
            'code' => 'conference',
            'type' => Category::TYPES['EVENT'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        // Create featured event section for feature test
        $this->featuredSection = EventSection::create([
            'name' => EventSection::FEATURED_SECTION,
            'title' => 'Featured',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        // Generate JWT token for admin user
        $this->adminToken = auth('admin')->login($this->adminUser);
    }

    // #[\PHPUnit\Framework\Attributes\Test]
    // public function itCanListEvents()
    // {
    //     Event::create([
    //         'event_name' => 'Test Event',
    //         'event_description' => 'Test description',
    //         'event_type' => Event::EVENT_TYPES['SINGLE'],
    //         'contact_email' => 'contact@event.com',
    //         'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
    //         'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
    //     ]);

    //     $response = $this->withHeaders([
    //         'Authorization' => 'Bearer ' . $this->adminToken,
    //     ])->getJson('/api/v1/events');

    //     $response->assertStatus(200)
    //         ->assertJsonStructure([
    //             'data' => [
    //                 '*' => [
    //                     'uuid',
    //                     'event_name',
    //                     'event_description',
    //                     'event_type',
    //                     'schedule_type',
    //                     'status',
    //                 ],
    //             ],
    //         ]);
    // }

    // #[\PHPUnit\Framework\Attributes\Test]
    // public function itCanListPublishedEvents()
    // {
    //     Event::create([
    //         'event_name' => 'Published Event',
    //         'event_description' => 'Published description',
    //         'event_type' => Event::EVENT_TYPES['SINGLE'],
    //         'contact_email' => 'contact@event.com',
    //         'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
    //         'published_at' => now(),
    //         'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
    //     ]);

    //     Event::create([
    //         'event_name' => 'Unpublished Event',
    //         'event_description' => 'Unpublished description',
    //         'event_type' => Event::EVENT_TYPES['SINGLE'],
    //         'contact_email' => 'contact@event.com',
    //         'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
    //         'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
    //     ]);

    //     $response = $this->getJson('/api/v1/events/published');

    //     $response->assertStatus(200);
    //     $this->assertCount(1, $response->json('data'));
    // }

    // #[\PHPUnit\Framework\Attributes\Test]
    // public function itCanListFeaturedEvents()
    // {
    //     Event::create([
    //         'event_name' => 'Featured Event',
    //         'event_description' => 'Featured description',
    //         'event_type' => Event::EVENT_TYPES['SINGLE'],
    //         'contact_email' => 'contact@event.com',
    //         'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
    //         'published_at' => now(),
    //         'is_featured' => true,
    //         'featured_order' => 1,
    //         'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
    //     ]);

    //     Event::create([
    //         'event_name' => 'Regular Event',
    //         'event_description' => 'Regular description',
    //         'event_type' => Event::EVENT_TYPES['SINGLE'],
    //         'contact_email' => 'contact@event.com',
    //         'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
    //         'published_at' => now(),
    //         'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
    //     ]);

    //     $response = $this->getJson('/api/v1/events/featured');

    //     $response->assertStatus(200);
    //     $this->assertCount(1, $response->json('data'));
    // }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanCreateAnEvent()
    {
        $eventData = [
            'event_name' => 'New Event',
            'event_description' => 'New event description',
            'contact_email' => 'contact@event.com',
            'city' => 'Manila',
            'category_uuid' => $this->category->uuid,
            'event_type' => Event::EVENT_TYPES['DAILY'],
            'schedule_type' => Event::SCHEDULE_TYPES['DAILY'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'tags' => ['conference', 'tech'],
            'status' => GeneralConstants::EVENT_STATUSES['DRAFT'],
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/events', $eventData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'uuid',
                    'event_name',
                    'event_description',
                    'contact_email',
                    'event_type',
                    'schedule_type',
                    'event_config',
                    'tags',
                    'status',
                ],
            ]);

        $this->assertDatabaseHas('events', [
            'event_name' => 'New Event',
            'event_description' => 'New event description',
            'contact_email' => 'contact@event.com',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itPersistsMetaPixelFieldsFromFrontendAliasesWhenCreatingEvent(): void
    {
        $eventData = [
            'event_name' => 'Fun Event With Meta Pixel',
            'event_description' => 'Fun event description',
            'contact_email' => 'contact@event.com',
            'city' => 'Manila',
            'category_uuid' => $this->category->uuid,
            'event_type' => Event::EVENT_TYPES['DAILY'],
            'schedule_type' => Event::SCHEDULE_TYPES['DAILY'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'enable_meta_pixel' => 'true',
            'meta_pixel_id' => '1234567890',
            'meta_pixel_access_token' => 'test-access-token',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/events', $eventData);

        $response->assertStatus(201);

        $this->assertDatabaseHas('events', [
            'event_name' => 'Fun Event With Meta Pixel',
            'track_event_meta' => true,
            'meta_pixel_id' => '1234567890',
            'meta_pixel_key' => 'test-access-token',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanShowAnEvent()
    {
        $event = Event::create([
            'event_name' => 'Test Event',
            'event_description' => 'Test description',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'contact_email' => 'contact@event.com',
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'status' => GeneralConstants::EVENT_STATUSES['DRAFT'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/events/' . $event->uuid);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'uuid' => $event->uuid,
                    'event_name' => $event->event_name,
                    'event_description' => $event->event_description,
                ],
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanUpdateAnEvent()
    {
        $event = Event::create([
            'event_name' => 'Test Event',
            'event_description' => 'Test description',
            'contact_email' => 'contact@event.com',
            'event_type' => Event::EVENT_TYPES['DAILY'],
            'schedule_type' => Event::SCHEDULE_TYPES['DAILY'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'slug' => 'test-event',
            'status' => GeneralConstants::EVENT_STATUSES['DRAFT'],
        ]);

        $updateData = [
            'event_name' => 'Updated Event',
            'event_description' => 'Updated description',
            'contact_email' => 'updated@event.com',
            'city' => 'Manila',
            'category_uuid' => $this->category->uuid,
            'event_type' => Event::EVENT_TYPES['DAILY'],
            'slug' => 'updated-event',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/v1/events/' . $event->uuid, $updateData);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'event_name' => 'Updated Event',
                    'event_description' => 'Updated description',
                    'contact_email' => 'updated@event.com',
                ],
            ]);

        $this->assertDatabaseHas('events', [
            'uuid' => $event->uuid,
            'event_name' => 'Updated Event',
            'event_description' => 'Updated description',
            'contact_email' => 'updated@event.com',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanPublishAnEvent()
    {
        $event = Event::create([
            'event_name' => 'Test Event',
            'event_description' => 'Test description',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'contact_email' => 'contact@event.com',
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'status' => GeneralConstants::EVENT_STATUSES['DRAFT'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->patchJson('/api/v1/events/' . $event->uuid . '/publish');

        $response->assertStatus(200);

        $event->refresh();
        $this->assertNotNull($event->published_at);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanUnpublishAnEvent()
    {
        $event = Event::create([
            'event_name' => 'Test Event',
            'event_description' => 'Test description',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'contact_email' => 'contact@event.com',
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'published_at' => now(),
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->patchJson('/api/v1/events/' . $event->uuid . '/unpublish');

        $response->assertStatus(200);

        $event->refresh();
        $this->assertNull($event->published_at);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanCancelAnEvent()
    {
        $event = Event::create([
            'event_name' => 'Test Event',
            'event_description' => 'Test description',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'contact_email' => 'contact@event.com',
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'status' => GeneralConstants::EVENT_STATUSES['DRAFT'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->patchJson('/api/v1/events/' . $event->uuid . '/cancel');

        $response->assertStatus(200);

        $event->refresh();
        $this->assertNotNull($event->cancelled_at);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanCompleteAnEvent()
    {
        $event = Event::create([
            'event_name' => 'Test Event',
            'event_description' => 'Test description',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'contact_email' => 'contact@event.com',
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'status' => GeneralConstants::EVENT_STATUSES['DRAFT'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->patchJson('/api/v1/events/' . $event->uuid . '/complete');

        $response->assertStatus(200);

        $event->refresh();
        $this->assertNotNull($event->completed_at);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanFeatureAnEvent()
    {
        $event = Event::create([
            'event_name' => 'Test Event',
            'event_description' => 'Test description',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'contact_email' => 'contact@event.com',
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'status' => GeneralConstants::EVENT_STATUSES['DRAFT'],
        ]);

        $featureData = [
            'featured_order' => 1,
            'featured_from' => now()->toDateString(),
            'featured_until' => now()->addDays(7)->toDateString(),
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->patchJson('/api/v1/events/' . $event->uuid . '/feature', $featureData);

        $response->assertStatus(200);

        $event->refresh();
        $this->assertTrue($event->is_featured);
        $this->assertEquals(1, $event->featured_order);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanUnfeatureAnEvent()
    {
        $event = Event::create([
            'event_name' => 'Test Event',
            'event_description' => 'Test description',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'contact_email' => 'contact@event.com',
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'is_featured' => true,
            'featured_order' => 1,
            'status' => GeneralConstants::EVENT_STATUSES['DRAFT'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->patchJson('/api/v1/events/' . $event->uuid . '/unfeature');

        $response->assertStatus(200);

        $event->refresh();
        $this->assertFalse($event->is_featured);
        $this->assertNull($event->featured_order);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanDeleteAnEvent()
    {
        $event = Event::create([
            'event_name' => 'Test Event',
            'event_description' => 'Test description',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'contact_email' => 'contact@event.com',
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'status' => GeneralConstants::EVENT_STATUSES['DRAFT'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->deleteJson('/api/v1/events/' . $event->uuid);

        $response->assertStatus(204);

        $this->assertSoftDeleted('events', [
            'uuid' => $event->uuid,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itPreventsDeleteEventWithRegistrations()
    {
        $event = Event::create([
            'event_name' => 'Test Event',
            'event_description' => 'Test description',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'contact_email' => 'contact@event.com',
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'registration_count' => 5,
            'status' => GeneralConstants::EVENT_STATUSES['DRAFT'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->deleteJson('/api/v1/events/' . $event->uuid);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Cannot delete event with existing registrations.',
            ]);

        $this->assertDatabaseHas('events', [
            'uuid' => $event->uuid,
            'deleted_at' => null,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturns404ForNonExistentEvent()
    {
        $nonExistentUuid = '550e8400-e29b-41d4-a716-446655440000';

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/events/' . $nonExistentUuid);

        $response->assertStatus(404);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresAuthenticationForProtectedEndpoints()
    {
        $event = Event::create([
            'event_name' => 'Test Event',
            'event_description' => 'Test description',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'contact_email' => 'contact@event.com',
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'status' => GeneralConstants::EVENT_STATUSES['DRAFT'],
        ]);

        // Test without token
        $response = $this->getJson('/api/v1/events');
        $response->assertStatus(401);

        $response = $this->postJson('/api/v1/events', []);
        $response->assertStatus(401);

        $response = $this->getJson('/api/v1/events/' . $event->uuid);
        $response->assertStatus(401);

        $response = $this->putJson('/api/v1/events/' . $event->uuid, []);
        $response->assertStatus(401);

        $response = $this->deleteJson('/api/v1/events/' . $event->uuid);
        $response->assertStatus(401);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesEventCreationData()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/events', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['event_name']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesEventUpdateData()
    {
        $event = Event::create([
            'event_name' => 'Test Event',
            'event_description' => 'Test description',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'contact_email' => 'contact@event.com',
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'status' => GeneralConstants::EVENT_STATUSES['DRAFT'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/v1/events/' . $event->uuid, [
            'contact_email' => 'invalid-email',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['contact_email']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanFilterEventsByCategory()
    {
        $categoryUuid = '550e8400-e29b-41d4-a716-446655440001';

        Event::create([
            'event_name' => 'Conference Event',
            'category_uuid' => $categoryUuid,
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'contact_email' => 'contact@event.com',
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'status' => GeneralConstants::EVENT_STATUSES['DRAFT'],
        ]);

        Event::create([
            'event_name' => 'Workshop Event',
            'category_uuid' => '550e8400-e29b-41d4-a716-446655440002',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'contact_email' => 'contact@event.com',
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'status' => GeneralConstants::EVENT_STATUSES['DRAFT'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/events?category_uuid=' . $categoryUuid);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }
}
