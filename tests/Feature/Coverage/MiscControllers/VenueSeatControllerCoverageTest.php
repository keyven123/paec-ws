<?php

namespace Tests\Feature\Coverage\MiscControllers;

use App\Constants\GeneralConstants;
use App\Exceptions\NoVenueSeatFoundException;
use App\Http\Repositories\VenueSeatRepository;
use App\Models\Event;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\TicketSeat;
use App\Models\Transaction;
use App\Models\Venue;
use App\Models\VenueSeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VenueSeatControllerCoverageTest extends TestCase
{
    use MiscFixtures;
    use RefreshDatabase;

    private Venue $venue;

    private VenueSeat $seat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->venue = $this->createVenue();
        $this->seat = $this->createVenueSeat($this->venue);
    }

    #[Test]
    public function showReturnsVenueSeat(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->getJson("/api/v1/venue-seats/{$this->seat->uuid}")
            ->assertOk()
            ->assertJsonPath('data.uuid', $this->seat->uuid)
            ->assertJsonPath('data.venue_uuid', $this->venue->uuid);
    }

    #[Test]
    public function updateChangesVenueSeat(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->putJson("/api/v1/venue-seats/{$this->seat->uuid}", ['seat_no' => 9, 'category' => 'vip', 'color' => 'red'])
            ->assertOk()
            ->assertJsonPath('data.uuid', $this->seat->uuid);

        $this->assertDatabaseHas('venue_seats', [
            'uuid' => $this->seat->uuid,
            'seat_no' => 9,
            'category' => 'vip',
            'color' => 'red',
        ]);
    }

    #[Test]
    public function updateReturns404WhenSeatDisappearsAfterValidation(): void
    {
        $this->mock(VenueSeatRepository::class, function ($mock) {
            $mock->shouldReceive('fetchOrThrow')->once()->andThrow(new NoVenueSeatFoundException());
            $mock->shouldNotReceive('update');
        });

        $this->withHeaders($this->adminHeaders())
            ->putJson("/api/v1/venue-seats/{$this->seat->uuid}", ['seat_no' => 3])
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Venue seat not found']);
    }

    #[Test]
    public function destroySoftDeletesUnusedSeat(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->deleteJson("/api/v1/venue-seats/{$this->seat->uuid}")
            ->assertNoContent();

        $this->assertSoftDeleted('venue_seats', ['uuid' => $this->seat->uuid]);
    }

    #[Test]
    public function destroyReturns404ForUnknownSeat(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->deleteJson('/api/v1/venue-seats/00000000-0000-0000-0000-000000000000')
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Venue seat not found']);
    }

    #[Test]
    public function destroyReturns403WhenSeatIsAssignedToTickets(): void
    {
        $ticket = $this->createTicket($this->createTransaction($this->createCustomer(), $this->createEvent()));
        TicketSeat::create([
            'ticket_uuid' => $ticket->uuid,
            'venue_uuid' => $this->venue->uuid,
            'venue_seat_uuid' => $this->seat->uuid,
            'col' => '1',
            'row' => 'A',
            'seat_no' => 1,
            'category' => 'gold',
            'color' => 'gold',
        ]);

        $this->withHeaders($this->adminHeaders())
            ->deleteJson("/api/v1/venue-seats/{$this->seat->uuid}")
            ->assertForbidden()
            ->assertJson(['success' => false, 'message' => 'Cannot delete venue seat with associated ticket seats.']);

        $this->assertDatabaseHas('venue_seats', ['uuid' => $this->seat->uuid, 'deleted_at' => null]);
    }

    public static function publicSeatEndpoints(): array
    {
        return [
            'v1' => ['seats'],
            'v2' => ['seats-v2'],
        ];
    }

    #[Test]
    #[DataProvider('publicSeatEndpoints')]
    public function publicSeatMapMarksOccupiedAndBlockedSeatsUnavailable(string $endpoint): void
    {
        $blocked = $this->createVenueSeat($this->venue, ['seat_no' => 2]);
        $free = $this->createVenueSeat($this->venue, ['seat_no' => 3]);
        $freedByCancellation = $this->createVenueSeat($this->venue, ['seat_no' => 4]);
        $this->createVenueSeat($this->venue, ['seat_no' => 5, 'status' => GeneralConstants::GENERAL_STATUSES['INACTIVE']]);
        $this->createVenueSeat($this->createVenue(), ['seat_no' => 6]);

        $event = $this->createEvent(['venue_uuid' => $this->venue->uuid, 'blocked_seats' => [$blocked->uuid]]);
        $schedule = $this->createSchedule($event);
        $scheduleTime = $this->createScheduleTime($schedule);
        $customer = $this->createCustomer();

        $paid = $this->createTransaction($customer, $event, [
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
        ]);
        $this->createTicket($paid, ['venue_seat_uuid' => $this->seat->uuid]);
        $this->createTicket($paid, [
            'venue_seat_uuid' => $freedByCancellation->uuid,
            'status' => GeneralConstants::TICKET_STATUSES['CANCELLED'],
        ]);

        $failed = $this->createTransaction($customer, $event, [
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'status' => Transaction::PAYMENT_STATUS['FAILED'],
        ]);
        $this->createTicket($failed, ['venue_seat_uuid' => $free->uuid]);

        $response = $this->getJson("/api/v1/public/events/{$event->uuid}/{$endpoint}?" . http_build_query([
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
        ]))->assertOk();

        $availability = collect($response->json())->pluck('is_available', 'uuid')->all();

        $this->assertSame([
            $this->seat->uuid => false,
            $blocked->uuid => false,
            $free->uuid => true,
            $freedByCancellation->uuid => true,
        ], $availability);
        foreach (['uuid', 'col', 'row', 'seat_no', 'category', 'color', 'status', 'order', 'is_available'] as $key) {
            $this->assertArrayHasKey($key, $response->json('0'));
        }
    }

    #[Test]
    #[DataProvider('publicSeatEndpoints')]
    public function publicSeatMapWithoutAScheduleCountsSeatsTakenOnAnyDate(string $endpoint): void
    {
        $free = $this->createVenueSeat($this->venue, ['seat_no' => 2]);
        $event = $this->createEvent(['venue_uuid' => $this->venue->uuid]);
        $paid = $this->createTransaction($this->createCustomer(), $event, [
            'schedule_uuid' => $this->createSchedule($event)->uuid,
        ]);
        $this->createTicket($paid, ['venue_seat_uuid' => $this->seat->uuid]);

        $availability = collect($this->getJson("/api/v1/public/events/{$event->uuid}/{$endpoint}")->assertOk()->json())
            ->pluck('is_available', 'uuid')
            ->all();

        $this->assertFalse($availability[$this->seat->uuid]);
        $this->assertTrue($availability[$free->uuid]);
    }
}
