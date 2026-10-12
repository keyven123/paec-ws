<?php

namespace Tests\Feature\Coverage\HttpEvents;

use App\Constants\GeneralConstants;
use App\Exceptions\NoEventTicketFoundException;
use App\Http\Controllers\EventTicketController;
use App\Http\Repositories\EventTicketRepository;
use App\Jobs\DeleteUserTicketCouponsJob;
use App\Jobs\UpdateUserTicketCouponsJob;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\EventTicketCoupon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;

class EventTicketControllerCoverageTest extends HttpEventsTestCase
{
    private string $token;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->token] = $this->createAdmin([
            'event-tickets' => ['view', 'create', 'update', 'delete'],
        ]);

        $this->event = Event::create([
            'event_name' => 'Daily Coverage Event',
            'event_description' => 'Daily',
            'contact_email' => 'daily@test.com',
            'event_type' => Event::EVENT_TYPES['DAILY'],
            'schedule_type' => Event::SCHEDULE_TYPES['DAILY'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
        ]);
    }

    private function makeTicket(array $overrides = []): EventTicket
    {
        return EventTicket::create(array_merge([
            'event_uuid' => $this->event->uuid,
            'code' => 'GA-' . uniqid(),
            'name' => 'General Admission',
            'price' => 100,
            'max_ticket' => 10,
            'is_unlimited' => false,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ], $overrides));
    }

    private function makeCoupon(EventTicket $ticket, string $name, bool $onceOnly = false): EventTicketCoupon
    {
        return EventTicketCoupon::create([
            'event_ticket_uuid' => $ticket->uuid,
            'name' => $name,
            'once_only' => $onceOnly,
        ]);
    }

    #[Test]
    public function storeGeneratesCodeFromNameWhenCodeIsOmitted(): void
    {
        $response = $this->withHeaders($this->bearer($this->token))
            ->postJson('/api/v1/event-tickets', [
                'event_uuid' => $this->event->uuid,
                'name' => 'Early Bird Pass',
                'price' => 250,
                'is_unlimited' => true,
            ])
            ->assertCreated();

        $code = $response->json('data.code');
        $this->assertMatchesRegularExpression('/^EARLYBIRDPASS-[A-Z0-9]{4}$/', $code);
        $this->assertDatabaseHas('event_tickets', [
            'event_uuid' => $this->event->uuid,
            'name' => 'Early Bird Pass',
            'code' => $code,
        ]);
    }

    #[Test]
    public function storeFallsBackToCacheVersionBumpWhenStoreHasNoTags(): void
    {
        $this->useCacheStoreWithoutTags();
        Cache::forever('public_events_cache_version', 4);

        $this->withHeaders($this->bearer($this->token))
            ->postJson('/api/v1/event-tickets', [
                'event_uuid' => $this->event->uuid,
                'code' => 'NOTAGS',
                'name' => 'No Tags',
                'price' => 10,
                'is_unlimited' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'NOTAGS');

        $this->assertSame(5, Cache::get('public_events_cache_version'));
    }

    #[Test]
    public function bulkStoreCreatesTicketsWithGeneratedCodesAndCoupons(): void
    {
        $response = $this->withHeaders($this->bearer($this->token))
            ->postJson('/api/v1/event-tickets/bulk', [
                'event_uuid' => $this->event->uuid,
                'tickets' => [
                    [
                        'name' => 'Kids Pass',
                        'price' => 100,
                        'is_unlimited' => true,
                        'with_coupon' => true,
                        'coupons' => [
                            ['name' => 'KIDSONCE', 'once_only' => true],
                            ['name' => 'KIDSMULTI'],
                        ],
                    ],
                    [
                        'code' => 'ADULT',
                        'name' => 'Adult Pass',
                        'price' => 200,
                        'is_unlimited' => false,
                        'max_ticket' => 20,
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'data');

        $byName = collect($response->json('data'))->keyBy('name');
        $this->assertSame('ADULT', $byName['Adult Pass']['code']);
        $this->assertCount(0, $byName['Adult Pass']['coupons']);
        $this->assertCount(2, $byName['Kids Pass']['coupons']);

        $kidsUuid = $byName['Kids Pass']['uuid'];
        $this->assertMatchesRegularExpression('/^KIDSPASS-[A-Z0-9]{4}$/', $byName['Kids Pass']['code']);
        $this->assertDatabaseHas('event_ticket_coupons', [
            'event_ticket_uuid' => $kidsUuid,
            'name' => 'KIDSONCE',
            'once_only' => 1,
        ]);
        $this->assertDatabaseHas('event_ticket_coupons', [
            'event_ticket_uuid' => $kidsUuid,
            'name' => 'KIDSMULTI',
            'once_only' => 0,
        ]);
        $this->assertDatabaseHas('event_tickets', [
            'event_uuid' => $this->event->uuid,
            'code' => 'ADULT',
            'max_ticket' => 20,
        ]);
    }

    #[Test]
    public function showReturnsTheEventTicket(): void
    {
        $ticket = $this->makeTicket(['name' => 'Show Me']);

        $this->withHeaders($this->bearer($this->token))
            ->getJson('/api/v1/event-tickets/' . $ticket->uuid)
            ->assertOk()
            ->assertJsonPath('data.uuid', $ticket->uuid)
            ->assertJsonPath('data.name', 'Show Me');
    }

    #[Test]
    public function updateWithoutCouponFlagOnlyUpdatesTicketFields(): void
    {
        Bus::fake();
        $ticket = $this->makeTicket();
        $coupon = $this->makeCoupon($ticket, 'KEEP');

        $this->withHeaders($this->bearer($this->token))
            ->putJson('/api/v1/event-tickets/' . $ticket->uuid, [
                'name' => 'Renamed',
                'price' => 150,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonCount(1, 'data.coupons');

        $this->assertDatabaseHas('event_tickets', ['uuid' => $ticket->uuid, 'name' => 'Renamed', 'price' => 150]);
        $this->assertNotSoftDeleted('event_ticket_coupons', ['uuid' => $coupon->uuid]);
        Bus::assertNothingDispatched();
    }

    #[Test]
    public function updateWithCouponsDisabledDeletesAllCouponsAndDispatchesCleanup(): void
    {
        Bus::fake();
        $ticket = $this->makeTicket();
        $first = $this->makeCoupon($ticket, 'ONE');
        $second = $this->makeCoupon($ticket, 'TWO');

        $this->withHeaders($this->bearer($this->token))
            ->putJson('/api/v1/event-tickets/' . $ticket->uuid, [
                'with_coupon' => false,
            ])
            ->assertOk()
            ->assertJsonCount(0, 'data.coupons');

        $this->assertSoftDeleted('event_ticket_coupons', ['uuid' => $first->uuid]);
        $this->assertSoftDeleted('event_ticket_coupons', ['uuid' => $second->uuid]);
        Bus::assertDispatched(DeleteUserTicketCouponsJob::class, function (DeleteUserTicketCouponsJob $job) use ($first, $second) {
            $ids = $job->deletedCouponIds;
            sort($ids);
            $expected = [$first->uuid, $second->uuid];
            sort($expected);

            return $ids === $expected;
        });
    }

    #[Test]
    public function updateWithCouponsSyncsUpdatesCreatesAndDeletes(): void
    {
        Bus::fake();
        $ticket = $this->makeTicket();
        $kept = $this->makeCoupon($ticket, 'OLDNAME');
        $removed = $this->makeCoupon($ticket, 'REMOVE');

        $this->withHeaders($this->bearer($this->token))
            ->putJson('/api/v1/event-tickets/' . $ticket->uuid, [
                'with_coupon' => true,
                'coupons' => [
                    ['uuid' => $kept->uuid, 'name' => 'NEWNAME', 'once_only' => true],
                    ['name' => 'BRANDNEW'],
                ],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'data.coupons');

        $this->assertDatabaseHas('event_ticket_coupons', [
            'uuid' => $kept->uuid,
            'name' => 'NEWNAME',
            'once_only' => 1,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('event_ticket_coupons', [
            'event_ticket_uuid' => $ticket->uuid,
            'name' => 'BRANDNEW',
            'once_only' => 0,
        ]);
        $this->assertSoftDeleted('event_ticket_coupons', ['uuid' => $removed->uuid]);
        Bus::assertDispatched(
            UpdateUserTicketCouponsJob::class,
            fn (UpdateUserTicketCouponsJob $job) => $job->eventTicketUuid === $ticket->uuid
        );
        Bus::assertDispatched(
            DeleteUserTicketCouponsJob::class,
            fn (DeleteUserTicketCouponsJob $job) => $job->deletedCouponIds === [$removed->uuid]
        );
    }

    #[Test]
    public function updateCouponsSkipsBlankAndScalarCouponEntries(): void
    {
        Bus::fake();
        $ticket = $this->makeTicket();

        $controller = app(EventTicketController::class);
        (fn () => $this->updateCoupons($ticket, ['  ', ['name' => ''], 'SCALAR']))->call($controller);

        $names = EventTicketCoupon::where('event_ticket_uuid', $ticket->uuid)->pluck('name')->all();
        $this->assertSame(['SCALAR'], $names);
        Bus::assertDispatched(UpdateUserTicketCouponsJob::class);
    }

    #[Test]
    public function updateReturns404WhenTicketCannotBeFetched(): void
    {
        $ticket = $this->makeTicket();
        $this->partialMock(EventTicketRepository::class, function (MockInterface $mock) {
            $mock->shouldReceive('fetchOrThrow')->andThrow(new NoEventTicketFoundException());
        });

        $this->withHeaders($this->bearer($this->token))
            ->putJson('/api/v1/event-tickets/' . $ticket->uuid, ['name' => 'Ghost'])
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Event ticket not found']);

        $this->assertDatabaseHas('event_tickets', ['uuid' => $ticket->uuid, 'name' => 'General Admission']);
    }

    #[Test]
    public function duplicateReturns404WhenTicketCannotBeFetched(): void
    {
        $ticket = $this->makeTicket();
        $this->partialMock(EventTicketRepository::class, function (MockInterface $mock) {
            $mock->shouldReceive('fetchOrThrow')->andThrow(new NoEventTicketFoundException());
        });

        $this->withHeaders($this->bearer($this->token))
            ->postJson('/api/v1/event-tickets/duplicate', ['uuid' => $ticket->uuid])
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Event ticket not found']);

        $this->assertSame(1, EventTicket::where('event_uuid', $this->event->uuid)->count());
    }
}
