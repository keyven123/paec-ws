<?php

namespace Tests\Feature\Coverage\HttpAnalyticsCms;

use App\Constants\GeneralConstants;
use App\Models\Event;
use App\Models\EventLocation;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventLocationControllerErrorsTest extends TestCase
{
    use CreatesPermittedAdmin;
    use RefreshDatabase;

    private const MISSING_UUID = '550e8400-e29b-41d4-a716-446655440000';

    private Event $event;

    private EventLocation $taguig;

    private EventLocation $manila;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsPermittedAdmin(['events' => ['view', 'create', 'update', 'delete']]);

        $organization = Organization::create([
            'name' => 'Location Org',
            'representative_first_name' => 'Rep',
            'representative_last_name' => 'Resentative',
            'email' => 'location-org@test.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
            'commission_percentage' => 10,
        ]);

        $this->event = Event::create([
            'organization_uuid' => $organization->uuid,
            'event_name' => 'Location Event',
            'event_description' => 'Has locations',
            'contact_email' => 'location-event@test.com',
            'event_type' => Event::EVENT_TYPES['DAILY'],
            'schedule_type' => Event::SCHEDULE_TYPES['DAILY'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
        ]);

        $this->taguig = EventLocation::create([
            'event_uuid' => $this->event->uuid,
            'name' => 'BGC Branch',
            'city' => 'Taguig',
            'organization_uuid' => $organization->uuid,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->manila = EventLocation::create([
            'event_uuid' => $this->event->uuid,
            'name' => 'Manila Branch',
            'city' => 'Manila',
            'organization_uuid' => $organization->uuid,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    #[Test]
    public function indexReturns404ForUnknownEvent(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->getJson('/api/v1/events/' . self::MISSING_UUID . '/locations')
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Event not found']);
    }

    #[Test]
    public function storeReturns404ForUnknownEventWithoutCreatingLocation(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->postJson('/api/v1/events/' . self::MISSING_UUID . '/locations', ['city' => 'Pasig'])
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Event not found']);

        $this->assertDatabaseMissing('event_locations', ['city' => 'Pasig']);
    }

    #[Test]
    public function updateReturns404ForUnknownEvent(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->putJson('/api/v1/events/' . self::MISSING_UUID . '/locations/' . $this->taguig->uuid, ['city' => 'Pasig'])
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Event not found']);

        $this->assertDatabaseHas('event_locations', ['uuid' => $this->taguig->uuid, 'city' => 'Taguig']);
    }

    #[Test]
    public function updateReturns404ForLocationOfAnotherEvent(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->putJson('/api/v1/events/' . $this->event->uuid . '/locations/' . self::MISSING_UUID, ['city' => 'Pasig'])
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Location not found']);
    }

    #[Test]
    public function destroyReturns404ForUnknownEvent(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->deleteJson('/api/v1/events/' . self::MISSING_UUID . '/locations/' . $this->taguig->uuid)
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Event not found']);

        $this->assertDatabaseHas('event_locations', ['uuid' => $this->taguig->uuid]);
    }

    #[Test]
    public function destroyReturns404ForUnknownLocation(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->deleteJson('/api/v1/events/' . $this->event->uuid . '/locations/' . self::MISSING_UUID)
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Location not found']);
    }

    #[Test]
    public function destroyDeactivatesLocationThatHasSales(): void
    {
        $customerRole = Role::firstOrCreate(
            ['code' => GeneralConstants::ROLES['CUSTOMER']['name']],
            ['name' => 'Customer'],
        );

        $customer = User::create([
            'role_uuid' => $customerRole->uuid,
            'email' => 'location-buyer@test.com',
            'password' => 'password123',
            'first_name' => 'Location',
            'last_name' => 'Buyer',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        Transaction::create([
            'user_uuid' => $customer->uuid,
            'event_uuid' => $this->event->uuid,
            'event_location_uuid' => $this->manila->uuid,
            'organization_uuid' => $this->event->organization_uuid,
            'order_number' => 'ORD-' . Str::upper(Str::random(10)),
            'total_amount' => 500,
            'sub_total' => 500,
            'tax_amount' => 0,
            'discount' => 0,
            'promo_code_discount' => 0,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
        ]);

        $this->withHeaders($this->adminHeaders())
            ->deleteJson('/api/v1/events/' . $this->event->uuid . '/locations/' . $this->manila->uuid)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Location deactivated because it has existing sales.')
            ->assertJsonPath('data.uuid', $this->manila->uuid)
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('event_locations', [
            'uuid' => $this->manila->uuid,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('event_locations', ['uuid' => $this->taguig->uuid, 'is_active' => true]);
    }
}
