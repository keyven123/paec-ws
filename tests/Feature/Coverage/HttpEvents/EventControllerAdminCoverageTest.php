<?php

namespace Tests\Feature\Coverage\HttpEvents;

use App\Constants\GeneralConstants;
use App\Http\Controllers\EventController;
use App\Http\Requests\Event\UpdateEventAffiliateRequest;
use App\Models\Event;
use App\Models\EventSection;
use App\Models\EventTicket;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class EventControllerAdminCoverageTest extends HttpEventsTestCase
{
    private const MISSING_UUID = '6f1c1a52-9a8b-4c7e-b1d2-000000000404';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeEvent(array $overrides = []): Event
    {
        return Event::create(array_merge([
            'event_name' => 'Admin Coverage ' . uniqid(),
            'event_description' => 'Coverage',
            'contact_email' => 'admin-cov@test.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
        ], $overrides));
    }

    private function makeCustomer(string $first = 'Juan', string $last = 'Dela Cruz'): User
    {
        $role = Role::firstOrCreate(
            ['code' => GeneralConstants::ROLES['CUSTOMER']['name']],
            ['name' => 'Customer']
        );

        return User::create([
            'role_uuid' => $role->uuid,
            'first_name' => $first,
            'last_name' => $last,
            'email' => strtolower($first) . uniqid() . '@test.com',
            'password' => 'password123',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);
    }

    private function makePaidTransaction(Event $event, User $user, array $overrides = []): Transaction
    {
        return Transaction::create(array_merge([
            'user_uuid' => $user->uuid,
            'event_uuid' => $event->uuid,
            'payment_method' => 'cash',
            'order_number' => 'ORD-' . strtoupper(uniqid()),
            'total_amount' => 100,
            'sub_total' => 100,
            'tax_amount' => 0,
            'discount' => 0,
            'status' => 'completed',
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => 'confirmed',
        ], $overrides));
    }

    private function makeTicket(Event $event, User $user, Transaction $transaction, EventTicket $eventTicket, array $overrides = []): Ticket
    {
        return Ticket::create(array_merge([
            'user_uuid' => $user->uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $event->uuid,
            'event_ticket_uuid' => $eventTicket->uuid,
            'attendee_name' => $user->first_name . ' ' . $user->last_name,
            'attendee_email' => $user->email,
            'ticket_number' => 'TN-' . strtoupper(uniqid()),
            'qr_code' => 'QR-' . strtoupper(uniqid()),
            'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
        ], $overrides));
    }

    private function makeEventTicket(Event $event): EventTicket
    {
        return EventTicket::create([
            'event_uuid' => $event->uuid,
            'code' => 'GA-' . uniqid(),
            'name' => 'General Admission',
            'price' => 100,
            'max_ticket' => 100,
            'is_unlimited' => true,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function missingEventEndpoints(): array
    {
        return [
            'submit for approval' => ['patch', 'submit-for-approval', []],
            'request for featured' => ['patch', 'request-for-featured', []],
            'cancel for featured' => ['patch', 'cancel-for-featured', []],
            'cancel for approval' => ['patch', 'cancel-for-approval', []],
            'approve' => ['patch', 'approve', []],
            'publish' => ['patch', 'publish', []],
            'unpublish' => ['patch', 'unpublish', []],
            'cancel' => ['patch', 'cancel', []],
            'complete' => ['patch', 'complete', []],
            'feature' => ['patch', 'feature', ['featured_order' => 1]],
            'unfeature' => ['patch', 'unfeature', []],
            'today cutoff' => ['patch', 'today-cutoff', ['today_cutoff_time' => '10:00']],
            'affiliate settings' => ['patch', 'affiliate-settings', ['affiliate_enabled' => false]],
            'destroy' => ['delete', '', []],
        ];
    }

    #[Test]
    #[DataProvider('missingEventEndpoints')]
    public function eventActionsReturn404ForUnknownEvent(string $method, string $suffix, array $body): void
    {
        [, $token] = $this->createAdmin([
            'events' => ['view', 'update', 'delete'],
            'affiliate-events' => ['update'],
        ]);
        $existing = $this->makeEvent(['status' => GeneralConstants::EVENT_STATUSES['DRAFT']]);

        $uri = '/api/v1/events/' . self::MISSING_UUID . ($suffix !== '' ? '/' . $suffix : '');

        $this->withHeaders($this->bearer($token))
            ->json(strtoupper($method), $uri, $body)
            ->assertNotFound()
            ->assertJson(['success' => false, 'message' => 'Event not found']);

        $this->assertDatabaseHas('events', [
            'uuid' => $existing->uuid,
            'status' => GeneralConstants::EVENT_STATUSES['DRAFT'],
            'deleted_at' => null,
        ]);
    }

    #[Test]
    public function updateTodayCutoffStoresAndClearsTheCutoffTime(): void
    {
        [, $token] = $this->createAdmin(['events' => ['update']]);
        $event = $this->makeEvent();

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/v1/events/' . $event->uuid . '/today-cutoff', ['today_cutoff_time' => '17:45'])
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Today cut-off time updated.',
                'data' => ['today_cutoff_time' => '17:45'],
            ]);
        $this->assertStringStartsWith('17:45', (string) $event->fresh()->today_cutoff_time);

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/v1/events/' . $event->uuid . '/today-cutoff', ['today_cutoff_time' => null])
            ->assertOk()
            ->assertJsonPath('data.today_cutoff_time', null);
        $this->assertNull($event->fresh()->today_cutoff_time);
    }

    #[Test]
    public function recentPurchasedTicketsListsPaidPurchasesAsActivities(): void
    {
        [, $token] = $this->createAdmin(['events' => ['view']]);
        $event = $this->makeEvent();
        $eventTicket = $this->makeEventTicket($event);
        $buyer = $this->makeCustomer('Maria', 'Santos');
        $paid = $this->makePaidTransaction($event, $buyer, ['order_number' => 'ORD-PAID-1']);
        $this->makeTicket($event, $buyer, $paid, $eventTicket);
        $pending = $this->makePaidTransaction($event, $buyer, [
            'order_number' => 'ORD-PENDING-1',
            'payment_status' => Transaction::PAYMENT_STATUS['PENDING'],
        ]);
        $this->makeTicket($event, $buyer, $pending, $eventTicket);

        $response = $this->withHeaders($this->bearer($token))
            ->getJson('/api/v1/events/' . $event->uuid . '/recent-purchased-tickets')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'activities')
            ->assertJsonPath('activities.0.type', 'purchase')
            ->assertJsonPath('activities.0.message', "{$buyer->full_name} purchased tickets (Order: ORD-PAID-1)");

        $this->assertStringContainsString('Maria', $buyer->full_name);

        $this->assertSame(
            Carbon::parse($paid->created_at)->format('F d, Y H:i:'),
            $response->json('activities.0.timestamp')
        );
    }

    #[Test]
    public function scannedAttendeesAppliesScheduleTimeSearchAndDateFilters(): void
    {
        [, $token] = $this->createAdmin(['events' => ['view']]);
        $event = $this->makeEvent();
        $eventTicket = $this->makeEventTicket($event);
        $schedule = Schedule::create([
            'event_uuid' => $event->uuid,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-02',
            'status' => GeneralConstants::SCHEDULE_STATUSES['PUBLISHED'],
        ]);
        $slot = ScheduleTime::create([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => '09:00:00',
            'time_end' => '12:00:00',
            'status' => GeneralConstants::SCHEDULE_STATUSES['PUBLISHED'],
        ]);
        $otherSlot = ScheduleTime::create([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => '13:00:00',
            'time_end' => '15:00:00',
            'status' => GeneralConstants::SCHEDULE_STATUSES['PUBLISHED'],
        ]);
        $buyer = $this->makeCustomer();
        $slotTxn = $this->makePaidTransaction($event, $buyer, [
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $slot->uuid,
        ]);
        $otherTxn = $this->makePaidTransaction($event, $buyer, [
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $otherSlot->uuid,
        ]);

        $match = $this->makeTicket($event, $buyer, $slotTxn, $eventTicket, [
            'attendee_name' => 'Zebra Unique',
            'used_at' => '2026-07-01 10:00:00',
        ]);
        $this->makeTicket($event, $buyer, $slotTxn, $eventTicket, [
            'attendee_name' => 'Searchable Other',
            'used_at' => '2026-07-01 10:30:00',
        ]);
        $this->makeTicket($event, $buyer, $otherTxn, $eventTicket, [
            'attendee_name' => 'Zebra Unique',
            'used_at' => '2026-07-01 13:30:00',
        ]);
        $this->makeTicket($event, $buyer, $slotTxn, $eventTicket, [
            'attendee_name' => 'Zebra Unique',
            'used_at' => '2026-07-02 10:00:00',
        ]);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/v1/events/' . $event->uuid . '/scanned-attendees?' . http_build_query([
                'schedule_uuid' => $schedule->uuid,
                'schedule_time_uuid' => $slot->uuid,
                'search' => 'Unique',
                'scanned_date' => '2026-07-01',
                'per_page' => 10,
            ]))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('data.0.uuid', $match->uuid);
    }

    #[Test]
    public function exportWithDateRangeReturnsCsvAttachment(): void
    {
        Carbon::setTestNow('2026-08-01 09:15:30');
        [, $token] = $this->createAdmin(['events' => ['export']]);
        $event = $this->makeEvent(['event_name' => 'Range Export Event']);

        $response = $this->withHeaders($this->bearer($token))
            ->get('/api/v1/events/' . $event->uuid . '/export?start_date=2026-07-01&end_date=2026-07-31')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8');

        $this->assertSame(
            'attachment; filename="purchasers_Range_Export_Event_2026-08-01_09-15-30.csv"',
            $response->headers->get('Content-Disposition')
        );
    }

    #[Test]
    public function indexRequiresEventsViewWhenNoAffiliateCatalogIsRequested(): void
    {
        [, $token] = $this->createAdmin([], GeneralConstants::ROLES['SUPER_ADMIN']['name']);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/v1/events')
            ->assertForbidden();
    }

    #[Test]
    public function indexFunCatalogRequiresAffiliateFunsView(): void
    {
        [, $token] = $this->createAdmin(['events' => ['view']]);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/v1/events?affiliate_catalog=fun')
            ->assertForbidden();
    }

    #[Test]
    public function indexFunCatalogListsEventsWhenAffiliateFunsViewIsGranted(): void
    {
        [, $token] = $this->createAdmin([
            'events' => ['view'],
            'affiliate-funs' => ['view'],
        ]);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/v1/events?affiliate_catalog=fun')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta']);
    }

    #[Test]
    public function indexEventCatalogRequiresAffiliateEventsView(): void
    {
        [, $token] = $this->createAdmin(['events' => ['view']]);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/v1/events?affiliate_catalog=event')
            ->assertForbidden();
    }

    #[Test]
    public function affiliateSettingsRequiresMatchingAffiliatePermission(): void
    {
        [, $token] = $this->createAdmin(['events' => ['view']]);
        $event = $this->makeEvent();

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/v1/events/' . $event->uuid . '/affiliate-settings', [
                'affiliate_enabled' => true,
                'affiliate_commission_percent' => 5,
            ])
            ->assertForbidden();

        $this->assertFalse((bool) $event->fresh()->affiliate_enabled);
    }

    #[Test]
    public function affiliateSettingsRejectsNonAdminFromAnotherOrganization(): void
    {
        $ownOrg = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        [, $token] = $this->createAdmin(
            ['affiliate-events' => ['update']],
            GeneralConstants::ROLES['ORGANIZER']['name'],
            false,
            $ownOrg->uuid,
        );
        $event = $this->makeEvent(['organization_uuid' => $otherOrg->uuid]);

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/v1/events/' . $event->uuid . '/affiliate-settings', [
                'affiliate_enabled' => true,
                'affiliate_commission_percent' => 5,
            ])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'You cannot update this event.');

        $this->assertFalse((bool) $event->fresh()->affiliate_enabled);
    }

    #[Test]
    public function affiliateSettingsForFunEventUsesFunPermissionAndStoresValues(): void
    {
        $section = EventSection::create([
            'name' => EventSection::AMUSEMENT_SECTION,
            'title' => 'Amusements',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
        [, $token] = $this->createAdmin(['affiliate-funs' => ['update']]);
        $event = $this->makeEvent([
            'event_section_uuid' => $section->uuid,
            'event_type' => Event::EVENT_TYPES['DAILY'],
        ]);

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/v1/events/' . $event->uuid . '/affiliate-settings', [
                'affiliate_enabled' => true,
                'affiliate_commission_percent' => 12.5,
                'affiliate_ends_at' => '2026-12-31',
            ])
            ->assertOk()
            ->assertJsonPath('data.uuid', $event->uuid);

        $event->refresh();
        $this->assertTrue($event->affiliate_enabled);
        $this->assertSame('12.50', $event->affiliate_commission_percent);
        $this->assertSame('2026-12-31', $event->affiliate_ends_at->toDateString());
    }

    #[Test]
    public function disablingAffiliateSettingsClearsCommissionAndEndDate(): void
    {
        [, $token] = $this->createAdmin(['affiliate-events' => ['update']]);
        $event = $this->makeEvent([
            'affiliate_enabled' => true,
            'affiliate_commission_percent' => 10,
            'affiliate_ends_at' => '2026-12-31',
        ]);

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/v1/events/' . $event->uuid . '/affiliate-settings', [
                'affiliate_enabled' => false,
                'affiliate_commission_percent' => 15,
                'affiliate_ends_at' => '2027-01-31',
            ])
            ->assertOk();

        $event->refresh();
        $this->assertFalse($event->affiliate_enabled);
        $this->assertNull($event->affiliate_commission_percent);
        $this->assertNull($event->affiliate_ends_at);
    }

    #[Test]
    public function affiliateSettingsReturns401WithoutAnAuthenticatedAdmin(): void
    {
        $event = $this->makeEvent();
        $request = UpdateEventAffiliateRequest::create('/coverage', 'PATCH', ['affiliate_enabled' => true]);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->validateResolved();

        $response = app(EventController::class)->updateAffiliateSettings($request, $event->uuid);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(['success' => false, 'message' => 'Unauthorized'], $response->getData(true));
        $this->assertFalse((bool) $event->fresh()->affiliate_enabled);
    }
}
