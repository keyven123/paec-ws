<?php

namespace Tests\Feature\Coverage\GapCheckout;

use App\Http\Requests\Customer\TempTransactionRequest;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\TempTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TempTransactionHoldsCoverageTest extends TestCase
{
    use RefreshDatabase;
    use GapCheckoutFixtures;

    private const BASE = '/api/v1/customer/temp-transactions';

    private User $customer;
    private Event $event;
    private EventTicket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->customer = $this->createCustomer();
        $this->event = $this->createEvent();
        $this->ticket = $this->createEventTicket($this->event);
    }

    #[Test]
    public function creatingAHoldWithAPromoCodeAppliesTheDiscount(): void
    {
        $promo = $this->createPromoCode();

        $response = $this->actingAs($this->customer, 'api')->postJson(self::BASE, [
            'event_uuid' => $this->event->uuid,
            'promo_code_uuid' => $promo->uuid,
            'tickets' => [['event_ticket_uuid' => $this->ticket->uuid, 'quantity' => 2]],
        ]);

        $response->assertCreated()->assertJsonPath('promo_code_uuid', $promo->uuid);
        $this->assertEquals(100, $response->json('promo_code_discount'));
        $this->assertDatabaseHas('temp_transactions', [
            'uuid' => $response->json('uuid'),
            'user_uuid' => $this->customer->uuid,
            'promo_code_uuid' => $promo->uuid,
        ]);
    }

    #[Test]
    public function updatingAHoldReplacesOrdersAndAppliesThePromoCode(): void
    {
        $limited = $this->createEventTicket($this->event, ['is_unlimited' => false, 'max_ticket' => 5, 'sold_ticket' => 4]);
        $temp = $this->createTempTransaction($this->customer, $limited, [], ['quantity' => 2]);
        $promo = $this->createPromoCode();
        $visitDate = now()->addDays(2)->toDateString();

        // Only 1 seat is unsold, but the 2 already held by this order are released back to it.
        $response = $this->actingAs($this->customer, 'api')->putJson(self::BASE . '/' . $temp->uuid, [
            'event_uuid' => $this->event->uuid,
            'promo_code_uuid' => $promo->uuid,
            'affiliate_code' => 'UNKNOWN1',
            'tickets' => [['event_ticket_uuid' => $limited->uuid, 'quantity' => 3, 'valid_until' => $visitDate]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('uuid', $temp->uuid)
            ->assertJsonPath('promo_code_uuid', $promo->uuid);
        $this->assertEquals(150, $response->json('promo_code_discount'));
        $this->assertEquals(1350, $response->json('total_amount'));

        $orders = $temp->fresh()->tempTransactionOrders;
        $this->assertCount(1, $orders);
        $this->assertSame(3, (int) $orders->first()->quantity);
        $this->assertStringStartsWith($visitDate . ' 23:59:59', (string) $orders->first()->getRawOriginal('valid_until'));
    }

    #[Test]
    public function updatingAHoldWithoutAPromoCodeClearsThePreviousPromo(): void
    {
        $promo = $this->createPromoCode();
        $temp = $this->createTempTransaction($this->customer, $this->ticket, [
            'promo_code_uuid' => $promo->uuid,
            'promo_code_discount' => 50,
        ]);

        $this->actingAs($this->customer, 'api')->putJson(self::BASE . '/' . $temp->uuid, [
            'event_uuid' => $this->event->uuid,
            'tickets' => [['event_ticket_uuid' => $this->ticket->uuid, 'quantity' => 1]],
        ])->assertCreated()
            ->assertJsonPath('promo_code_uuid', null);

        $fresh = $temp->fresh();
        $this->assertNull($fresh->promo_code_uuid);
        $this->assertEquals(0, $fresh->promo_code_discount);
        $this->assertEquals(500, $fresh->total_amount);
    }

    #[Test]
    public function customersCannotReleaseAnotherCustomersHold(): void
    {
        $temp = $this->createTempTransaction($this->createCustomer(), $this->ticket);

        $this->actingAs($this->customer, 'api')
            ->deleteJson(self::BASE . '/' . $temp->uuid)
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'Unauthorized']);

        $this->assertDatabaseHas('temp_transactions', ['uuid' => $temp->uuid]);
    }

    #[Test]
    public function releasingASeatHoldReturns500WhenDeletionFails(): void
    {
        Log::spy();
        $seatEvent = $this->createEvent(['event_config' => Event::EVENT_CONFIGS['SEAT_SELECTION']]);
        $temp = $this->createTempTransaction($this->customer, $this->createEventTicket($seatEvent));
        $this->failingRepository('delete');

        $this->actingAs($this->customer, 'api')
            ->deleteJson(self::BASE . '/' . $temp->uuid)
            ->assertStatus(500)
            ->assertExactJson([
                'success' => false,
                'message' => 'Failed to release reservation. Please try again.',
            ]);

        $this->assertDatabaseHas('temp_transactions', ['uuid' => $temp->uuid]);
        Log::shouldHaveReceived('error')->with('Failed to delete temp transaction', \Mockery::on(
            fn (array $context) => $context['temp_transaction_uuid'] === $temp->uuid && $context['error'] === 'Database unavailable'
        ));
    }

    #[Test]
    public function creatingAHoldRejectsATicketRemovedAfterValidation(): void
    {
        $this->app->afterResolving(TempTransactionRequest::class, fn () => $this->ticket->delete());

        $this->actingAs($this->customer, 'api')->postJson(self::BASE, [
            'event_uuid' => $this->event->uuid,
            'tickets' => [['event_ticket_uuid' => $this->ticket->uuid, 'quantity' => 1]],
        ])->assertUnprocessable()
            ->assertJsonPath('errors', ['tickets.0.event_ticket_uuid' => ['Ticket not found.']]);

        $this->assertSame(0, TempTransaction::count());
    }

    #[Test]
    public function creatingAHoldRejectsSoldOutTickets(): void
    {
        $soldOut = $this->createEventTicket($this->event, [
            'name' => 'VIP',
            'is_unlimited' => false,
            'max_ticket' => 5,
            'sold_ticket' => 5,
        ]);

        $this->actingAs($this->customer, 'api')->postJson(self::BASE, [
            'event_uuid' => $this->event->uuid,
            'tickets' => [['event_ticket_uuid' => $soldOut->uuid, 'quantity' => 1]],
        ])->assertUnprocessable()
            ->assertJsonPath('errors', ['tickets.0.quantity' => ['VIP is no longer available. All tickets have been sold.']]);

        $this->assertSame(0, TempTransaction::count());
    }

    #[Test]
    public function creatingAHoldRejectsQuantitiesAboveTheRemainingStock(): void
    {
        $limited = $this->createEventTicket($this->event, [
            'name' => 'Balcony',
            'is_unlimited' => false,
            'max_ticket' => 5,
            'sold_ticket' => 3,
        ]);
        $lastOne = $this->createEventTicket($this->event, [
            'name' => 'Box',
            'is_unlimited' => false,
            'max_ticket' => 1,
            'sold_ticket' => 0,
        ]);

        $this->actingAs($this->customer, 'api')->postJson(self::BASE, [
            'event_uuid' => $this->event->uuid,
            'tickets' => [
                ['event_ticket_uuid' => $limited->uuid, 'quantity' => 4],
                ['event_ticket_uuid' => $lastOne->uuid, 'quantity' => 2],
            ],
        ])->assertUnprocessable()
            ->assertJsonPath('errors', [
                'tickets.0.quantity' => ['Only 2 tickets available for Balcony. You requested 4.'],
                'tickets.1.quantity' => ['Only 1 ticket available for Box. You requested 2.'],
            ]);
    }
}
